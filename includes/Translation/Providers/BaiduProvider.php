<?php
/**
 * Baidu Translate (百度翻译) provider adapter.
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
 * Translates storefront content via the Baidu Translate HTTPS API.
 */
final class BaiduProvider extends AbstractTranslationProvider {

	/**
	 * Provider identifier.
	 */
	public const PROVIDER_ID = 'baidu';

	/**
	 * Baidu Translate HTTPS endpoint.
	 */
	public const ENDPOINT = 'https://fanyi-api.baidu.com/api/trans/vip/translate';

	/**
	 * Merchant Baidu APP ID.
	 *
	 * @var string
	 */
	private string $app_id;

	/**
	 * Merchant Baidu secret key.
	 *
	 * @var string
	 */
	private string $app_secret;

	/**
	 * Optional custom endpoint override.
	 *
	 * @var string
	 */
	private string $custom_endpoint;

	/**
	 * Construct the Baidu Translate adapter.
	 *
	 * @param string                   $app_id                   Merchant Baidu APP ID.
	 * @param string                   $app_secret               Merchant Baidu Secret Key.
	 * @param HttpClientInterface|null $http_client              Optional HTTP client.
	 * @param bool|null                $allow_execution_override Optional context guard override.
	 * @param string                   $custom_endpoint          Optional custom endpoint URL.
	 */
	public function __construct(
		string $app_id,
		string $app_secret,
		?HttpClientInterface $http_client = null,
		?bool $allow_execution_override = null,
		string $custom_endpoint = ''
	) {
		parent::__construct( $http_client, $allow_execution_override );
		$this->app_id          = trim( $app_id );
		$this->app_secret      = trim( $app_secret );
		$this->custom_endpoint = trim( $custom_endpoint );
	}

	/**
	 * Resolve the Baidu Translate endpoint URL.
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
		return 'Baidu Translate (百度翻译)';
	}

	/**
	 * Check whether both APP ID and Secret Key are configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->app_id && '' !== $this->app_secret;
	}

	/**
	 * Map a WordPress locale code to Baidu Translate's language code conventions.
	 *
	 * @param string $locale WordPress locale (e.g. `zh_CN`, `en_US`, `fr_FR`, `ja`, `ko_KR`, `es_ES`).
	 * @return string
	 */
	public static function map_locale( string $locale ): string {
		$clean = strtolower( str_replace( '-', '_', trim( $locale ) ) );
		if ( '' === $clean ) {
			return 'auto';
		}

		$special_map = array(
			'zh_cn'   => 'zh',
			'zh_hans' => 'zh',
			'zh'      => 'zh',
			'zh_tw'   => 'cht',
			'zh_hk'   => 'cht',
			'zh_hant' => 'cht',
			'fr'      => 'fra',
			'fr_fr'   => 'fra',
			'fr_ca'   => 'fra',
			'es'      => 'spa',
			'es_es'   => 'spa',
			'es_mx'   => 'spa',
			'ja'      => 'jp',
			'ja_jp'   => 'jp',
			'ko'      => 'kor',
			'ko_kr'   => 'kor',
			'ar'      => 'ara',
			'ru'      => 'ru',
			'ru_ru'   => 'ru',
			'de'      => 'de',
			'de_de'   => 'de',
			'pt'      => 'pt',
			'pt_br'   => 'pt',
			'pt_pt'   => 'pt',
			'it'      => 'it',
			'it_it'   => 'it',
			'nl'      => 'nl',
			'nl_nl'   => 'nl',
			'pl'      => 'pl',
			'pl_pl'   => 'pl',
		);

		if ( isset( $special_map[ $clean ] ) ) {
			return $special_map[ $clean ];
		}

		if ( 0 === strpos( $clean, 'en' ) ) {
			return 'en';
		}

		$parts = explode( '_', $clean );
		return $parts[0];
	}

	/**
	 * Compute Baidu API request signature (`md5(appid . q . salt . secret)`).
	 *
	 * @param string $query Text query.
	 * @param string $salt  Random salt string.
	 * @return string
	 */
	public function compute_signature( string $query, string $salt ): string {
		return md5( $this->app_id . $query . $salt . $this->app_secret );
	}

	/**
	 * Execute the Baidu Translate API request.
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
		$salt = (string) time();
		$sign = $this->compute_signature( $masked_text, $salt );

		$form_params = array(
			'q'     => $masked_text,
			'from'  => self::map_locale( $source_locale ),
			'to'    => self::map_locale( $target_locale ),
			'appid' => $this->app_id,
			'salt'  => $salt,
			'sign'  => $sign,
		);

		$response = $this->send_provider_request(
			'POST',
			$this->resolve_endpoint(),
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $form_params, '', '&' ),
			)
		);

		$transport_failure = $this->check_transport_or_size_failure( $response, $source_hash, $target_locale );
		if ( null !== $transport_failure ) {
			return $transport_failure;
		}

		$status  = $response['status'];
		$decoded = json_decode( $response['body'], true );

		if ( 200 !== $status || ! is_array( $decoded ) ) {
			return TranslationResult::failure(
				self::PROVIDER_ID,
				TranslationResult::ERROR_PROVIDER_FAILURE,
				'Baidu Translate HTTP error ' . $status,
				$status >= 500,
				$status,
				$source_hash,
				$target_locale
			);
		}

		if ( isset( $decoded['error_code'] ) && '52000' !== (string) $decoded['error_code'] ) {
			$bd_code = (string) $decoded['error_code'];
			$bd_msg  = isset( $decoded['error_msg'] ) ? (string) $decoded['error_msg'] : 'Baidu error ' . $bd_code;

			if ( in_array( $bd_code, array( '52003', '54001', '58000', '58002' ), true ) ) {
				return TranslationResult::failure(
					self::PROVIDER_ID,
					TranslationResult::ERROR_INVALID_CREDENTIALS,
					$bd_msg . ' (' . $bd_code . ')',
					false,
					$status,
					$source_hash,
					$target_locale
				);
			}

			if ( '54004' === $bd_code ) {
				return TranslationResult::failure(
					self::PROVIDER_ID,
					TranslationResult::ERROR_QUOTA_EXCEEDED,
					$bd_msg . ' (' . $bd_code . ')',
					false,
					$status,
					$source_hash,
					$target_locale
				);
			}

			if ( '54003' === $bd_code ) {
				return TranslationResult::failure(
					self::PROVIDER_ID,
					TranslationResult::ERROR_RATE_LIMITED,
					$bd_msg . ' (' . $bd_code . ')',
					true,
					$status,
					$source_hash,
					$target_locale
				);
			}

			if ( '52001' === $bd_code ) {
				return TranslationResult::failure(
					self::PROVIDER_ID,
					TranslationResult::ERROR_TIMEOUT,
					$bd_msg . ' (' . $bd_code . ')',
					true,
					$status,
					$source_hash,
					$target_locale
				);
			}

			return TranslationResult::failure(
				self::PROVIDER_ID,
				TranslationResult::ERROR_PROVIDER_FAILURE,
				$bd_msg . ' (' . $bd_code . ')',
				'52002' === $bd_code,
				$status,
				$source_hash,
				$target_locale
			);
		}

		if ( empty( $decoded['trans_result'] ) || ! is_array( $decoded['trans_result'] ) ) {
			return TranslationResult::failure(
				self::PROVIDER_ID,
				TranslationResult::ERROR_PROVIDER_FAILURE,
				'Baidu response did not contain trans_result.',
				false,
				$status,
				$source_hash,
				$target_locale
			);
		}

		$segments = array();
		foreach ( $decoded['trans_result'] as $row ) {
			if ( is_array( $row ) && isset( $row['dst'] ) && is_string( $row['dst'] ) ) {
				$segments[] = $row['dst'];
			}
		}

		return TranslationResult::success(
			self::PROVIDER_ID,
			implode( "\n", $segments ),
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
		return array( $this->app_id, $this->app_secret );
	}
}
