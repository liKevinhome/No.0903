<?php
/**
 * Base translation provider adapter implementing storefront call blocking,
 * privacy PII blocking, token protection/restoration, and secret scrubbing.
 *
 * @package LocalePilot\Translation
 */

declare(strict_types=1);

namespace LocalePilot\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Shared template pipeline for concrete translation providers.
 */
abstract class AbstractTranslationProvider implements TranslationProviderInterface {

	/**
	 * HTTP client instance.
	 *
	 * @var HttpClientInterface
	 */
	protected HttpClientInterface $http_client;

	/**
	 * Maximum allowed response body size in bytes.
	 *
	 * @var int
	 */
	protected int $max_response_bytes = WpHttpClient::DEFAULT_MAX_RESPONSE_BYTES;

	/**
	 * Whether execution is permitted in the current runtime context.
	 *
	 * By product rule (PRODUCT-PLAN.md §1 & §3), storefront visitor requests
	 * never call external translation APIs in real time.
	 *
	 * @var bool|null
	 */
	private ?bool $allow_execution_override;

	/**
	 * Construct base provider with HTTP transport.
	 *
	 * @param HttpClientInterface|null $http_client              Optional HTTP client override.
	 * @param bool|null                $allow_execution_override Optional context guard override for testing/CLI.
	 * @param int                      $max_response_bytes       Optional response body size limit in bytes.
	 */
	public function __construct(
		?HttpClientInterface $http_client = null,
		?bool $allow_execution_override = null,
		int $max_response_bytes = WpHttpClient::DEFAULT_MAX_RESPONSE_BYTES
	) {
		$this->http_client              = $http_client ?? new WpHttpClient( $max_response_bytes );
		$this->allow_execution_override = $allow_execution_override;
		$this->max_response_bytes       = WpHttpClient::clamp_max_response_bytes( $max_response_bytes );
	}

	/**
	 * Configure the bounded maximum response body size in bytes.
	 *
	 * @param int $bytes Maximum response size in bytes.
	 * @return void
	 */
	public function set_max_response_bytes( int $bytes ): void {
		$this->max_response_bytes = WpHttpClient::clamp_max_response_bytes( $bytes );
	}

	/**
	 * Return the configured maximum response body size in bytes.
	 *
	 * @return int
	 */
	public function get_max_response_bytes(): int {
		return $this->max_response_bytes;
	}

	/**
	 * Execute the full guarded translation pipeline:
	 * 1. Block storefront visitor requests (`storefront_blocked`).
	 * 2. Block customer/order PII contexts (`privacy_blocked`).
	 * 3. Verify credentials presence (`invalid_credentials`).
	 * 4. Short-circuit empty source strings.
	 * 5. Mask protected tokens (`TokenProtector::protect()`).
	 * 6. Delegate to concrete provider's `do_provider_translate()`.
	 * 7. Restore protected tokens (`TokenProtector::restore()`) and scrub secrets on error.
	 *
	 * @param TranslationRequest $request Translation request.
	 * @return TranslationResult
	 */
	final public function translate( TranslationRequest $request ): TranslationResult {
		$source_hash   = $request->get_source_hash();
		$target_locale = $request->get_target_locale();

		if ( ! $this->is_execution_context_allowed() ) {
			return TranslationResult::failure(
				$this->get_provider_id(),
				TranslationResult::ERROR_STOREFRONT_BLOCKED,
				'Real-time translation API calls are prohibited during storefront visitor requests.',
				false,
				0,
				$source_hash,
				$target_locale
			);
		}

		if ( $request->is_prohibited_pii_context() ) {
			return TranslationResult::failure(
				$this->get_provider_id(),
				TranslationResult::ERROR_PRIVACY_BLOCKED,
				'Customer, order, address, and payment PII data must never be sent to external translation providers.',
				false,
				0,
				$source_hash,
				$target_locale
			);
		}

		if ( ! $this->is_configured() ) {
			return TranslationResult::failure(
				$this->get_provider_id(),
				TranslationResult::ERROR_INVALID_CREDENTIALS,
				'Provider API credentials are not configured.',
				false,
				0,
				$source_hash,
				$target_locale
			);
		}

		$raw_text = $request->get_source_text();
		if ( '' === trim( $raw_text ) ) {
			return TranslationResult::success(
				$this->get_provider_id(),
				$raw_text,
				$source_hash,
				$target_locale,
				200
			);
		}

		$protected   = TokenProtector::protect(
			$raw_text,
			$request->get_glossary(),
			$request->get_protected_tokens()
		);
		$masked_text = $protected['masked_text'];
		$token_map   = $protected['token_map'];

		$raw_result = $this->do_provider_translate(
			$masked_text,
			$request->get_source_locale(),
			$target_locale,
			$source_hash
		);

		if ( ! $raw_result->is_success() ) {
			$scrubbed_error = TokenProtector::redact_secrets(
				$raw_result->get_error_message(),
				$this->get_sensitive_secrets()
			);

			return TranslationResult::failure(
				$this->get_provider_id(),
				$raw_result->get_error_code(),
				$scrubbed_error,
				$raw_result->is_retryable(),
				$raw_result->get_http_status(),
				$source_hash,
				$target_locale
			);
		}

		$restored_text = TokenProtector::restore( $raw_result->get_translated_text(), $token_map );

		return TranslationResult::success(
			$this->get_provider_id(),
			$restored_text,
			$source_hash,
			$target_locale,
			$raw_result->get_http_status()
		);
	}

	/**
	 * Validate configured API credentials using a minimal probe request.
	 *
	 * @return TranslationResult
	 */
	public function validate_credentials(): TranslationResult {
		$probe = new TranslationRequest( 'Hello', 'en_US', 'zh_CN', 'credential_probe' );
		return $this->translate( $probe );
	}

	/**
	 * Determine whether the current execution context is an authorized admin,
	 * background queue (Action Scheduler / WP-Cron), or CLI context — and NOT
	 * a live storefront visitor request.
	 *
	 * @return bool
	 */
	public function is_execution_context_allowed(): bool {
		if ( null !== $this->allow_execution_override ) {
			return $this->allow_execution_override;
		}

		// Storefront WooCommerce AJAX requests (`?wc-ajax=...`) are visitor requests.
		if ( isset( $_GET['wc-ajax'] ) && '' !== (string) $_GET['wc-ajax'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Context check only.
			return false;
		}

		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			return true;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}

		if ( defined( 'DOING_CRON' ) && constant( 'DOING_CRON' ) ) {
			return true;
		}

		if ( function_exists( 'is_admin' ) ) {
			return is_admin();
		}

		// In standalone CLI / PHPUnit test runner when WordPress is not handling a frontend HTTP request.
		return 'cli' === PHP_SAPI;
	}

	/**
	 * Safely encode an array payload to JSON using `wp_json_encode()` when available.
	 *
	 * @param array<string, mixed> $payload Payload array.
	 * @return string
	 */
	protected function encode_json( array $payload ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			return (string) wp_json_encode( $payload );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback for standalone CLI unit tests when WP core is not loaded.
		return (string) json_encode( $payload );
	}

	/**
	 * Send an HTTP request with bounded `max_response_bytes` and enforce response size limits.
	 *
	 * @param string               $method HTTP method (`POST`, `GET`).
	 * @param string               $url    Target URL.
	 * @param array<string, mixed> $args   Request arguments.
	 * @return array{status:int, body:string, headers:array<string,string>, error:string, is_timeout:bool}
	 */
	protected function send_provider_request( string $method, string $url, array $args = array() ): array {
		if ( ! isset( $args['max_response_bytes'] ) ) {
			$args['max_response_bytes'] = $this->max_response_bytes;
		}

		return $this->http_client->request( $method, $url, $args );
	}

	/**
	 * Check for transport errors or oversized response bodies before parsing provider JSON.
	 *
	 * @param array<string, mixed> $response      Raw HTTP response.
	 * @param string               $source_hash   SHA-256 hash of source text.
	 * @param string               $target_locale Target locale.
	 * @return TranslationResult|null Failure result when transport/size check fails, or null if OK.
	 */
	protected function check_transport_or_size_failure(
		array $response,
		string $source_hash,
		string $target_locale
	): ?TranslationResult {
		if ( strlen( $response['body'] ) > $this->max_response_bytes ) {
			return TranslationResult::failure(
				$this->get_provider_id(),
				TranslationResult::ERROR_RESPONSE_TOO_LARGE,
				sprintf( 'Provider response exceeded maximum allowed size (%d bytes).', $this->max_response_bytes ),
				false,
				$response['status'],
				$source_hash,
				$target_locale
			);
		}

		if ( '' !== $response['error'] ) {
			if ( false !== stripos( $response['error'], 'exceeded maximum allowed size' ) ) {
				return TranslationResult::failure(
					$this->get_provider_id(),
					TranslationResult::ERROR_RESPONSE_TOO_LARGE,
					$response['error'],
					false,
					$response['status'],
					$source_hash,
					$target_locale
				);
			}

			$code = $response['is_timeout']
				? TranslationResult::ERROR_TIMEOUT
				: TranslationResult::ERROR_PROVIDER_FAILURE;

			return TranslationResult::failure(
				$this->get_provider_id(),
				$code,
				$response['error'],
				true,
				0,
				$source_hash,
				$target_locale
			);
		}

		return null;
	}

	/**
	 * Perform the provider-specific API call on the token-masked text.
	 *
	 * @param string $masked_text   Source text with protected tokens masked as `[[LP_TOK_N]]`.
	 * @param string $source_locale Source locale code.
	 * @param string $target_locale Target locale code.
	 * @param string $source_hash   SHA-256 hash of original source text.
	 * @return TranslationResult
	 */
	abstract protected function do_provider_translate(
		string $masked_text,
		string $source_locale,
		string $target_locale,
		string $source_hash
	): TranslationResult;

	/**
	 * Return sensitive secret strings configured on this provider for log/error redaction.
	 *
	 * @return array<int, string>
	 */
	abstract protected function get_sensitive_secrets(): array;
}
