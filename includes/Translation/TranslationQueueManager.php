<?php
/**
 * WooCommerce Action Scheduler Background Translation Queue Manager.
 *
 * Implements asynchronous translation job scheduling, execution, rate-limit
 * throttling, bounded exponential backoff retries, cancellation, and protection
 * of existing `approved` translations using WooCommerce's built-in Action Scheduler
 * (zero Composer runtime dependency).
 *
 * Security & Integrity Guarantees:
 * - Only accepts explicitly allowed public content types (`product_title`,
 *   `product_description`, `ui_string`, etc.); strictly rejects customer/order/PII fields.
 * - Never enqueues or executes translation jobs from storefront visitor requests.
 * - Deduplicates jobs by `(object_type, object_id, field_key, source_hash, target_locale, provider_id)`.
 * - Protects existing `approved` translations: a failed, retried, or duplicate
 *   background job NEVER overwrites or downgrades an existing `approved` translation
 *   for the same `(object_type, object_id, field_key, target_locale, source_hash)`.
 * - Supports job states: `pending`, `running`, `succeeded`, `failed`, `retry`, `cancelled`.
 * - Bounded exponential backoff (`base * 2^(attempt-1)`, max 3 retries) and per-provider
 *   rate-limit cooldown window.
 *
 * @package LocalePilot\Translation
 */

declare(strict_types=1);

namespace LocalePilot\Translation;

use LocalePilot\Admin\ProviderSettingsManager;
use LocalePilot\Admin\SubscriptionStateManager;

defined( 'ABSPATH' ) || exit;

/**
 * Manages background translation jobs via WooCommerce Action Scheduler and local state storage.
 */
final class TranslationQueueManager {

	/**
	 * Action Scheduler hook name for executing a single queued translation job.
	 */
	public const ACTION_HOOK = 'localepilot_process_translation_job';

	/**
	 * Action Scheduler group name.
	 */
	public const ACTION_GROUP = 'localepilot_translations';

	/**
	 * WordPress option key storing queued/recent job records (`autoload = false`).
	 */
	public const OPTION_JOBS_KEY = '_localepilot_translation_queue_jobs';

	/**
	 * WordPress option key storing per-provider rate-limit cooldown timestamps (`autoload = false`).
	 */
	public const OPTION_RATE_LIMIT_KEY = '_localepilot_provider_rate_limits';

	/**
	 * Job status constants.
	 */
	public const STATUS_PENDING   = 'pending';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_SUCCEEDED = 'succeeded';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_RETRY     = 'retry';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Default maximum retry attempts for transient/rate-limit failures.
	 */
	public const DEFAULT_MAX_ATTEMPTS = 3;

	/**
	 * Base exponential backoff delay in seconds.
	 */
	public const BASE_BACKOFF_SECONDS = 30;

	/**
	 * Default provider rate-limit cooldown in seconds on HTTP 429.
	 */
	public const RATE_LIMIT_COOLDOWN_SECONDS = 60;

	/**
	 * In-memory job store fallback for unit tests.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $memory_jobs = array();

	/**
	 * In-memory provider rate-limit timestamps (`provider_id => unix_timestamp`).
	 *
	 * @var array<string, int>
	 */
	private static array $memory_rate_limits = array();

	/**
	 * Translation repository instance.
	 *
	 * @var TranslationRepository
	 */
	private TranslationRepository $repository;

	/**
	 * Optional HTTP client override for testing.
	 *
	 * @var HttpClientInterface|null
	 */
	private ?HttpClientInterface $http_client;

	/**
	 * Optional admin context override for testing.
	 *
	 * @var bool|null
	 */
	private ?bool $is_admin_context;

	/**
	 * Construct the background translation queue manager.
	 *
	 * @param TranslationRepository|null $repository       Optional translation repository.
	 * @param HttpClientInterface|null   $http_client      Optional HTTP client override.
	 * @param bool|null                  $is_admin_context Optional admin context override.
	 */
	public function __construct(
		?TranslationRepository $repository = null,
		?HttpClientInterface $http_client = null,
		?bool $is_admin_context = null
	) {
		$this->repository       = $repository ?? new TranslationRepository();
		$this->http_client      = $http_client;
		$this->is_admin_context = $is_admin_context;
	}

	/**
	 * Reset in-memory queue and rate-limit state (intended for unit tests).
	 *
	 * @return void
	 */
	public static function reset_memory_state(): void {
		self::$memory_jobs        = array();
		self::$memory_rate_limits = array();
	}

	/**
	 * Register the Action Scheduler worker callback hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( self::ACTION_HOOK, array( $this, 'process_scheduled_job_hook' ), 10, 1 );
		}
	}

	/**
	 * Enqueue an admin-submitted public content translation job into Action Scheduler.
	 *
	 * Enforces:
	 * - Admin/worker execution context (never enqueues on storefront visitor requests).
	 * - Active/grace-period subscription entitlement check.
	 * - Allowed content type & PII prohibition (`TranslationRequest`).
	 * - Protection of existing `approved` translations (returns existing approved record without re-queuing).
	 * - Job deduplication (if an identical `pending` / `running` / `retry` job already exists, returns it without duplicating).
	 *
	 * @param array<string, mixed> $payload Job parameters (`provider_id`, `content_type`, `object_id`, `field_key`, `source_locale`, `target_locale`, `source_text`, `glossary`).
	 * @return array<string, mixed> Result associative array (`ok`, `code`, `job_id`, `status`, `deduplicated`, `message`).
	 */
	public function enqueue_job( array $payload ): array {
		if ( ! $this->is_allowed_execution_context() ) {
			return array(
				'ok'           => false,
				'code'         => TranslationResult::ERROR_STOREFRONT_BLOCKED,
				'job_id'       => '',
				'status'       => self::STATUS_FAILED,
				'deduplicated' => false,
				'message'      => 'Translation queue jobs cannot be enqueued from storefront visitor requests.',
			);
		}

		if ( ! SubscriptionStateManager::can_dispatch_new_translations() ) {
			return array(
				'ok'           => false,
				'code'         => 'subscription_expired',
				'job_id'       => '',
				'status'       => self::STATUS_FAILED,
				'deduplicated' => false,
				'message'      => 'Subscription is expired; new translation tasks are paused while existing local translations remain active.',
			);
		}

		$provider_id   = isset( $payload['provider_id'] ) && is_string( $payload['provider_id'] )
			? strtolower( trim( $payload['provider_id'] ) )
			: '';
		$content_type  = isset( $payload['content_type'] ) && is_string( $payload['content_type'] )
			? strtolower( trim( $payload['content_type'] ) )
			: 'product_title';
		$object_id     = isset( $payload['object_id'] ) ? max( 0, (int) $payload['object_id'] ) : 0;
		$field_key     = isset( $payload['field_key'] ) && '' !== trim( (string) $payload['field_key'] )
			? trim( (string) $payload['field_key'] )
			: $content_type;
		$source_locale = isset( $payload['source_locale'] ) && is_string( $payload['source_locale'] )
			? trim( $payload['source_locale'] )
			: 'en_US';
		$target_locale = isset( $payload['target_locale'] ) && is_string( $payload['target_locale'] )
			? trim( $payload['target_locale'] )
			: '';
		$source_text   = isset( $payload['source_text'] ) && is_string( $payload['source_text'] )
			? trim( $payload['source_text'] )
			: '';
		$glossary      = isset( $payload['glossary'] ) && is_array( $payload['glossary'] )
			? $payload['glossary']
			: array();
		$max_attempts  = isset( $payload['max_attempts'] )
			? max( 1, min( 5, (int) $payload['max_attempts'] ) )
			: self::DEFAULT_MAX_ATTEMPTS;

		$request = new TranslationRequest( $source_text, $source_locale, $target_locale, $content_type, $glossary );
		if ( $request->is_prohibited_pii_context() || ! TranslationRepository::is_allowed_content_type( $content_type ) ) {
			return array(
				'ok'           => false,
				'code'         => TranslationResult::ERROR_PRIVACY_BLOCKED,
				'job_id'       => '',
				'status'       => self::STATUS_FAILED,
				'deduplicated' => false,
				'message'      => 'Only whitelisted public product, taxonomy, and UI text fields may be queued for translation.',
			);
		}

		if ( '' === $source_text || '' === $target_locale ) {
			return array(
				'ok'           => false,
				'code'         => TranslationResult::ERROR_PROVIDER_FAILURE,
				'job_id'       => '',
				'status'       => self::STATUS_FAILED,
				'deduplicated' => false,
				'message'      => 'Source text and target locale are required.',
			);
		}

		$source_hash = $request->get_source_hash();

		// 1. Protect existing `approved` translation: never overwrite or re-queue if already approved for same source_hash.
		$existing_approved = $this->repository->find_approved_for_storefront(
			$content_type,
			$object_id,
			$field_key,
			$target_locale,
			$source_hash
		);
		if ( null !== $existing_approved && '' !== trim( $existing_approved ) ) {
			$dedup_id = self::compute_job_id( $content_type, $object_id, $field_key, $source_hash, $target_locale, $provider_id );
			return array(
				'ok'              => true,
				'code'            => 'already_approved',
				'job_id'          => $dedup_id,
				'status'          => self::STATUS_SUCCEEDED,
				'deduplicated'    => true,
				'translated_text' => $existing_approved,
				'message'         => 'Approved translation already exists for this source text and target locale; skipped re-queuing.',
			);
		}

		// 2. Check if an identical translation already exists in `TranslationRepository` (even `pending_review`).
		$existing_row = $this->repository->find_by_dedup(
			$source_hash,
			$target_locale,
			$provider_id,
			$content_type,
			$object_id,
			$field_key
		);
		if ( null !== $existing_row && '' !== trim( (string) ( $existing_row['translated_text'] ?? '' ) ) ) {
			$dedup_id = self::compute_job_id( $content_type, $object_id, $field_key, $source_hash, $target_locale, $provider_id );
			return array(
				'ok'              => true,
				'code'            => 'deduplicated_repository',
				'job_id'          => $dedup_id,
				'status'          => self::STATUS_SUCCEEDED,
				'deduplicated'    => true,
				'translated_text' => (string) $existing_row['translated_text'],
				'review_status'   => (string) ( $existing_row['review_status'] ?? TranslationResult::STATUS_PENDING_REVIEW ),
				'message'         => 'Matching translation already exists in local repository; skipped duplicate API job.',
			);
		}

		// 3. Check if an identical job is already active (`pending`, `running`, or `retry`) in the queue.
		$job_id   = self::compute_job_id( $content_type, $object_id, $field_key, $source_hash, $target_locale, $provider_id );
		$jobs     = $this->get_all_jobs();
		$existing = $jobs[ $job_id ] ?? null;

		if (
			is_array( $existing )
			&& in_array( $existing['status'], array( self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_RETRY ), true )
		) {
			return array(
				'ok'           => true,
				'code'         => 'deduplicated_queue',
				'job_id'       => $job_id,
				'status'       => (string) $existing['status'],
				'deduplicated' => true,
				'message'      => 'Identical translation job is already queued or running.',
			);
		}

		$now        = time();
		$job_record = array(
			'job_id'          => $job_id,
			'provider_id'     => '' !== $provider_id ? $provider_id : 'gemini',
			'content_type'    => $content_type,
			'object_id'       => $object_id,
			'field_key'       => $field_key,
			'source_locale'   => $source_locale,
			'target_locale'   => $target_locale,
			'source_text'     => $source_text,
			'source_hash'     => $source_hash,
			'glossary'        => $glossary,
			'status'          => self::STATUS_PENDING,
			'attempts'        => 0,
			'max_attempts'    => $max_attempts,
			'next_run_at'     => $now,
			'as_action_id'    => 0,
			'last_error_code' => '',
			'last_error_msg'  => '',
			'translated_text' => '',
			'created_at'      => gmdate( 'Y-m-d H:i:s', $now ),
			'updated_at'      => gmdate( 'Y-m-d H:i:s', $now ),
		);

		$action_id                  = $this->schedule_with_action_scheduler( $job_id, 0 );
		$job_record['as_action_id'] = $action_id;

		$jobs[ $job_id ] = $job_record;
		$this->save_all_jobs( $jobs );

		return array(
			'ok'           => true,
			'code'         => 'enqueued',
			'job_id'       => $job_id,
			'status'       => self::STATUS_PENDING,
			'deduplicated' => false,
			'as_action_id' => $action_id,
			'message'      => 'Translation job enqueued in Action Scheduler.',
		);
	}

	/**
	 * Cancel a `pending` or `retry` job in the queue and unschedule its Action Scheduler action.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True when cancelled.
	 */
	public function cancel_job( string $job_id ): bool {
		$clean_id = trim( $job_id );
		if ( '' === $clean_id ) {
			return false;
		}

		$jobs = $this->get_all_jobs();
		if ( ! isset( $jobs[ $clean_id ] ) ) {
			return false;
		}

		$job = $jobs[ $clean_id ];
		if ( ! in_array( $job['status'], array( self::STATUS_PENDING, self::STATUS_RETRY ), true ) ) {
			return false;
		}

		$this->unschedule_in_action_scheduler( $clean_id );

		$job['status']     = self::STATUS_CANCELLED;
		$job['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$jobs[ $clean_id ] = $job;
		$this->save_all_jobs( $jobs );

		return true;
	}

	/**
	 * Action Scheduler hook callback (`localepilot_process_translation_job`).
	 *
	 * @param mixed $job_id Job ID passed by Action Scheduler.
	 * @return void
	 */
	public function process_scheduled_job_hook( $job_id ): void {
		if ( is_string( $job_id ) && '' !== trim( $job_id ) ) {
			$this->process_job( trim( $job_id ) );
		}
	}

	/**
	 * Execute a single queued job by `$job_id`.
	 *
	 * Handles:
	 * - Cancellation and already-succeeded short-circuiting.
	 * - Protection of existing `approved` translations (never overwrites an approved record).
	 * - Provider rate-limit cooldown deferral (`retry`).
	 * - Provider execution via `ProviderRegistry`.
	 * - Bounded exponential backoff retry (`retry`) on transient/rate-limit errors (`429`, `5xx`, `timeout`).
	 * - Terminal `failed` state on non-retryable errors (`401`/`403` auth failure, `missing_credentials`, or max attempts reached).
	 *
	 * @param string                    $job_id            Job identifier.
	 * @param array<string, mixed>|null $provider_settings Optional provider settings override.
	 * @param int|null                  $now_timestamp     Optional current Unix timestamp for testing.
	 * @return array<string, mixed> Updated job record (or error summary if not found).
	 */
	public function process_job(
		string $job_id,
		?array $provider_settings = null,
		?int $now_timestamp = null
	): array {
		$clean_id = trim( $job_id );
		$jobs     = $this->get_all_jobs();

		if ( '' === $clean_id || ! isset( $jobs[ $clean_id ] ) ) {
			return array(
				'job_id' => $clean_id,
				'status' => self::STATUS_FAILED,
				'error'  => 'job_not_found',
			);
		}

		$job = $jobs[ $clean_id ];
		$now = $now_timestamp ?? time();

		// Do not process cancelled or already succeeded jobs.
		if ( in_array( $job['status'], array( self::STATUS_CANCELLED, self::STATUS_SUCCEEDED ), true ) ) {
			return $job;
		}

		if ( ! $this->is_allowed_execution_context() ) {
			return $job;
		}

		// Protect existing `approved` translation before making any API call.
		$existing_approved = $this->repository->find_approved_for_storefront(
			(string) $job['content_type'],
			(int) $job['object_id'],
			(string) $job['field_key'],
			(string) $job['target_locale'],
			(string) $job['source_hash']
		);
		if ( null !== $existing_approved && '' !== trim( $existing_approved ) ) {
			$job['status']          = self::STATUS_SUCCEEDED;
			$job['translated_text'] = $existing_approved;
			$job['updated_at']      = gmdate( 'Y-m-d H:i:s', $now );
			$jobs[ $clean_id ]      = $job;
			$this->save_all_jobs( $jobs );
			return $job;
		}

		// Check subscription entitlement state before dispatching outbound API request.
		if ( ! SubscriptionStateManager::can_dispatch_new_translations() ) {
			$job['status']          = self::STATUS_FAILED;
			$job['last_error_code'] = 'subscription_expired';
			$job['last_error_msg']  = 'Subscription expired; background translation paused.';
			$job['updated_at']      = gmdate( 'Y-m-d H:i:s', $now );
			$jobs[ $clean_id ]      = $job;
			$this->save_all_jobs( $jobs );
			return $job;
		}

		$provider_id = (string) $job['provider_id'];

		// Check per-provider rate-limit cooldown window.
		$cooldown_until = $this->get_provider_rate_limit_until( $provider_id );
		if ( $cooldown_until > $now ) {
			$delay                  = max( 1, $cooldown_until - $now );
			$job['status']          = self::STATUS_RETRY;
			$job['next_run_at']     = $cooldown_until;
			$job['last_error_code'] = TranslationResult::ERROR_RATE_LIMITED;
			$job['last_error_msg']  = 'Provider is in rate-limit cooldown window; rescheduled.';
			$job['as_action_id']    = $this->schedule_with_action_scheduler( $clean_id, $delay );
			$job['updated_at']      = gmdate( 'Y-m-d H:i:s', $now );
			$jobs[ $clean_id ]      = $job;
			$this->save_all_jobs( $jobs );
			return $job;
		}

		// Transition job to `running`.
		$job['status']     = self::STATUS_RUNNING;
		$job['attempts']   = (int) $job['attempts'] + 1;
		$job['updated_at'] = gmdate( 'Y-m-d H:i:s', $now );
		$jobs[ $clean_id ] = $job;
		$this->save_all_jobs( $jobs );

		$raw_settings = $provider_settings ?? ProviderSettingsManager::get_raw_settings();
		$registry     = ProviderRegistry::create_default(
			$raw_settings,
			$this->http_client,
			true,
			$this->repository
		);

		$provider = $registry->get( $provider_id );
		if ( null === $provider ) {
			$job['status']          = self::STATUS_FAILED;
			$job['last_error_code'] = 'unknown_provider';
			$job['last_error_msg']  = 'Unknown translation provider: ' . $provider_id;
			$job['updated_at']      = gmdate( 'Y-m-d H:i:s', $now );
			$jobs[ $clean_id ]      = $job;
			$this->save_all_jobs( $jobs );
			return $job;
		}

		$request = new TranslationRequest(
			(string) $job['source_text'],
			(string) $job['source_locale'],
			(string) $job['target_locale'],
			(string) $job['content_type'],
			is_array( $job['glossary'] ) ? $job['glossary'] : array()
		);

		$result = $provider->translate( $request );

		if ( $result->is_success() ) {
			// Double-check `approved` protection before saving to repository.
			$still_approved = $this->repository->find_approved_for_storefront(
				(string) $job['content_type'],
				(int) $job['object_id'],
				(string) $job['field_key'],
				(string) $job['target_locale'],
				(string) $job['source_hash']
			);

			if ( null === $still_approved ) {
				$this->repository->save(
					array(
						'object_type'     => (string) $job['content_type'],
						'object_id'       => (int) $job['object_id'],
						'field_key'       => (string) $job['field_key'],
						'source_locale'   => (string) $job['source_locale'],
						'target_locale'   => (string) $job['target_locale'],
						'source_hash'     => (string) $job['source_hash'],
						'provider_id'     => $provider_id,
						'translated_text' => $result->get_translated_text(),
						'review_status'   => $result->get_review_status(),
					)
				);
			}

			$job['status']          = self::STATUS_SUCCEEDED;
			$job['translated_text'] = $still_approved ?? $result->get_translated_text();
			$job['last_error_code'] = '';
			$job['last_error_msg']  = '';
			$job['updated_at']      = gmdate( 'Y-m-d H:i:s', $now );
			$jobs[ $clean_id ]      = $job;
			$this->save_all_jobs( $jobs );

			return $job;
		}

		// Handle failure: never overwrite or touch any existing `approved` translation in repository!
		$err_code = $result->get_error_code();
		$err_msg  = $result->get_error_message();

		if ( TranslationResult::ERROR_RATE_LIMITED === $err_code ) {
			$this->set_provider_rate_limit_until( $provider_id, $now + self::RATE_LIMIT_COOLDOWN_SECONDS );
		}

		$attempts     = (int) $job['attempts'];
		$max_attempts = (int) $job['max_attempts'];

		if ( ( $result->is_retryable() || self::is_retryable_error( $err_code ) ) && $attempts < $max_attempts ) {
			$backoff                = self::calculate_backoff_seconds( $attempts );
			$job['status']          = self::STATUS_RETRY;
			$job['next_run_at']     = $now + $backoff;
			$job['last_error_code'] = $err_code;
			$job['last_error_msg']  = $err_msg;
			$job['as_action_id']    = $this->schedule_with_action_scheduler( $clean_id, $backoff );
			$job['updated_at']      = gmdate( 'Y-m-d H:i:s', $now );
		} else {
			$job['status']          = self::STATUS_FAILED;
			$job['last_error_code'] = $err_code;
			$job['last_error_msg']  = $err_msg;
			$job['updated_at']      = gmdate( 'Y-m-d H:i:s', $now );
		}

		$jobs[ $clean_id ] = $job;
		$this->save_all_jobs( $jobs );

		return $job;
	}

	/**
	 * Calculate bounded exponential backoff delay in seconds (`base * 2^(attempt - 1)`).
	 *
	 * @param int $attempt Current 1-based attempt number.
	 * @return int Delay in seconds (bounded between 30s and 900s).
	 */
	public static function calculate_backoff_seconds( int $attempt ): int {
		$exp   = max( 0, min( 5, $attempt - 1 ) );
		$delay = self::BASE_BACKOFF_SECONDS * ( 1 << $exp );
		return min( 900, max( self::BASE_BACKOFF_SECONDS, $delay ) );
	}

	/**
	 * Determine whether a `TranslationResult` error code is transient/retryable.
	 *
	 * @param string $error_code Standardized error code.
	 * @return bool
	 */
	public static function is_retryable_error( string $error_code ): bool {
		return in_array(
			$error_code,
			array(
				TranslationResult::ERROR_RATE_LIMITED,
				TranslationResult::ERROR_PROVIDER_FAILURE,
				TranslationResult::ERROR_TIMEOUT,
			),
			true
		);
	}

	/**
	 * Compute deterministic deduplication job ID.
	 *
	 * @param string $content_type  Content type.
	 * @param int    $object_id     Object ID.
	 * @param string $field_key     Field key.
	 * @param string $source_hash   SHA-256 source text hash.
	 * @param string $target_locale Target locale.
	 * @param string $provider_id   Provider ID.
	 * @return string
	 */
	public static function compute_job_id(
		string $content_type,
		int $object_id,
		string $field_key,
		string $source_hash,
		string $target_locale,
		string $provider_id
	): string {
		$raw = implode(
			'|',
			array(
				strtolower( trim( $content_type ) ),
				(string) max( 0, $object_id ),
				trim( $field_key ),
				strtolower( trim( $source_hash ) ),
				trim( $target_locale ),
				strtolower( trim( $provider_id ) ),
			)
		);

		return 'lp_job_' . substr( hash( 'sha256', $raw ), 0, 24 );
	}

	/**
	 * Retrieve a single job record by ID.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>|null
	 */
	public function get_job( string $job_id ): ?array {
		$jobs = $this->get_all_jobs();
		return $jobs[ trim( $job_id ) ] ?? null;
	}

	/**
	 * Retrieve all persisted queue jobs (most recent first).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all_jobs(): array {
		if ( function_exists( 'get_option' ) ) {
			$opt = get_option( self::OPTION_JOBS_KEY, null );
			if ( is_array( $opt ) ) {
				self::$memory_jobs = $opt;
				return $opt;
			}
		}

		return self::$memory_jobs;
	}

	/**
	 * Set a provider's rate-limit cooldown expiration Unix timestamp.
	 *
	 * @param string $provider_id Provider ID.
	 * @param int    $until_ts    Unix timestamp until which requests are throttled.
	 * @return void
	 */
	public function set_provider_rate_limit_until( string $provider_id, int $until_ts ): void {
		$key = strtolower( trim( $provider_id ) );
		if ( '' === $key ) {
			return;
		}

		$limits         = $this->get_all_rate_limits();
		$limits[ $key ] = $until_ts;

		self::$memory_rate_limits = $limits;
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_RATE_LIMIT_KEY, $limits, false );
		}
	}

	/**
	 * Get a provider's rate-limit cooldown expiration Unix timestamp (`0` if none).
	 *
	 * @param string $provider_id Provider ID.
	 * @return int
	 */
	public function get_provider_rate_limit_until( string $provider_id ): int {
		$key    = strtolower( trim( $provider_id ) );
		$limits = $this->get_all_rate_limits();
		return isset( $limits[ $key ] ) ? (int) $limits[ $key ] : 0;
	}

	/**
	 * Check whether the current execution context is an allowed admin or background
	 * Action Scheduler worker context (and NEVER a storefront visitor request).
	 *
	 * @return bool
	 */
	private function is_allowed_execution_context(): bool {
		if ( null !== $this->is_admin_context ) {
			return $this->is_admin_context;
		}

		// Storefront `wc-ajax` requests must never enqueue or run translation jobs.
		if ( isset( $_GET['wc-ajax'] ) && '' !== (string) $_GET['wc-ajax'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Context guard only.
			return false;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			return true;
		}

		if ( function_exists( 'did_action' ) && did_action( 'action_scheduler_run_queue' ) > 0 ) {
			return true;
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return true;
		}

		return false;
	}

	/**
	 * Schedule a job in WooCommerce Action Scheduler (`as_enqueue_async_action` or `as_schedule_single_action`).
	 *
	 * @param string $job_id        Job identifier.
	 * @param int    $delay_seconds Delay in seconds (`0` for immediate async action).
	 * @return int Action Scheduler action ID (`0` when running in standalone unit test mode).
	 */
	private function schedule_with_action_scheduler( string $job_id, int $delay_seconds = 0 ): int {
		$args = array( 'job_id' => $job_id );

		if ( $delay_seconds <= 0 && function_exists( 'as_enqueue_async_action' ) ) {
			return (int) as_enqueue_async_action( self::ACTION_HOOK, $args, self::ACTION_GROUP );
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			return (int) as_schedule_single_action( time() + max( 1, $delay_seconds ), self::ACTION_HOOK, $args, self::ACTION_GROUP );
		}

		return 0;
	}

	/**
	 * Unschedule any pending Action Scheduler actions for `$job_id`.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private function unschedule_in_action_scheduler( string $job_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_HOOK, array( 'job_id' => $job_id ), self::ACTION_GROUP );
		}
	}

	/**
	 * Save all job records to WordPress options and memory store.
	 *
	 * @param array<string, array<string, mixed>> $jobs Job map.
	 * @return void
	 */
	private function save_all_jobs( array $jobs ): void {
		self::$memory_jobs = $jobs;
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_JOBS_KEY, $jobs, false );
		}
	}

	/**
	 * Retrieve all provider rate-limit timestamps.
	 *
	 * @return array<string, int>
	 */
	private function get_all_rate_limits(): array {
		if ( function_exists( 'get_option' ) ) {
			$opt = get_option( self::OPTION_RATE_LIMIT_KEY, null );
			if ( is_array( $opt ) ) {
				self::$memory_rate_limits = $opt;
				return $opt;
			}
		}

		return self::$memory_rate_limits;
	}
}
