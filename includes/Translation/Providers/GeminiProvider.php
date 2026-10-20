<?php
/**
 * Google Gemini translation provider adapter.
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
 * Translates storefront content via the Google Gemini REST API (`generateContent`)
 * while preserving `[[LP_TOK_N]]` protected placeholders.
 */
final class GeminiProvider extends AbstractTranslationProvider {

	/**
	 * Provider identifier.
	 */
	public const PROVIDER_ID = 'gemini';

	/**
	 * Default Gemini model identifier.
	 */
	public const DEFAULT_MODEL = 'gemini-2.5-flash';

	/**
	 * Base endpoint template.
	 */
	public const ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * Merchant-configured Gemini API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Target Gemini model name.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Optional custom endpoint override.
	 *
	 * @var string
	 */
	private string $custom_endpoint;

	/**
	 * Construct the Gemini provider adapter.
	 *
	 * @param string                   $api_key                  Merchant Gemini API key.
	 * @param string                   $model                    Gemini model name.
	 * @param HttpClientInterface|null $http_client              Optional HTTP client.
	 * @param bool|null                $allow_execution_override Optional context guard override.
	 * @param string                   $custom_endpoint          Optional custom endpoint URL.
	 */
	public function __construct(
		string $api_key,
		string $model = self::DEFAULT_MODEL,
		?HttpClientInterface $http_client = null,
		?bool $allow_execution_override = null,
		string $custom_endpoint = ''
	) {
		parent::__construct( $http_client, $allow_execution_override );
		$this->api_key         = trim( $api_key );
		$clean_model           = trim( $model );
		$this->model           = '' !== $clean_model ? $clean_model : self::DEFAULT_MODEL;
		$this->custom_endpoint = trim( $custom_endpoint );
	}

	/**
	 * Resolve the Gemini `generateContent` endpoint URL.
	 *
	 * @return string
	 */
	public function resolve_endpoint(): string {
		if ( '' !== $this->custom_endpoint ) {
			return $this->custom_endpoint;
		}

		return self::ENDPOINT_BASE . rawurlencode( $this->model ) . ':generateContent';
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
		return 'Google Gemini';
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
	 * Execute the Gemini `generateContent` API call.
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
		$url = $this->resolve_endpoint();

		$system_prompt = sprintf(
			'You are an e-commerce localization engine. Translate the input text from %s to %s. '
			. 'Keep every placeholder token matching [[LP_TOK_number]] (such as [[LP_TOK_0]]) completely unchanged in the output. '
			. 'Return only the translated text without extra commentary or markdown fences.',
			'' !== $source_locale ? $source_locale : 'auto',
			$target_locale
		);

		$payload = array(
			'systemInstruction' => array(
				'parts' => array(
					array( 'text' => $system_prompt ),
				),
			),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $masked_text ),
					),
				),
			),
			'generationConfig'  => array(
				'temperature' => 0.1,
			),
		);

		$body_json = $this->encode_json( $payload );

		$response = $this->send_provider_request(
			'POST',
			$url,
			array(
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $this->api_key,
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
			$api_err_msg = is_array( $decoded ) && isset( $decoded['error']['message'] )
				? (string) $decoded['error']['message']
				: 'Gemini HTTP error ' . $status;

			if ( in_array( $status, array( 400, 401, 403 ), true ) ) {
				return TranslationResult::failure(
					self::PROVIDER_ID,
					TranslationResult::ERROR_INVALID_CREDENTIALS,
					$api_err_msg,
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
					$api_err_msg,
					true,
					$status,
					$source_hash,
					$target_locale
				);
			}

			return TranslationResult::failure(
				self::PROVIDER_ID,
				TranslationResult::ERROR_PROVIDER_FAILURE,
				$api_err_msg,
				$status >= 500,
				$status,
				$source_hash,
				$target_locale
			);
		}

		$candidate_text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
		if ( ! is_string( $candidate_text ) || '' === trim( $candidate_text ) ) {
			return TranslationResult::failure(
				self::PROVIDER_ID,
				TranslationResult::ERROR_PROVIDER_FAILURE,
				'Gemini response did not contain translated text.',
				false,
				$status,
				$source_hash,
				$target_locale
			);
		}

		return TranslationResult::success(
			self::PROVIDER_ID,
			trim( $candidate_text ),
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
