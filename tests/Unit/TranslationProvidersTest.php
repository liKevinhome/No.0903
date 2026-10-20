<?php
/**
 * Unit tests for the translation provider abstraction, token protector,
 * privacy/storefront guards, deduplication registry, and the 4 initial
 * provider adapters (Gemini, DeepL, Google Cloud Translation, Baidu Translate).
 *
 * @package LocalePilot\Tests\Unit
 */

declare(strict_types=1);

namespace LocalePilot\Tests\Unit;

use LocalePilot\Admin\AdminPage;
use LocalePilot\Admin\ProviderSettingsManager;
use LocalePilot\Translation\HttpClientInterface;
use LocalePilot\Translation\ProviderRegistry;
use LocalePilot\Translation\Providers\BaiduProvider;
use LocalePilot\Translation\Providers\DeepLProvider;
use LocalePilot\Translation\Providers\GeminiProvider;
use LocalePilot\Translation\Providers\GoogleCloudProvider;
use LocalePilot\Translation\TokenProtector;
use LocalePilot\Translation\TranslationRepository;
use LocalePilot\Translation\TranslationRequest;
use LocalePilot\Translation\TranslationResult;
use LocalePilot\Translation\WpHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Verifies translation provider contracts, token protection, error handling,
 * secret scrubbing, and deduplication.
 */
final class TranslationProvidersTest extends TestCase {

	/**
	 * Build a mock HTTP client backed by a callback or queued response.
	 *
	 * @param callable $handler Callback receiving `($method, $url, $args)` and returning response array.
	 * @param int      $calls   Reference counter incremented on each HTTP request.
	 * @return HttpClientInterface
	 */
	private function create_mock_http_client( callable $handler, int &$calls = 0 ): HttpClientInterface {
		return new class( $handler, $calls ) implements HttpClientInterface {
			/**
			 * Request callback.
			 *
			 * @var callable
			 */
			private $handler;

			/**
			 * Reference to call counter.
			 *
			 * @var int
			 */
			private int $calls_ref;

			/**
			 * Construct mock HTTP client.
			 *
			 * @param callable $handler_callback Handler callback.
			 * @param int      $calls_counter    Call counter reference.
			 */
			public function __construct( callable $handler_callback, int &$calls_counter ) {
				$this->handler   = $handler_callback;
				$this->calls_ref = &$calls_counter;
			}

			/**
			 * Execute mock HTTP request.
			 *
			 * @param string               $method HTTP method.
			 * @param string               $url    Target URL.
			 * @param array<string, mixed> $args   Request arguments.
			 * @return array{status:int, body:string, headers:array<string,string>, error:string, is_timeout:bool}
			 */
			public function request( string $method, string $url, array $args = array() ): array {
				++$this->calls_ref;
				return ( $this->handler )( $method, $url, $args );
			}
		};
	}

	/**
	 * Encode test array payload as JSON string.
	 *
	 * @param array<string, mixed> $data Array data.
	 * @return string
	 */
	private static function encode_test_json( array $data ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test fixture serialization.
		return (string) json_encode( $data );
	}

	/**
	 * Ensure TokenProtector masks and restores HTML, shortcodes, URLs, placeholders,
	 * prices, units, SKUs, and glossary locks accurately.
	 *
	 * @return void
	 */
	public function test_token_protector_masks_and_restores_all_protected_elements(): void {
		$source = '<div class="spec">Buy <strong>Waterproof Jacket</strong> (SKU-LP-9001) for $129.99 or EUR 119.00, weight 1.5kg, capacity 250ml, %1$s {{discount}} [product_badge id="7"] at https://example.com/item</div>';

		$glossary         = array( 'Waterproof Jacket' => 'Veste Imperméable' );
		$protected_tokens = array( 'SKU-LP-9001' );

		$protected   = TokenProtector::protect( $source, $glossary, $protected_tokens );
		$masked_text = $protected['masked_text'];
		$token_map   = $protected['token_map'];

		$this->assertStringNotContainsString( '<div class="spec">', $masked_text );
		$this->assertStringNotContainsString( 'SKU-LP-9001', $masked_text );
		$this->assertStringNotContainsString( '$129.99', $masked_text );
		$this->assertStringNotContainsString( 'EUR 119.00', $masked_text );
		$this->assertStringNotContainsString( '1.5kg', $masked_text );
		$this->assertStringNotContainsString( '250ml', $masked_text );
		$this->assertStringNotContainsString( '%1$s', $masked_text );
		$this->assertStringNotContainsString( '{{discount}}', $masked_text );
		$this->assertStringNotContainsString( '[product_badge id="7"]', $masked_text );
		$this->assertStringNotContainsString( 'https://example.com/item', $masked_text );
		$this->assertStringContainsString( '[[LP_TOK_0]]', $masked_text );

		// Simulate engine translating surrounding words and inserting spaces inside one placeholder.
		$simulated_translated = str_replace(
			array( 'Buy', 'for', 'or', 'weight', 'capacity', 'at', '[[LP_TOK_0]]' ),
			array( 'Achetez', 'pour', 'ou', 'poids', 'capacité', 'sur', '[[ LP_TOK_0 ]]' ),
			$masked_text
		);

		$restored = TokenProtector::restore( $simulated_translated, $token_map );

		$this->assertStringContainsString( '<div class="spec">', $restored );
		$this->assertStringContainsString( '<strong>Veste Imperméable</strong>', $restored );
		$this->assertStringContainsString( '(SKU-LP-9001)', $restored );
		$this->assertStringContainsString( '$129.99', $restored );
		$this->assertStringContainsString( 'EUR 119.00', $restored );
		$this->assertStringContainsString( '1.5kg', $restored );
		$this->assertStringContainsString( '250ml', $restored );
		$this->assertStringContainsString( '%1$s', $restored );
		$this->assertStringContainsString( '{{discount}}', $restored );
		$this->assertStringContainsString( '[product_badge id="7"]', $restored );
		$this->assertStringContainsString( 'https://example.com/item', $restored );
	}

	/**
	 * Ensure storefront visitor requests and prohibited PII contexts are strictly blocked
	 * before any HTTP call is made.
	 *
	 * @return void
	 */
	public function test_storefront_and_privacy_pii_guards_block_outbound_calls(): void {
		$http_calls = 0;
		$client     = $this->create_mock_http_client(
			static function (): array {
				return array(
					'status'     => 200,
					'body'       => '{}',
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			},
			$http_calls
		);

		// 1. Storefront context (`allow_execution_override = false`) must be blocked.
		$storefront_provider = new GeminiProvider( 'gem-secret-key-12345', 'gemini-2.5-flash', $client, false );
		$product_req         = new TranslationRequest( 'Cotton T-Shirt', 'en_US', 'fr_FR', 'product_title' );
		$storefront_res      = $storefront_provider->translate( $product_req );

		$this->assertFalse( $storefront_res->is_success() );
		$this->assertSame( TranslationResult::ERROR_STOREFRONT_BLOCKED, $storefront_res->get_error_code() );
		$this->assertFalse( $storefront_res->is_retryable() );
		$this->assertSame( 0, $http_calls );

		// 2. Prohibited PII contexts must never be sent to any translation provider.
		$admin_provider = new GeminiProvider( 'gem-secret-key-12345', 'gemini-2.5-flash', $client, true );
		foreach ( array( 'order', 'order_note', 'customer_name', 'customer_email', 'billing_address', 'shipping_address', 'payment_meta', 'comment_author' ) as $pii_context ) {
			$pii_req = new TranslationRequest( 'Sensitive Customer Data', 'en_US', 'fr_FR', $pii_context );
			$this->assertTrue( $pii_req->is_prohibited_pii_context() );

			$pii_res = $admin_provider->translate( $pii_req );
			$this->assertFalse( $pii_res->is_success() );
			$this->assertSame( TranslationResult::ERROR_PRIVACY_BLOCKED, $pii_res->get_error_code() );
			$this->assertFalse( $pii_res->is_retryable() );
		}

		$this->assertSame( 0, $http_calls );
	}

	/**
	 * Verify GeminiProvider request structure, token preservation, error mapping, and key redaction.
	 *
	 * @return void
	 */
	public function test_gemini_provider_translation_and_error_handling(): void {
		$captured_url     = '';
		$captured_headers = array();
		$calls            = 0;

		$client = $this->create_mock_http_client(
			static function ( string $method, string $url, array $args ) use ( &$captured_url, &$captured_headers ): array {
				$captured_url     = $url;
				$captured_headers = $args['headers'] ?? array();
				$body             = json_decode( (string) ( $args['body'] ?? '{}' ), true );
				$input_text       = $body['contents'][0]['parts'][0]['text'] ?? '';

				return array(
					'status'     => 200,
					'body'       => self::encode_test_json(
						array(
							'candidates' => array(
								array(
									'content' => array(
										'parts' => array(
											array( 'text' => str_replace( 'Organic Cotton Shirt', 'Chemise en coton bio', $input_text ) ),
										),
									),
								),
							),
						)
					),
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			},
			$calls
		);

		$provider = new GeminiProvider( 'AIzaSyTestSecretKey999', 'gemini-2.5-flash', $client, true );
		$request  = new TranslationRequest(
			'<strong>Organic Cotton Shirt</strong> $29.99',
			'en_US',
			'fr_FR',
			'product_title'
		);

		$result = $provider->translate( $request );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'gemini', $result->get_provider_id() );
		$this->assertSame( TranslationResult::STATUS_PENDING_REVIEW, $result->get_review_status() );
		$this->assertSame( '<strong>Chemise en coton bio</strong> $29.99', $result->get_translated_text() );
		$this->assertStringContainsString( 'gemini-2.5-flash:generateContent', $captured_url );
		$this->assertSame( 'AIzaSyTestSecretKey999', $captured_headers['x-goog-api-key'] ?? '' );

		// Test 403 error with secret redaction.
		$err_client   = $this->create_mock_http_client(
			static function (): array {
				return array(
					'status'     => 403,
					'body'       => self::encode_test_json(
						array(
							'error' => array(
								'message' => 'Invalid API key AIzaSyTestSecretKey999 provided.',
							),
						)
					),
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		);
		$err_provider = new GeminiProvider( 'AIzaSyTestSecretKey999', 'gemini-2.5-flash', $err_client, true );
		$err_result   = $err_provider->translate( $request );

		$this->assertFalse( $err_result->is_success() );
		$this->assertSame( TranslationResult::ERROR_INVALID_CREDENTIALS, $err_result->get_error_code() );
		$this->assertFalse( $err_result->is_retryable() );
		$this->assertStringNotContainsString( 'AIzaSyTestSecretKey999', $err_result->get_error_message() );
		$this->assertStringContainsString( '[REDACTED]', $err_result->get_error_message() );
	}

	/**
	 * Verify DeepLProvider Free/Pro endpoint routing, locale mapping, and 456/429 error handling.
	 *
	 * @return void
	 */
	public function test_deepl_provider_endpoints_locales_and_error_codes(): void {
		$free_provider = new DeepLProvider( 'deepl-free-key-123:fx', null, true );
		$pro_provider  = new DeepLProvider( 'deepl-pro-key-456', null, true );

		$this->assertSame( DeepLProvider::ENDPOINT_FREE, $free_provider->resolve_endpoint() );
		$this->assertSame( DeepLProvider::ENDPOINT_PRO, $pro_provider->resolve_endpoint() );

		$this->assertSame( 'ZH-HANS', DeepLProvider::map_locale( 'zh_CN', true ) );
		$this->assertSame( 'ZH-HANT', DeepLProvider::map_locale( 'zh_TW', true ) );
		$this->assertSame( 'ZH', DeepLProvider::map_locale( 'zh_CN', false ) );
		$this->assertSame( 'EN-US', DeepLProvider::map_locale( 'en_US', true ) );
		$this->assertSame( 'EN-GB', DeepLProvider::map_locale( 'en_GB', true ) );

		$client = $this->create_mock_http_client(
			static function ( string $method, string $url, array $args ): array {
				$body = json_decode( (string) ( $args['body'] ?? '{}' ), true );
				$text = $body['text'][0] ?? '';

				return array(
					'status'     => 200,
					'body'       => self::encode_test_json(
						array(
							'translations' => array(
								array( 'text' => str_replace( 'Leather Bag', 'Sac en cuir', $text ) ),
							),
						)
					),
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		);

		$deepl  = new DeepLProvider( 'deepl-free-key-123:fx', $client, true );
		$result = $deepl->translate( new TranslationRequest( 'Leather Bag 50cm', 'en_US', 'fr_FR' ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'Sac en cuir 50cm', $result->get_translated_text() );

		// HTTP 456 Quota Exceeded -> non-retryable.
		$quota_client = $this->create_mock_http_client(
			static function (): array {
				return array(
					'status'     => 456,
					'body'       => '{"message":"Quota exceeded"}',
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		);
		$quota_deepl  = new DeepLProvider( 'deepl-free-key-123:fx', $quota_client, true );
		$quota_res    = $quota_deepl->translate( new TranslationRequest( 'Leather Bag', 'en_US', 'fr_FR' ) );
		$this->assertFalse( $quota_res->is_success() );
		$this->assertSame( TranslationResult::ERROR_QUOTA_EXCEEDED, $quota_res->get_error_code() );
		$this->assertFalse( $quota_res->is_retryable() );
	}

	/**
	 * Verify GoogleCloudProvider translation, BCP-47 locale mapping, and retryable 429/503 handling.
	 *
	 * @return void
	 */
	public function test_google_cloud_provider_translation_and_retryable_errors(): void {
		$this->assertSame( 'zh-CN', GoogleCloudProvider::map_locale( 'zh_CN' ) );
		$this->assertSame( 'zh-TW', GoogleCloudProvider::map_locale( 'zh_TW' ) );
		$this->assertSame( 'fr', GoogleCloudProvider::map_locale( 'fr_FR' ) );

		$client = $this->create_mock_http_client(
			static function ( string $method, string $url, array $args ): array {
				$body = json_decode( (string) ( $args['body'] ?? '{}' ), true );
				$q    = (string) ( $body['q'] ?? '' );

				return array(
					'status'     => 200,
					'body'       => self::encode_test_json(
						array(
							'data' => array(
								'translations' => array(
									array( 'translatedText' => str_replace( 'Running Shoes', 'Chaussures de course', $q ) ),
								),
							),
						)
					),
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		);

		$provider = new GoogleCloudProvider( 'gcloud-api-key-789', $client, true );
		$res      = $provider->translate( new TranslationRequest( 'Running Shoes $89.00', 'en_US', 'fr_FR' ) );

		$this->assertTrue( $res->is_success() );
		$this->assertSame( 'Chaussures de course $89.00', $res->get_translated_text() );

		// HTTP 429 Rate Limit -> retryable.
		$rate_client   = $this->create_mock_http_client(
			static function (): array {
				return array(
					'status'     => 429,
					'body'       => '{"error":{"message":"Rate Limit Exceeded"}}',
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		);
		$rate_provider = new GoogleCloudProvider( 'gcloud-api-key-789', $rate_client, true );
		$rate_res      = $rate_provider->translate( new TranslationRequest( 'Running Shoes', 'en_US', 'fr_FR' ) );
		$this->assertFalse( $rate_res->is_success() );
		$this->assertSame( TranslationResult::ERROR_RATE_LIMITED, $rate_res->get_error_code() );
		$this->assertTrue( $rate_res->is_retryable() );
	}

	/**
	 * Verify BaiduProvider MD5 signature, locale mapping, translation parsing, and error codes.
	 *
	 * @return void
	 */
	public function test_baidu_provider_signature_locales_and_error_codes(): void {
		$this->assertSame( 'zh', BaiduProvider::map_locale( 'zh_CN' ) );
		$this->assertSame( 'cht', BaiduProvider::map_locale( 'zh_TW' ) );
		$this->assertSame( 'fra', BaiduProvider::map_locale( 'fr_FR' ) );
		$this->assertSame( 'jp', BaiduProvider::map_locale( 'ja_JP' ) );
		$this->assertSame( 'kor', BaiduProvider::map_locale( 'ko_KR' ) );
		$this->assertSame( 'spa', BaiduProvider::map_locale( 'es_ES' ) );

		$client = $this->create_mock_http_client(
			static function ( string $method, string $url, array $args ): array {
				parse_str( (string) ( $args['body'] ?? '' ), $params );
				$q = (string) ( $params['q'] ?? '' );

				return array(
					'status'     => 200,
					'body'       => self::encode_test_json(
						array(
							'from'         => 'en',
							'to'           => 'zh',
							'trans_result' => array(
								array(
									'src' => $q,
									'dst' => str_replace( 'Wireless Headphones', '无线耳机', $q ),
								),
							),
						)
					),
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		);

		$baidu = new BaiduProvider( '20261020001', 'bd_secret_xyz', $client, true );
		$this->assertSame(
			md5( '20261020001Hello123456bd_secret_xyz' ),
			$baidu->compute_signature( 'Hello', '123456' )
		);

		$res = $baidu->translate( new TranslationRequest( 'Wireless Headphones 250g', 'en_US', 'zh_CN' ) );
		$this->assertTrue( $res->is_success() );
		$this->assertSame( '无线耳机 250g', $res->get_translated_text() );

		// Baidu error 54004 (balance insufficient -> quota_exceeded, non-retryable).
		$quota_client = $this->create_mock_http_client(
			static function (): array {
				return array(
					'status'     => 200,
					'body'       => '{"error_code":"54004","error_msg":"Invalid balance for secret bd_secret_xyz"}',
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		);
		$quota_baidu  = new BaiduProvider( '20261020001', 'bd_secret_xyz', $quota_client, true );
		$quota_res    = $quota_baidu->translate( new TranslationRequest( 'Wireless Headphones', 'en_US', 'zh_CN' ) );
		$this->assertFalse( $quota_res->is_success() );
		$this->assertSame( TranslationResult::ERROR_QUOTA_EXCEEDED, $quota_res->get_error_code() );
		$this->assertFalse( $quota_res->is_retryable() );
		$this->assertStringNotContainsString( 'bd_secret_xyz', $quota_res->get_error_message() );
	}

	/**
	 * Verify ProviderRegistry registers all 4 adapters and deduplicates identical
	 * `source_hash × target_locale` requests without making duplicate HTTP calls.
	 *
	 * @return void
	 */
	public function test_provider_registry_and_source_hash_deduplication(): void {
		$http_calls = 0;
		$client     = $this->create_mock_http_client(
			static function (): array {
				return array(
					'status'     => 200,
					'body'       => '{"translations":[{"text":"Chapeau de soleil"}]}',
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			},
			$http_calls
		);

		$registry = ProviderRegistry::create_default(
			array(
				'gemini'       => array( 'api_key' => 'g-key' ),
				'deepl'        => array( 'api_key' => 'd-key:fx' ),
				'google_cloud' => array( 'api_key' => 'gc-key' ),
				'baidu'        => array(
					'app_id'     => 'b-id',
					'app_secret' => 'b-sec',
				),
			),
			$client,
			true
		);

		$this->assertCount( 4, $registry->get_all() );
		$this->assertNotNull( $registry->get( 'gemini' ) );
		$this->assertNotNull( $registry->get( 'deepl' ) );
		$this->assertNotNull( $registry->get( 'google_cloud' ) );
		$this->assertNotNull( $registry->get( 'baidu' ) );

		$req1 = new TranslationRequest( 'Sun Hat', 'en_US', 'fr_FR', 'product_title' );
		$req2 = new TranslationRequest( 'Sun Hat', 'en_US', 'fr_FR', 'product_title' );

		$res1 = $registry->translate_with_dedup( 'deepl', $req1 );
		$res2 = $registry->translate_with_dedup( 'deepl', $req2 );

		$this->assertTrue( $res1->is_success() );
		$this->assertTrue( $res2->is_success() );
		$this->assertSame( 'Chapeau de soleil', $res2->get_translated_text() );
		$this->assertSame( 1, $http_calls, 'Identical source_hash × target_locale must not trigger a second API call.' );
	}

	/**
	 * Verify WpHttpClient response size bounds (default 1MB, min 1KB, hard cap 2MB)
	 * and oversized response rejection across providers.
	 *
	 * @return void
	 */
	public function test_wp_http_client_response_size_bounds_and_oversized_response(): void {
		$default_client = new WpHttpClient();
		$this->assertSame( 1048576, WpHttpClient::DEFAULT_MAX_RESPONSE_BYTES );
		$this->assertSame( 1048576, $default_client->get_max_response_bytes() );
		$this->assertSame( 1024, WpHttpClient::clamp_max_response_bytes( 64 ) );
		$this->assertSame( 2097152, WpHttpClient::clamp_max_response_bytes( 10000000 ) );

		$oversized_client = $this->create_mock_http_client(
			static function (): array {
				return array(
					'status'     => 200,
					'body'       => str_repeat( 'A', 4096 ),
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		);

		$provider = new GeminiProvider( 'gem-size-key-1234', 'gemini-2.5-flash', $oversized_client, true );
		$provider->set_max_response_bytes( 2048 );
		$this->assertSame( 2048, $provider->get_max_response_bytes() );

		$res = $provider->translate( new TranslationRequest( 'Short Title', 'en_US', 'fr_FR', 'product_title' ) );
		$this->assertFalse( $res->is_success() );
		$this->assertSame( TranslationResult::ERROR_RESPONSE_TOO_LARGE, $res->get_error_code() );
		$this->assertFalse( $res->is_retryable() );
	}

	/**
	 * Verify missing credentials, 5xx upstream errors, and malformed JSON handling across all 4 providers.
	 *
	 * @return void
	 */
	public function test_missing_credentials_malformed_json_and_5xx_errors(): void {
		$http_calls = 0;
		$client     = $this->create_mock_http_client(
			static function (): array {
				return array(
					'status'     => 200,
					'body'       => '{}',
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			},
			$http_calls
		);

		$empty_providers = array(
			new GeminiProvider( '', 'gemini-2.5-flash', $client, true ),
			new DeepLProvider( '   ', $client, true ),
			new GoogleCloudProvider( '', $client, true ),
			new BaiduProvider( 'only-app-id', '', $client, true ),
			new BaiduProvider( '', 'only-secret', $client, true ),
		);

		foreach ( $empty_providers as $unconfigured ) {
			$this->assertFalse( $unconfigured->is_configured() );
			$res = $unconfigured->translate( new TranslationRequest( 'Wool Sweater', 'en_US', 'de_DE' ) );
			$this->assertFalse( $res->is_success() );
			$this->assertSame( TranslationResult::ERROR_INVALID_CREDENTIALS, $res->get_error_code() );
			$this->assertFalse( $res->is_retryable() );
		}
		$this->assertSame( 0, $http_calls, 'Unconfigured providers must never make outbound HTTP requests.' );

		// HTTP 503 upstream error -> retryable.
		$err_503_client = $this->create_mock_http_client(
			static function (): array {
				return array(
					'status'     => 503,
					'body'       => 'Service Unavailable',
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		);
		$deepl_503      = new DeepLProvider( 'deepl-key-503:fx', $err_503_client, true );
		$res_503        = $deepl_503->translate( new TranslationRequest( 'Wool Sweater', 'en_US', 'de_DE' ) );
		$this->assertFalse( $res_503->is_success() );
		$this->assertSame( TranslationResult::ERROR_PROVIDER_FAILURE, $res_503->get_error_code() );
		$this->assertTrue( $res_503->is_retryable() );

		// HTTP 200 with invalid JSON -> non-retryable provider failure.
		$bad_json_client = $this->create_mock_http_client(
			static function (): array {
				return array(
					'status'     => 200,
					'body'       => '{malformed_json',
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		);
		$gcloud_bad      = new GoogleCloudProvider( 'gcloud-key-badjson', $bad_json_client, true );
		$res_bad         = $gcloud_bad->translate( new TranslationRequest( 'Wool Sweater', 'en_US', 'de_DE' ) );
		$this->assertFalse( $res_bad->is_success() );
		$this->assertSame( TranslationResult::ERROR_PROVIDER_FAILURE, $res_bad->get_error_code() );
		$this->assertFalse( $res_bad->is_retryable() );
	}

	/**
	 * Verify admin provider settings storage, capability & nonce enforcement,
	 * secret masking (no plaintext secret echoed in HTML), and connection testing.
	 *
	 * @return void
	 */
	public function test_admin_provider_settings_secret_masking_and_connection_test(): void {
		ProviderSettingsManager::reset_memory_state();
		$admin_page = new AdminPage();

		$secret_gemini = 'AIzaSyPlaintextSecretGeminiKey987654';
		$secret_deepl  = 'deeplPlaintextSecretKey654321:fx';
		$secret_gcloud = 'gcloudPlaintextSecretKey112233';
		$secret_bd_id  = '202699887766';
		$secret_bd_sec = 'baiduPlaintextSecretKey445566';

		$payload = array(
			'localepilot_action'   => AdminPage::FORM_ACTION_SAVE_PROVIDERS,
			'active_provider'      => 'deepl',
			'gemini_api_key'       => $secret_gemini,
			'gemini_model'         => 'gemini-2.5-flash',
			'deepl_api_key'        => $secret_deepl,
			'google_cloud_api_key' => $secret_gcloud,
			'baidu_app_id'         => $secret_bd_id,
			'baidu_app_secret'     => $secret_bd_sec,
			'deepl_endpoint'       => 'https://api-free.deepl.com/v2/translate',
		);

		// 1. Reject when capability is false or nonce is false.
		$this->assertFalse( $admin_page->handle_provider_settings_submission( $payload, false, true ) );
		$this->assertFalse( $admin_page->handle_provider_settings_submission( $payload, true, false ) );

		// 2. Accept when capability and nonce are valid.
		$this->assertTrue( $admin_page->handle_provider_settings_submission( $payload, true, true ) );

		// 3. Render admin page HTML and verify NO plaintext secret appears anywhere in the output.
		$html = $admin_page->get_page_html( 1, 'zh_CN', 'en_US' );
		$this->assertStringContainsString( 'data-lp-active-provider="deepl"', $html );
		$this->assertStringContainsString( '••••••••', $html );
		$this->assertStringNotContainsString( $secret_gemini, $html );
		$this->assertStringNotContainsString( $secret_deepl, $html );
		$this->assertStringNotContainsString( $secret_gcloud, $html );
		$this->assertStringNotContainsString( $secret_bd_id, $html );
		$this->assertStringNotContainsString( $secret_bd_sec, $html );

		// 4. Saving again with blank secret inputs preserves existing stored secrets.
		$blank_secret_payload = array(
			'localepilot_action' => AdminPage::FORM_ACTION_SAVE_PROVIDERS,
			'active_provider'    => 'gemini',
			'gemini_api_key'     => '',
			'deepl_api_key'      => '',
		);
		$this->assertTrue( $admin_page->handle_provider_settings_submission( $blank_secret_payload, true, true ) );
		$raw_after = ProviderSettingsManager::get_raw_settings();
		$this->assertSame( 'gemini', $raw_after['active_provider'] );
		$this->assertSame( $secret_gemini, $raw_after['gemini']['api_key'] );
		$this->assertSame( $secret_deepl, $raw_after['deepl']['api_key'] );

		// 5. Server-side connection test enforces capability + nonce and executes probe via mock HTTP.
		$test_payload = array(
			'localepilot_action' => AdminPage::FORM_ACTION_TEST_CONNECTION,
			'provider_id'        => 'deepl',
		);
		$this->assertNull( $admin_page->handle_provider_connection_test( $test_payload, false, true ) );
		$this->assertNull( $admin_page->handle_provider_connection_test( $test_payload, true, false ) );

		$probe_client = $this->create_mock_http_client(
			static function (): array {
				return array(
					'status'     => 200,
					'body'       => '{"translations":[{"text":"你好"}]}',
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			}
		);
		$probe_res    = $admin_page->handle_provider_connection_test( $test_payload, true, true, $probe_client );
		$this->assertNotNull( $probe_res );
		$this->assertTrue( $probe_res->is_success() );
		$this->assertSame( '你好', $probe_res->get_translated_text() );
	}

	/**
	 * Verify persistent TranslationRepository deduplication across requests,
	 * review status workflow (`pending_review` <-> `approved`), and PII field rejection.
	 *
	 * @return void
	 */
	public function test_persistent_translation_repository_deduplication_and_review_workflow(): void {
		TranslationRepository::reset_memory_store();
		ProviderSettingsManager::reset_memory_state();

		ProviderSettingsManager::update_settings(
			array(
				'active_provider' => 'deepl',
				'deepl_api_key'   => 'deepl-repo-test-key:fx',
			)
		);

		$repo = new TranslationRepository();
		$this->assertTrue( $repo->ensure_table() );
		$this->assertStringContainsString( 'CREATE TABLE wp_localepilot_translations', $repo->get_schema_sql() );

		$http_calls = 0;
		$client     = $this->create_mock_http_client(
			static function (): array {
				return array(
					'status'     => 200,
					'body'       => '{"translations":[{"text":"Veste en laine mérinos"}]}',
					'headers'    => array(),
					'error'      => '',
					'is_timeout' => false,
				);
			},
			$http_calls
		);

		$admin_page = new AdminPage();

		// 1. Prohibited PII content_type (`order`, `customer_email`, `user_profile`) must be rejected.
		foreach ( array( 'order', 'customer_email', 'billing_address', 'user_profile' ) as $forbidden_type ) {
			$pii_submit = $admin_page->handle_manual_translation_submission(
				array(
					'localepilot_action' => AdminPage::FORM_ACTION_SUBMIT_TRANSLATION,
					'provider_id'        => 'deepl',
					'content_type'       => $forbidden_type,
					'object_id'          => 99,
					'source_locale'      => 'en_US',
					'target_locale'      => 'fr_FR',
					'source_text'        => 'Customer John Doe Order Note',
				),
				true,
				true,
				$client,
				$repo
			);
			$this->assertNotNull( $pii_submit );
			$this->assertFalse( $pii_submit->is_success() );
			$this->assertSame( TranslationResult::ERROR_PRIVACY_BLOCKED, $pii_submit->get_error_code() );
		}
		$this->assertSame( 0, $http_calls );
		$this->assertCount( 0, $repo->list_recent() );

		// 2. Valid product_title submission calls provider once and persists with `pending_review`.
		$valid_payload = array(
			'localepilot_action' => AdminPage::FORM_ACTION_SUBMIT_TRANSLATION,
			'provider_id'        => 'deepl',
			'content_type'       => 'product_title',
			'object_id'          => 101,
			'field_key'          => 'post_title',
			'source_locale'      => 'en_US',
			'target_locale'      => 'fr_FR',
			'source_text'        => 'Merino Wool Jacket',
		);

		$first_res = $admin_page->handle_manual_translation_submission( $valid_payload, true, true, $client, $repo );
		$this->assertNotNull( $first_res );
		$this->assertTrue( $first_res->is_success() );
		$this->assertSame( 'Veste en laine mérinos', $first_res->get_translated_text() );
		$this->assertSame( TranslationResult::STATUS_PENDING_REVIEW, $first_res->get_review_status() );
		$this->assertSame( 1, $http_calls );

		$recent = $repo->list_recent();
		$this->assertCount( 1, $recent );
		$saved_id = (int) $recent[0]['id'];
		$this->assertSame( 'pending_review', $recent[0]['review_status'] );

		// 3. Approve the translation via admin review status action (enforcing capability & nonce).
		$approve_payload = array(
			'localepilot_action' => AdminPage::FORM_ACTION_UPDATE_REVIEW_STATUS,
			'translation_id'     => $saved_id,
			'review_status'      => TranslationResult::STATUS_APPROVED,
		);
		$this->assertFalse( $admin_page->handle_review_status_submission( $approve_payload, false, true, $repo ) );
		$this->assertFalse( $admin_page->handle_review_status_submission( $approve_payload, true, false, $repo ) );
		$this->assertTrue( $admin_page->handle_review_status_submission( $approve_payload, true, true, $repo ) );

		$updated_row = $repo->find_by_id( $saved_id );
		$this->assertNotNull( $updated_row );
		$this->assertSame( TranslationResult::STATUS_APPROVED, $updated_row['review_status'] );

		// 4. Subsequent submission of identical source text + target locale in a new request reuses
		// the persisted row from TranslationRepository without making a second HTTP call, and preserves `approved` status.
		$second_res = $admin_page->handle_manual_translation_submission( $valid_payload, true, true, $client, $repo );
		$this->assertNotNull( $second_res );
		$this->assertTrue( $second_res->is_success() );
		$this->assertSame( 'Veste en laine mérinos', $second_res->get_translated_text() );
		$this->assertSame( TranslationResult::STATUS_APPROVED, $second_res->get_review_status() );
		$this->assertSame( 1, $http_calls, 'Cross-request persistent deduplication must prevent a second HTTP call.' );
	}
}
