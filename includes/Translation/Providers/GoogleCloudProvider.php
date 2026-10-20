<?php
/**
 * Google Cloud Translation v2 provider adapter.
 *
 * @package LocalePilot\Translation\Providers
 */

declare(strict_types=1);

namespace LocalePilot\Translation\Providers;

use LocalePilot\Translation\AbstractTranslationProvider;
use LocalePilot\Translation\HttpClientInterface;
use LocalePilot\Translation\TranslationResult;

defined( 'ABSPATH' ) || exit;

/**
 * Translates storefront content via the Google Cloud Translation v2 REST API.
 */
final class GoogleCloudProvider extends AbstractTranslationProvider {

	/**
	 * Provider identifier.
	 */
	public const PROVIDER_ID = 'google_cloud';

	/**
	 * Google Cloud Translation v2 REST endpoint.
	 */
	public const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

	/**
	 * Merchant Google Cloud API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Optional custom endpoint override.
	 *
	 * @var string
	 */
	private string $custom_endpoint;

	/**
	 * Construct the Google Cloud Translation adapter.
	 *
	 * @param string                   $api_key                  Merchant Google Cloud API key.
	 * @param HttpClientInterface|null $http_client              Optional HTTP client.
	 * @param bool|null                $allow_execution_override Optional context guard override.
	 * @param string                   $custom_endpoint          Optional custom endpoint URL.
	 */
	public function __construct(
		string $api_key,
		?HttpClientInterface $http_client = null,
		?bool $allow_execution_override = null,
		string $custom_endpoint = ''
	) {
		parent::__construct( $http_client, $allow_execution_override );
		$this->api_key         = trim( $api_key );
		$this->custom_endpoint = trim( $custom_endpoint );
	}

	/**
	 * Resolve the Google Cloud Translation endpoint URL.
	 *
	 * @return string
	 */
	public function resolve_endpoint(): string {
		return '' !== $this->custom_endpoint ? $this->custom_endpoint : self::ENDPOINT;
	}

	/**
	 * Return provider ID.
	 *
	 * @return string
	 */
	public function get_provider_id(): string {
		return self::PROVIDER_ID;
	}

	/**
	 * Return provider display name.
	 *
	 * @return string
	 */
	public function get_provider_name(): string {
		return 'Google Cloud Translation';
	}

	/**
	 * Check whether the merchant API key is configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Map a WordPress locale code to Google Cloud Translation BCP-47 / ISO-639 format.
	 *
	 * @param string $locale WordPress locale (e.g. `zh_CN`, `zh_TW`, `en_US`, `fr_FR`).
	 * @return string
	 */
	public static function map_locale( string $locale ): string {
		$clean = strtolower( str_replace( '-', '_', trim( $locale ) ) );
		if ( '' === $clean ) {
			return '';
		}

		if ( in_array( $clean, array( 'zh_cn', 'zh_hans', 'zh' ), true ) ) {
			return 'zh-CN';
		}

		if ( in_array( $clean, array( 'zh_tw', 'zh_hk', 'zh_hant' ), true ) ) {
			return 'zh-TW';
		}

		$parts = explode( '_', $clean );
		return $parts[0];
	}

	/**
	 * Execute the Google Cloud Translation v2 request.
	 *
	 * @param string $masked_text   Source text with `[[LP_TOK_N]]` placeholders.
	 * @param string $source_locale Source locale code.
	 * @param string $target_locale Target locale code.
	 * @param string $source_hash   SHA-256 hash of original source text.
	 * @return TranslationResult
	 */
	protected function do_provider_translate(
		string $masked_text,
		string $source_locale,
		string $target_locale,
		string $source_hash
	): TranslationResult {
		$payload = array(
			'q'      => $masked_text,
			'target' => self::map_locale( $target_locale ),
			'format' => 'text',
		);

		$mapped_source = self::map_locale( $source_locale );
		if ( '' !== $mapped_source ) {
			$payload['source'] = $mapped_source;
		}

		$body_json = $this->encode_json( $payload );

		$response = $this->send_provider_request(
			'POST',
			$this->resolve_endpoint(),
			array(
				'headers' => array(
					'Content-Type'   => 'application/json',
					'X-Goog-Api-Key' => $this->api_key,
				),
				'body'    => $body_json,
			)
		);

		$transport_failure = $this->check_transport_or_size_failure( $response, $source_hash, $target_locale );
		if ( null !== $transport_failure ) {
			return $transport_failure;
		}

		$status  = $response['status'];
		$decoded = json_decode( $response['body'], true );

		if ( 200 !== $status || ! is_array( $decoded ) ) {
			$err_msg = is_array( $decoded ) && isset( $decoded['error']['message'] )
				? (string) $decoded['error']['message']
				: 'Google Cloud Translation HTTP error ' . $status;

			if ( in_array( $status, array( 400, 401, 403 ), true ) ) {
				return TranslationResult::failure(
					self::PROVIDER_ID,
					TranslationResult::ERROR_INVALID_CREDENTIALS,
					$err_msg,
					false,
					$status,
					$source_hash,
					$target_locale
				);
			}

			if ( 429 === $status ) {
				return TranslationResult::failure(
					self::PROVIDER_ID,
					TranslationResult::ERROR_RATE_LIMITED,
					$err_msg,
					true,
					$status,
					$source_hash,
					$target_locale
				);
			}

			return TranslationResult::failure(
				self::PROVIDER_ID,
				TranslationResult::ERROR_PROVIDER_FAILURE,
				$err_msg,
				$status >= 500,
				$status,
				$source_hash,
				$target_locale
			);
		}

		$translated_text = $decoded['data']['translations'][0]['translatedText'] ?? null;
		if ( ! is_string( $translated_text ) ) {
			return TranslationResult::failure(
				self::PROVIDER_ID,
				TranslationResult::ERROR_PROVIDER_FAILURE,
				'Google Cloud response did not contain data.translations[0].translatedText.',
				false,
				$status,
				$source_hash,
				$target_locale
			);
		}

		return TranslationResult::success(
			self::PROVIDER_ID,
			$translated_text,
			$source_hash,
			$target_locale,
			$status
		);
	}

	/**
	 * Return configured secrets for log/error redaction.
	 *
	 * @return array<int, string>
	 */
	protected function get_sensitive_secrets(): array {
		return array( $this->api_key );
	}
}
