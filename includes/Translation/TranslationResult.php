<?php
/**
 * Value object representing a normalized translation provider result.
 *
 * @package LocalePilot\Translation
 */

declare(strict_types=1);

namespace LocalePilot\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized result returned by all translation provider adapters.
 */
final class TranslationResult {

	/**
	 * Default review status for newly machine-translated content per PRODUCT-PLAN.md §4.
	 */
	public const STATUS_PENDING_REVIEW = 'pending_review';

	/**
	 * Approved review status after merchant confirmation.
	 */
	public const STATUS_APPROVED = 'approved';

	/**
	 * Standardized error codes across providers.
	 */
	public const ERROR_NONE                = '';
	public const ERROR_INVALID_CREDENTIALS = 'invalid_credentials';
	public const ERROR_QUOTA_EXCEEDED      = 'quota_exceeded';
	public const ERROR_RATE_LIMITED        = 'rate_limited';
	public const ERROR_TIMEOUT             = 'timeout';
	public const ERROR_RESPONSE_TOO_LARGE  = 'response_too_large';
	public const ERROR_PROVIDER_FAILURE    = 'provider_error';
	public const ERROR_PRIVACY_BLOCKED     = 'privacy_blocked';
	public const ERROR_STOREFRONT_BLOCKED  = 'storefront_blocked';

	/**
	 * Whether the translation succeeded.
	 *
	 * @var bool
	 */
	private bool $success;

	/**
	 * Provider identifier (`gemini`, `deepl`, `google_cloud`, `baidu`).
	 *
	 * @var string
	 */
	private string $provider_id;

	/**
	 * Translated text with protected tokens restored (empty on failure).
	 *
	 * @var string
	 */
	private string $translated_text;

	/**
	 * Source text SHA-256 hash.
	 *
	 * @var string
	 */
	private string $source_hash;

	/**
	 * Target locale code.
	 *
	 * @var string
	 */
	private string $target_locale;

	/**
	 * Initial review status (`pending_review`).
	 *
	 * @var string
	 */
	private string $review_status;

	/**
	 * Standardized error code (empty when successful).
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Sanitized error message with secrets/keys scrubbed.
	 *
	 * @var string
	 */
	private string $error_message;

	/**
	 * Whether the failed operation is safe for asynchronous Action Scheduler retry.
	 *
	 * @var bool
	 */
	private bool $retryable;

	/**
	 * HTTP status code returned by provider.
	 *
	 * @var int
	 */
	private int $http_status;

	/**
	 * Construct a TranslationResult.
	 *
	 * @param bool   $success         Whether translation succeeded.
	 * @param string $provider_id     Provider ID.
	 * @param string $translated_text Translated text.
	 * @param string $source_hash     SHA-256 of source text.
	 * @param string $target_locale   Target locale.
	 * @param string $review_status   Review status (`pending_review`).
	 * @param string $error_code      Standardized error code.
	 * @param string $error_message   Sanitized error message.
	 * @param bool   $retryable       Whether failure is retryable.
	 * @param int    $http_status     HTTP status code.
	 */
	public function __construct(
		bool $success,
		string $provider_id,
		string $translated_text = '',
		string $source_hash = '',
		string $target_locale = '',
		string $review_status = self::STATUS_PENDING_REVIEW,
		string $error_code = self::ERROR_NONE,
		string $error_message = '',
		bool $retryable = false,
		int $http_status = 200
	) {
		$this->success         = $success;
		$this->provider_id     = $provider_id;
		$this->translated_text = $translated_text;
		$this->source_hash     = $source_hash;
		$this->target_locale   = $target_locale;
		$this->review_status   = $review_status;
		$this->error_code      = $error_code;
		$this->error_message   = $error_message;
		$this->retryable       = $retryable;
		$this->http_status     = $http_status;
	}

	/**
	 * Create a successful translation result entering `pending_review` state.
	 *
	 * @param string $provider_id     Provider ID.
	 * @param string $translated_text Translated text.
	 * @param string $source_hash     Source hash.
	 * @param string $target_locale   Target locale.
	 * @param int    $http_status     HTTP status code.
	 * @return self
	 */
	public static function success(
		string $provider_id,
		string $translated_text,
		string $source_hash,
		string $target_locale,
		int $http_status = 200
	): self {
		return new self(
			true,
			$provider_id,
			$translated_text,
			$source_hash,
			$target_locale,
			self::STATUS_PENDING_REVIEW,
			self::ERROR_NONE,
			'',
			false,
			$http_status
		);
	}

	/**
	 * Create a failed translation result.
	 *
	 * @param string $provider_id   Provider ID.
	 * @param string $error_code    Standardized error code.
	 * @param string $error_message Sanitized error message.
	 * @param bool   $retryable     Whether failure is retryable.
	 * @param int    $http_status   HTTP status code.
	 * @param string $source_hash   Optional source hash.
	 * @param string $target_locale Optional target locale.
	 * @return self
	 */
	public static function failure(
		string $provider_id,
		string $error_code,
		string $error_message,
		bool $retryable = false,
		int $http_status = 0,
		string $source_hash = '',
		string $target_locale = ''
	): self {
		return new self(
			false,
			$provider_id,
			'',
			$source_hash,
			$target_locale,
			self::STATUS_PENDING_REVIEW,
			$error_code,
			$error_message,
			$retryable,
			$http_status
		);
	}

	/**
	 * Whether the translation succeeded.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Return the provider identifier.
	 *
	 * @return string
	 */
	public function get_provider_id(): string {
		return $this->provider_id;
	}

	/**
	 * Return the translated text.
	 *
	 * @return string
	 */
	public function get_translated_text(): string {
		return $this->translated_text;
	}

	/**
	 * Return the SHA-256 hash of the source text.
	 *
	 * @return string
	 */
	public function get_source_hash(): string {
		return $this->source_hash;
	}

	/**
	 * Return the target locale.
	 *
	 * @return string
	 */
	public function get_target_locale(): string {
		return $this->target_locale;
	}

	/**
	 * Return the review status (`pending_review`).
	 *
	 * @return string
	 */
	public function get_review_status(): string {
		return $this->review_status;
	}

	/**
	 * Return the standardized error code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Return the sanitized error message.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->error_message;
	}

	/**
	 * Whether the failed request should be retried by Action Scheduler.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}

	/**
	 * Return the HTTP status code.
	 *
	 * @return int
	 */
	public function get_http_status(): int {
		return $this->http_status;
	}

	/**
	 * Return a copy of this result with an updated review status (`pending_review` or `approved`).
	 *
	 * @param string $review_status Review status.
	 * @return self
	 */
	public function with_review_status( string $review_status ): self {
		$normalized = self::STATUS_APPROVED === $review_status
			? self::STATUS_APPROVED
			: self::STATUS_PENDING_REVIEW;

		return new self(
			$this->success,
			$this->provider_id,
			$this->translated_text,
			$this->source_hash,
			$this->target_locale,
			$normalized,
			$this->error_code,
			$this->error_message,
			$this->retryable,
			$this->http_status
		);
	}
}
