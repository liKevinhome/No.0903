<?php
/**
 * Registry and persistent source-hash deduplication coordinator for translation providers.
 *
 * @package LocalePilot\Translation
 */

declare(strict_types=1);

namespace LocalePilot\Translation;

use LocalePilot\Translation\Providers\BaiduProvider;
use LocalePilot\Translation\Providers\DeepLProvider;
use LocalePilot\Translation\Providers\GeminiProvider;
use LocalePilot\Translation\Providers\GoogleCloudProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Registers translation provider adapters and enforces source-hash × target-locale
 * deduplication (both in-memory and via `TranslationRepository` across requests)
 * so unchanged source text is never billed/translated repeatedly.
 */
final class ProviderRegistry {

	/**
	 * Supported provider identifiers.
	 *
	 * @var array<int, string>
	 */
	public const SUPPORTED_PROVIDER_IDS = array(
		GeminiProvider::PROVIDER_ID,
		DeepLProvider::PROVIDER_ID,
		GoogleCloudProvider::PROVIDER_ID,
		BaiduProvider::PROVIDER_ID,
	);

	/**
	 * Registered provider adapters keyed by provider ID.
	 *
	 * @var array<string, TranslationProviderInterface>
	 */
	private array $providers = array();

	/**
	 * Local in-request deduplication cache keyed by `provider_id:source_hash:target_locale`.
	 *
	 * @var array<string, TranslationResult>
	 */
	private array $dedup_cache = array();

	/**
	 * Optional persistent translation repository.
	 *
	 * @var TranslationRepository|null
	 */
	private ?TranslationRepository $repository;

	/**
	 * Construct the provider registry.
	 *
	 * @param TranslationRepository|null $repository Optional persistent repository.
	 */
	public function __construct( ?TranslationRepository $repository = null ) {
		$this->repository = $repository;
	}

	/**
	 * Attach or replace the persistent translation repository.
	 *
	 * @param TranslationRepository $repository Persistent repository.
	 * @return void
	 */
	public function set_repository( TranslationRepository $repository ): void {
		$this->repository = $repository;
	}

	/**
	 * Return the attached persistent translation repository if present.
	 *
	 * @return TranslationRepository|null
	 */
	public function get_repository(): ?TranslationRepository {
		return $this->repository;
	}

	/**
	 * Register a translation provider adapter.
	 *
	 * @param TranslationProviderInterface $provider Provider adapter instance.
	 * @return void
	 */
	public function register( TranslationProviderInterface $provider ): void {
		$this->providers[ $provider->get_provider_id() ] = $provider;
	}

	/**
	 * Retrieve a registered provider adapter by ID.
	 *
	 * @param string $provider_id Provider ID (`gemini`, `deepl`, `google_cloud`, `baidu`).
	 * @return TranslationProviderInterface|null
	 */
	public function get( string $provider_id ): ?TranslationProviderInterface {
		return $this->providers[ $provider_id ] ?? null;
	}

	/**
	 * Return all registered provider adapters.
	 *
	 * @return array<string, TranslationProviderInterface>
	 */
	public function get_all(): array {
		return $this->providers;
	}

	/**
	 * Translate a request using the specified provider, reusing any existing
	 * successful translation with the same `source_hash:target_locale` key
	 * from either the persistent `TranslationRepository` or the in-memory cache.
	 *
	 * @param string             $provider_id Provider ID.
	 * @param TranslationRequest $request     Translation request.
	 * @param int                $object_id   Optional object ID for persistent storage.
	 * @param string             $field_key   Optional field key for persistent storage.
	 * @return TranslationResult
	 */
	public function translate_with_dedup(
		string $provider_id,
		TranslationRequest $request,
		int $object_id = 0,
		string $field_key = ''
	): TranslationResult {
		$provider = $this->get( $provider_id );
		if ( null === $provider ) {
			return TranslationResult::failure(
				$provider_id,
				TranslationResult::ERROR_PROVIDER_FAILURE,
				'Unknown translation provider: ' . $provider_id,
				false,
				0,
				$request->get_source_hash(),
				$request->get_target_locale()
			);
		}

		if ( ! $provider->is_execution_context_allowed() ) {
			return TranslationResult::failure(
				$provider_id,
				TranslationResult::ERROR_STOREFRONT_BLOCKED,
				'Real-time translation API calls are prohibited during storefront visitor requests.',
				false,
				0,
				$request->get_source_hash(),
				$request->get_target_locale()
			);
		}

		if ( $request->is_prohibited_pii_context() ) {
			return TranslationResult::failure(
				$provider_id,
				TranslationResult::ERROR_PRIVACY_BLOCKED,
				'Customer, order, address, and payment PII data must never be sent to external translation providers.',
				false,
				0,
				$request->get_source_hash(),
				$request->get_target_locale()
			);
		}

		$source_hash   = $request->get_source_hash();
		$target_locale = $request->get_target_locale();
		$dedup_key     = $provider_id . ':' . $request->get_dedup_key();

		if ( null !== $this->repository ) {
			$persisted = $this->repository->find_by_dedup(
				$source_hash,
				$target_locale,
				$provider_id,
				$request->get_context_type(),
				$object_id,
				$field_key
			);
			if ( is_array( $persisted ) && isset( $persisted['translated_text'] ) && '' !== (string) $persisted['translated_text'] ) {
				$status = isset( $persisted['review_status'] ) ? (string) $persisted['review_status'] : TranslationResult::STATUS_PENDING_REVIEW;
				$cached = TranslationResult::success(
					$provider_id,
					(string) $persisted['translated_text'],
					$source_hash,
					$target_locale,
					200
				)->with_review_status( $status );

				$this->dedup_cache[ $dedup_key ] = $cached;
				return $cached;
			}
		}

		if ( isset( $this->dedup_cache[ $dedup_key ] ) && $this->dedup_cache[ $dedup_key ]->is_success() ) {
			return $this->dedup_cache[ $dedup_key ];
		}

		$result = $provider->translate( $request );
		if ( $result->is_success() ) {
			$this->dedup_cache[ $dedup_key ] = $result;

			if ( null !== $this->repository && TranslationRepository::is_allowed_content_type( $request->get_context_type() ) ) {
				$this->repository->save(
					array(
						'object_type'     => $request->get_context_type(),
						'object_id'       => $object_id,
						'field_key'       => '' !== $field_key ? $field_key : $request->get_context_type(),
						'source_locale'   => $request->get_source_locale(),
						'target_locale'   => $target_locale,
						'source_hash'     => $source_hash,
						'provider_id'     => $provider_id,
						'translated_text' => $result->get_translated_text(),
						'review_status'   => $result->get_review_status(),
					)
				);
			}
		}

		return $result;
	}

	/**
	 * Build a default registry pre-populated with Gemini, DeepL, Google Cloud
	 * Translation, and Baidu Translate adapters.
	 *
	 * @param array<string, array<string, string>> $config                   Provider credentials map.
	 * @param HttpClientInterface|null             $http_client              Optional HTTP client.
	 * @param bool|null                            $allow_execution_override Optional context guard override.
	 * @param TranslationRepository|null           $repository               Optional persistent repository.
	 * @return self
	 */
	public static function create_default(
		array $config = array(),
		?HttpClientInterface $http_client = null,
		?bool $allow_execution_override = null,
		?TranslationRepository $repository = null
	): self {
		$registry = new self( $repository );

		$gemini_cfg = $config['gemini'] ?? array();
		$registry->register(
			new GeminiProvider(
				(string) ( $gemini_cfg['api_key'] ?? '' ),
				(string) ( $gemini_cfg['model'] ?? GeminiProvider::DEFAULT_MODEL ),
				$http_client,
				$allow_execution_override,
				(string) ( $gemini_cfg['endpoint'] ?? '' )
			)
		);

		$deepl_cfg = $config['deepl'] ?? array();
		$registry->register(
			new DeepLProvider(
				(string) ( $deepl_cfg['api_key'] ?? '' ),
				$http_client,
				$allow_execution_override,
				(string) ( $deepl_cfg['endpoint'] ?? '' )
			)
		);

		$google_cfg = $config['google_cloud'] ?? array();
		$registry->register(
			new GoogleCloudProvider(
				(string) ( $google_cfg['api_key'] ?? '' ),
				$http_client,
				$allow_execution_override,
				(string) ( $google_cfg['endpoint'] ?? '' )
			)
		);

		$baidu_cfg = $config['baidu'] ?? array();
		$registry->register(
			new BaiduProvider(
				(string) ( $baidu_cfg['app_id'] ?? '' ),
				(string) ( $baidu_cfg['app_secret'] ?? '' ),
				$http_client,
				$allow_execution_override,
				(string) ( $baidu_cfg['endpoint'] ?? '' )
			)
		);

		return $registry;
	}
}
