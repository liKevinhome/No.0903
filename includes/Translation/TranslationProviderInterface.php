<?php
/**
 * Contract for all machine translation provider adapters.
 *
 * @package LocalePilot\Translation
 */

declare(strict_types=1);

namespace LocalePilot\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the unified adapter interface for Gemini, DeepL, Google Cloud
 * Translation, and Baidu Translate.
 */
interface TranslationProviderInterface {

	/**
	 * Return the machine-readable provider ID (`gemini`, `deepl`, `google_cloud`, `baidu`).
	 *
	 * @return string
	 */
	public function get_provider_id(): string;

	/**
	 * Return the human-readable provider display name.
	 *
	 * @return string
	 */
	public function get_provider_name(): string;

	/**
	 * Check whether required merchant API credentials are present.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Translate a single request through the provider adapter.
	 *
	 * @param TranslationRequest $request Translation request.
	 * @return TranslationResult
	 */
	public function translate( TranslationRequest $request ): TranslationResult;

	/**
	 * Validate the configured API credentials with a lightweight probe request.
	 *
	 * @return TranslationResult
	 */
	public function validate_credentials(): TranslationResult;
}
