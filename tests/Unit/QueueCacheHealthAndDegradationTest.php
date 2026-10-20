<?php
/**
 * Unit tests for Action Scheduler Background Translation Queue, Bounded Exponential
 * Backoff & Rate-Limiting, Protection of Approved Translations, Subscription Expired
 * Degradation, and Same-URL Cache Health Check.
 *
 * @package LocalePilot\Tests\Unit
 */

declare(strict_types=1);

namespace LocalePilot\Tests\Unit;

use LocalePilot\Admin\AdminLocaleManager;
use LocalePilot\Admin\AdminPage;
use LocalePilot\Admin\ProviderSettingsManager;
use LocalePilot\Admin\SubscriptionStateManager;
use LocalePilot\Storefront\CacheHealthChecker;
use LocalePilot\Storefront\StorefrontLocaleResolver;
use LocalePilot\Storefront\StorefrontTranslator;
use LocalePilot\Translation\HttpClientInterface;
use LocalePilot\Translation\TranslationQueueManager;
use LocalePilot\Translation\TranslationRepository;
use LocalePilot\Translation\TranslationResult;
use PHPUnit\Framework\TestCase;

/**
 * Verifies TranslationQueueManager, CacheHealthChecker, and SubscriptionStateManager.
 */
final class QueueCacheHealthAndDegradationTest extends TestCase {

	/**
	 * Reset in-memory state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		AdminLocaleManager::reset_memory_state();
		ProviderSettingsManager::reset_memory_state();
		SubscriptionStateManager::reset_memory_state();
		TranslationQueueManager::reset_memory_state();
		TranslationRepository::reset_memory_store();
		StorefrontLocaleResolver::reset_memory_state();
		CacheHealthChecker::reset_memory_state();
		unset( $_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] );
	}

	/**
	 * Clean up superglobals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] );
		parent::tearDown();
	}

	/**
	 * Verify AdminPage capability/nonce checks, queue deduplication, job execution,
	 * cancellation, and strict prohibition of storefront or PII jobs.
	 *
	 * @return void
	 */
	public function test_queue_enqueue_dedup_execution_cancellation_and_security_guards(): void {
		ProviderSettingsManager::update_settings(
			array(
				'active_provider' => 'deepl',
				'deepl_api_key'   => 'deepl_queue_unit_secret_9988:fx',
			)
		);

		$http_calls = 0;
		$mock_http  = new class( $http_calls ) implements HttpClientInterface {
			/**
			 * Call counter reference.
			 *
			 * @var int
			 */
			private int $calls;

			/**
			 * Construct mock HTTP client.
			 *
			 * @param int $calls Call counter reference.
			 */
			public function __construct( int &$calls ) {
				$this->calls = &$calls;
			}

			/**
			 * Perform mocked HTTP request.
			 *
			 * @param string               $method HTTP method.
			 * @param string               $url    Target URL.
			 * @param array<string, mixed> $args   Request options.
			 * @return array<string, mixed>
			 */
			public function request( string $method, string $url, array $args = array() ): array {
				unset( $method, $url, $args );
				++$this->calls;
				return array(
					'status'     => 200,
					'body'       => '{"translations":[{"text":"Sac à dos étanche"}]}',
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		};

		$repo       = new TranslationRepository();
		$admin_q    = new TranslationQueueManager( $repo, $mock_http, true );
		$visitor_q  = new TranslationQueueManager( $repo, $mock_http, false );
		$admin_page = new AdminPage();

		$valid_post = array(
			'localepilot_action'                => AdminPage::FORM_ACTION_ENQUEUE_TRANSLATION,
			'provider_id'                       => 'deepl',
			'content_type'                      => 'product_title',
			'object_id'                         => 610,
			'field_key'                         => 'post_title',
			'source_locale'                     => 'en_US',
			'target_locale'                     => 'fr_FR',
			'source_text'                       => 'Waterproof Backpack',
			ProviderSettingsManager::NONCE_NAME => 'valid_nonce',
		);

		// 1. Missing capability or invalid nonce -> rejected (`null`).
		$this->assertNull( $admin_page->handle_enqueue_translation_submission( $valid_post, false, true, $admin_q ) );
		$this->assertNull( $admin_page->handle_enqueue_translation_submission( $valid_post, true, false, $admin_q ) );

		// 2. Storefront visitor request -> strictly blocked (`storefront_execution_blocked`).
		$sf_attempt = $visitor_q->enqueue_job( $valid_post );
		$this->assertFalse( $sf_attempt['ok'] );
		$this->assertSame( TranslationResult::ERROR_STOREFRONT_BLOCKED, $sf_attempt['code'] );

		// 3. Customer / order / PII field -> strictly blocked (`privacy_prohibited_field`).
		$pii_post                 = $valid_post;
		$pii_post['content_type'] = 'customer_billing_address';
		$pii_attempt              = $admin_page->handle_enqueue_translation_submission( $pii_post, true, true, $admin_q );
		$this->assertNotNull( $pii_attempt );
		$this->assertFalse( $pii_attempt['ok'] );
		$this->assertSame( TranslationResult::ERROR_PRIVACY_BLOCKED, $pii_attempt['code'] );
		$this->assertSame( 0, $http_calls );

		// 4. Valid admin enqueue -> creates `pending` job without calling external API yet.
		$enq1 = $admin_page->handle_enqueue_translation_submission( $valid_post, true, true, $admin_q );
		$this->assertNotNull( $enq1 );
		$this->assertTrue( $enq1['ok'] );
		$this->assertFalse( $enq1['deduplicated'] );
		$this->assertSame( TranslationQueueManager::STATUS_PENDING, $enq1['status'] );
		$this->assertSame( 0, $http_calls, 'Enqueueing must be asynchronous and make 0 immediate HTTP calls.' );

		$job_id = (string) $enq1['job_id'];
		$this->assertNotEmpty( $job_id );

		// 5. Enqueueing the exact same job again while `pending` -> deduplicated (`deduplicated_queue`).
		$enq2 = $admin_page->handle_enqueue_translation_submission( $valid_post, true, true, $admin_q );
		$this->assertNotNull( $enq2 );
		$this->assertTrue( $enq2['ok'] );
		$this->assertTrue( $enq2['deduplicated'] );
		$this->assertSame( 'deduplicated_queue', $enq2['code'] );
		$this->assertSame( $job_id, $enq2['job_id'] );

		// 6. Execute the queued job -> transitions `pending -> running -> succeeded` and saves `pending_review` in DB.
		$processed = $admin_q->process_job( $job_id );
		$this->assertSame( TranslationQueueManager::STATUS_SUCCEEDED, $processed['status'] );
		$this->assertSame( 'Sac à dos étanche', $processed['translated_text'] );
		$this->assertSame( 1, $http_calls );

		// 7. Enqueueing a new job and cancelling it before execution -> status becomes `cancelled` and `process_job` is a no-op.
		$cancel_post                  = $valid_post;
		$cancel_post['target_locale'] = 'de_DE';
		$enq_de                       = $admin_page->handle_enqueue_translation_submission( $cancel_post, true, true, $admin_q );
		$de_job_id                    = (string) $enq_de['job_id'];

		// Cancel without capability fails.
		$this->assertFalse(
			$admin_page->handle_cancel_job_submission(
				array(
					'localepilot_action'                => AdminPage::FORM_ACTION_CANCEL_JOB,
					'job_id'                            => $de_job_id,
					ProviderSettingsManager::NONCE_NAME => 'valid_nonce',
				),
				false,
				true,
				$admin_q
			)
		);

		// Cancel with valid capability + nonce succeeds.
		$this->assertTrue(
			$admin_page->handle_cancel_job_submission(
				array(
					'localepilot_action'                => AdminPage::FORM_ACTION_CANCEL_JOB,
					'job_id'                            => $de_job_id,
					ProviderSettingsManager::NONCE_NAME => 'valid_nonce',
				),
				true,
				true,
				$admin_q
			)
		);

		$after_cancel = $admin_q->process_job( $de_job_id );
		$this->assertSame( TranslationQueueManager::STATUS_CANCELLED, $after_cancel['status'] );
		$this->assertSame( 1, $http_calls, 'Cancelled job must not invoke provider HTTP API.' );
	}

	/**
	 * Verify bounded exponential backoff, rate-limit throttling (HTTP 429), max retry exhaustion,
	 * and strict protection of existing `approved` translations against failure or overwrite.
	 *
	 * @return void
	 */
	public function test_exponential_backoff_rate_limit_and_approved_translation_protection(): void {
		ProviderSettingsManager::update_settings(
			array(
				'active_provider' => 'deepl',
				'deepl_api_key'   => 'deepl_retry_unit_secret_1122:fx',
			)
		);

		$simulated_status = 429;
		$http_calls       = 0;
		$mock_http        = new class( $simulated_status, $http_calls ) implements HttpClientInterface {
			/**
			 * Simulated HTTP status reference.
			 *
			 * @var int
			 */
			private int $status_ref;

			/**
			 * Call counter reference.
			 *
			 * @var int
			 */
			private int $calls;

			/**
			 * Construct mock HTTP client.
			 *
			 * @param int $status_ref Status code reference.
			 * @param int $calls      Call counter reference.
			 */
			public function __construct( int &$status_ref, int &$calls ) {
				$this->status_ref = &$status_ref;
				$this->calls      = &$calls;
			}

			/**
			 * Perform mocked HTTP request.
			 *
			 * @param string               $method HTTP method.
			 * @param string               $url    Target URL.
			 * @param array<string, mixed> $args   Request options.
			 * @return array<string, mixed>
			 */
			public function request( string $method, string $url, array $args = array() ): array {
				unset( $method, $url, $args );
				++$this->calls;
				if ( 200 === $this->status_ref ) {
					return array(
						'status'     => 200,
						'body'       => '{"translations":[{"text":"Tente quatre saisons"}]}',
						'headers'    => array(),
						'error'      => '',
						'is_timeout' => false,
					);
				}

				return array(
					'status'     => $this->status_ref,
					'body'       => '{"message":"Rate limit or server error"}',
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		};

		$repo  = new TranslationRepository();
		$queue = new TranslationQueueManager( $repo, $mock_http, true );

		// Verify bounded exponential backoff schedule: 30s -> 60s -> 120s.
		$this->assertSame( 30, TranslationQueueManager::calculate_backoff_seconds( 1 ) );
		$this->assertSame( 60, TranslationQueueManager::calculate_backoff_seconds( 2 ) );
		$this->assertSame( 120, TranslationQueueManager::calculate_backoff_seconds( 3 ) );

		$enq    = $queue->enqueue_job(
			array(
				'provider_id'   => 'deepl',
				'content_type'  => 'product_title',
				'object_id'     => 715,
				'field_key'     => 'post_title',
				'source_locale' => 'en_US',
				'target_locale' => 'fr_FR',
				'source_text'   => 'Four Season Tent',
				'max_attempts'  => 3,
			)
		);
		$job_id = (string) $enq['job_id'];
		$base_t = 1700000000;

		// Attempt 1: HTTP 429 -> status `retry`, sets provider rate-limit cooldown (+60s) and backoff (+30s).
		$run1 = $queue->process_job( $job_id, null, $base_t );
		$this->assertSame( TranslationQueueManager::STATUS_RETRY, $run1['status'] );
		$this->assertSame( 1, $run1['attempts'] );
		$this->assertSame( TranslationResult::ERROR_RATE_LIMITED, $run1['last_error_code'] );
		$this->assertSame( $base_t + 60, $queue->get_provider_rate_limit_until( 'deepl' ) );
		$this->assertSame( 1, $http_calls );

		// Running while still inside provider rate-limit cooldown (`$base_t + 10`) -> defers without calling HTTP!
		$run_throttled = $queue->process_job( $job_id, null, $base_t + 10 );
		$this->assertSame( TranslationQueueManager::STATUS_RETRY, $run_throttled['status'] );
		$this->assertSame( 1, $run_throttled['attempts'], 'Throttled deferral must not consume an attempt or call HTTP.' );
		$this->assertSame( 1, $http_calls );

		// Attempt 2 after cooldown expires (`$base_t + 65`) with HTTP 503 -> status `retry`, backoff +60s.
		$simulated_status = 503;
		$run2             = $queue->process_job( $job_id, null, $base_t + 65 );
		$this->assertSame( TranslationQueueManager::STATUS_RETRY, $run2['status'] );
		$this->assertSame( 2, $run2['attempts'] );
		$this->assertSame( TranslationResult::ERROR_PROVIDER_FAILURE, $run2['last_error_code'] );
		$this->assertSame( $base_t + 65 + 60, $run2['next_run_at'] );
		$this->assertSame( 2, $http_calls );

		// Attempt 3 (`$base_t + 200`) still HTTP 503 -> max_attempts (3) reached -> terminal `failed` status.
		$run3 = $queue->process_job( $job_id, null, $base_t + 200 );
		$this->assertSame( TranslationQueueManager::STATUS_FAILED, $run3['status'] );
		$this->assertSame( 3, $run3['attempts'] );
		$this->assertSame( 3, $http_calls );

		// Now test PROTECTION OF APPROVED TRANSLATIONS:
		// Save an `approved` translation for product 720 (`"Glacier Crampons"` -> `"Crampons de glacier certifiés"`).
		$approved_src = 'Glacier Crampons';
		$repo->save(
			array(
				'object_type'     => 'product_title',
				'object_id'       => 720,
				'field_key'       => 'post_title',
				'source_locale'   => 'en_US',
				'target_locale'   => 'fr_FR',
				'source_hash'     => hash( 'sha256', $approved_src ),
				'provider_id'     => 'deepl',
				'translated_text' => 'Crampons de glacier certifiés',
				'review_status'   => TranslationResult::STATUS_APPROVED,
			)
		);

		$calls_before_approved = $http_calls;
		$enq_approved          = $queue->enqueue_job(
			array(
				'provider_id'   => 'deepl',
				'content_type'  => 'product_title',
				'object_id'     => 720,
				'field_key'     => 'post_title',
				'source_locale' => 'en_US',
				'target_locale' => 'fr_FR',
				'source_text'   => $approved_src,
			)
		);

		$this->assertTrue( $enq_approved['ok'] );
		$this->assertTrue( $enq_approved['deduplicated'] );
		$this->assertSame( 'already_approved', $enq_approved['code'] );
		$this->assertSame( 'Crampons de glacier certifiés', $enq_approved['translated_text'] );
		$this->assertSame( $calls_before_approved, $http_calls );

		// Verify storefront still renders the exact `approved` translation even when subscription is expired.
		SubscriptionStateManager::update_state( array( 'status' => SubscriptionStateManager::STATUS_EXPIRED ) );
		$this->assertFalse( SubscriptionStateManager::can_dispatch_new_translations() );

		$expired_enq = $queue->enqueue_job(
			array(
				'provider_id'   => 'deepl',
				'content_type'  => 'product_title',
				'object_id'     => 799,
				'field_key'     => 'post_title',
				'source_locale' => 'en_US',
				'target_locale' => 'fr_FR',
				'source_text'   => 'New Ice Axe',
			)
		);
		$this->assertFalse( $expired_enq['ok'] );
		$this->assertSame( 'subscription_expired', $expired_enq['code'] );

		// Existing approved translation on storefront remains 100% available while subscription is expired!
		StorefrontLocaleResolver::update_settings(
			array(
				'default_locale'  => 'en_US',
				'enabled_locales' => array( 'en_US', 'fr_FR' ),
			)
		);
		$_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] = 'fr_FR';
		$translator                                       = new StorefrontTranslator( $repo, true );
		$this->assertSame(
			'Crampons de glacier certifiés',
			$translator->filter_product_title( $approved_src, 720 )
		);
	}

	/**
	 * Verify CacheHealthChecker evaluates headers/WP Rocket hook, marks undeployed caches
	 * as `uncertified`, and detects simulated cross-language cache poisoning.
	 *
	 * @return void
	 */
	public function test_cache_health_checker_and_uncertified_cache_matrix(): void {
		StorefrontLocaleResolver::update_settings(
			array(
				'default_locale'  => 'en_US',
				'enabled_locales' => array( 'en_US', 'fr_FR', 'de_DE' ),
			)
		);

		$admin_page = new AdminPage();

		// 1. Unauthorized Cache Health Check POST is rejected.
		$this->assertNull(
			$admin_page->handle_cache_health_check_submission(
				array(
					'localepilot_action'                => AdminPage::FORM_ACTION_RUN_CACHE_HEALTH_CHECK,
					ProviderSettingsManager::NONCE_NAME => 'valid_nonce',
				),
				false,
				true
			)
		);

		// 2. Authorized Cache Health Check runs and returns healthy report with honest `uncertified` matrix.
		$report = $admin_page->handle_cache_health_check_submission(
			array(
				'localepilot_action'                => AdminPage::FORM_ACTION_RUN_CACHE_HEALTH_CHECK,
				'canonical_url'                     => '/product/hiking-boots/?utm_source=meta',
				'locale_a'                          => 'en_US',
				'locale_b'                          => 'fr_FR',
				ProviderSettingsManager::NONCE_NAME => 'valid_nonce',
			),
			true,
			true
		);

		$this->assertNotNull( $report );
		$this->assertTrue( $report['overall_pass'] );
		$this->assertFalse( $report['cross_contamination_detected'] );
		$this->assertSame( 'healthy_safe_bypass', $report['status'] );
		$this->assertSame( 'lp_lang', $report['cookie_name'] );
		$this->assertTrue( $report['headers_valid'] );
		$this->assertTrue( $report['wp_rocket_cookie_registered'] );
		$this->assertCount( 4, $report['probes'] );

		// Every undeployed external cache integration MUST be marked `uncertified` and `shared_cache_allowed = false`.
		foreach ( array( 'wp_rocket', 'litespeed_cache', 'cloudflare_apo_edge', 'unrecognized_page_cache' ) as $cache_key ) {
			$this->assertArrayHasKey( $cache_key, $report['integrations'] );
			$this->assertSame( 'uncertified', $report['integrations'][ $cache_key ]['certification_status'] );
			$this->assertFalse( $report['integrations'][ $cache_key ]['shared_cache_allowed'] );
		}

		// 3. Simulate a poisoned page cache that serves `en-US` HTML to `fr_FR` Probe #2 -> must detect contamination and enforce fallback.
		$poisoned_report = CacheHealthChecker::run_health_check(
			'/product/hiking-boots/',
			'en_US',
			'fr_FR',
			static function ( string $url, string $cookie_locale, int $seq ): array {
				unset( $url, $cookie_locale, $seq );
				// Always returns cached English HTML regardless of `lp_lang` cookie!
				return array(
					'html_lang'   => 'en-US',
					'body_marker' => 'data-lp-current-locale="en_US"',
					'headers'     => array(),
				);
			}
		);

		$this->assertFalse( $poisoned_report['overall_pass'] );
		$this->assertTrue( $poisoned_report['cross_contamination_detected'] );
		$this->assertSame( 'contamination_fallback_enforced', $poisoned_report['status'] );
		$this->assertNotEmpty( $poisoned_report['contamination_events'] );
		$this->assertTrue( $poisoned_report['donotcachepage_active'] );

		// 4. Verify AdminPage HTML includes Cache Health Check table and `uncertified` badges.
		$admin_html = $admin_page->get_page_html( 1, 'en_US', 'en_US' );
		$this->assertStringContainsString( 'lp-cache-health-panel', $admin_html );
		$this->assertStringContainsString( 'data-lp-cert-status="uncertified"', $admin_html );
		$this->assertStringContainsString( 'rocket_cache_dynamic_cookies', $admin_html );
	}
}
