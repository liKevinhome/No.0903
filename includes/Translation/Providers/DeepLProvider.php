<?php
/**
 * DeepL translation provider adapter.
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
 * Translates storefront content via the DeepL v2 REST API (supporting both
 * DeepL Free `:fx` keys and DeepL Pro keys).
 */
final class DeepLProvider extends AbstractTranslationProvider {

	/**
	 * Provider identifier.
	 */
	public const PROVIDER_ID = 'deepl';

	/**
	 * DeepL Pro API endpoint.
	 */
	public const ENDPOINT_PRO = 'https://api.deepl.com/v2/translate';

	/**
	 * DeepL Free API endpoint (used when key ends with `:fx`).
	 */
	public const ENDPOINT_FREE = 'https://api-free.deepl.com/v2/translate';

	/**
	 * Merchant DeepL API key.
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
	 * Construct the DeepL provider adapter.
	 *
	 * @param string                   $api_key                  Merchant DeepL API key.
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
		return 'DeepL';
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
	 * Resolve the appropriate DeepL endpoint URL (`api-free.deepl.com` for `:fx` keys,
	 * `api.deepl.com` for Pro keys).
	 *
	 * @return string
	 */
	public function resolve_endpoint(): string {
		if ( '' !== $this->custom_endpoint ) {
			return $this->custom_endpoint;
		}

		if ( ':fx' === substr( strtolower( $this->api_key ), -3 ) ) {
			return self::ENDPOINT_FREE;
		}

		return self::ENDPOINT_PRO;
	}

	/**
	 * Map a WordPress locale code to DeepL's language format.
	 *
	 * @param string $locale        WordPress locale (e.g. `zh_CN`, `en_US`, `fr_FR`).
	 * @param bool   $is_target     True when mapping target_lang, false for source_lang.
	 * @return string
	 */
	public static function map_locale( string $locale, bool $is_target = true ): string {
		$clean = strtoupper( str_replace( '-', '_', trim( $locale ) ) );
		if ( '' === $clean ) {
			return '';
		}

		if ( 0 === strpos( $clean, 'ZH' ) ) {
			if ( ! $is_target ) {
				return 'ZH';
			}
			return ( 'ZH_TW' === $clean || 'ZH_HK' === $clean || 'ZH_HANT' === $clean ) ? 'ZH-HANT' : 'ZH-HANS';
		}

		if ( 0 === strpos( $clean, 'EN' ) ) {
			if ( ! $is_target ) {
				return 'EN';
			}
			return 'EN_GB' === $clean ? 'EN-GB' : 'EN-US';
		}

		if ( 0 === strpos( $clean, 'PT' ) ) {
			if ( ! $is_target ) {
				return 'PT';
			}
			return 'PT_BR' === $clean ? 'PT-BR' : 'PT-PT';
		}

		$parts = explode( '_', $clean );
		return $parts[0];
	}

	/**
	 * Execute the DeepL v2 translate request.
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
			'text'        => array( $masked_text ),
			'target_lang' => self::map_locale( $target_locale, true ),
		);

		$mapped_source = self::map_locale( $source_locale, false );
		if ( '' !== $mapped_source ) {
			$payload['source_lang'] = $mapped_source;
		}

		$body_json = $this->encode_json( $payload );

		$response = $this->send_provider_request(
			'POST',
			$this->resolve_endpoint(),
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'DeepL-Auth-Key ' . $this->api_key,
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
			$err_msg = is_array( $decoded ) && isset( $decoded['message'] )
				? (string) $decoded['message']
				: 'DeepL HTTP error ' . $status;

			if ( in_array( $status, array( 401, 403 ), true ) ) {
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

			if ( 456 === $status ) {
				return TranslationResult::failure(
					self::PROVIDER_ID,
					TranslationResult::ERROR_QUOTA_EXCEEDED,
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

		$translated_text = $decoded['translations'][0]['text'] ?? null;
		if ( ! is_string( $translated_text ) ) {
			return TranslationResult::failure(
				self::PROVIDER_ID,
				TranslationResult::ERROR_PROVIDER_FAILURE,
				'DeepL response did not contain translations[0].text.',
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
