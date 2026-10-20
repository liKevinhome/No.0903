<?php
/**
 * Manages local storage, secret masking, and retrieval of translation provider
 * settings and API credentials in WordPress options (`_localepilot_provider_settings`).
 *
 * Ensures API keys/secrets are never echoed back in HTML inputs or debug logs.
 *
 * @package LocalePilot\Admin
 */

declare(strict_types=1);

namespace LocalePilot\Admin;

use LocalePilot\Translation\ProviderRegistry;
use LocalePilot\Translation\Providers\GeminiProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Handles reading, updating, and masking translation provider settings.
 */
final class ProviderSettingsManager {

	/**
	 * WordPress option key for storing provider configuration (autoload disabled).
	 */
	public const OPTION_KEY = '_localepilot_provider_settings';

	/**
	 * Nonce action for provider settings, connection test, and manual translation actions.
	 */
	public const NONCE_ACTION = 'localepilot_provider_settings_action';

	/**
	 * Nonce request field name.
	 */
	public const NONCE_NAME = '_localepilot_provider_settings_nonce';

	/**
	 * In-memory fallback settings for standalone CLI unit tests without WordPress DB.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $memory_settings = null;

	/**
	 * Reset in-memory fallback settings (intended for isolated unit tests).
	 *
	 * @return void
	 */
	public static function reset_memory_state(): void {
		self::$memory_settings = null;
	}

	/**
	 * Return default empty provider settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'active_provider' => GeminiProvider::PROVIDER_ID,
			'gemini'          => array(
				'api_key'  => '',
				'model'    => GeminiProvider::DEFAULT_MODEL,
				'endpoint' => '',
			),
			'deepl'           => array(
				'api_key'  => '',
				'endpoint' => '',
			),
			'google_cloud'    => array(
				'api_key'  => '',
				'endpoint' => '',
			),
			'baidu'           => array(
				'app_id'     => '',
				'app_secret' => '',
				'endpoint'   => '',
			),
		);
	}

	/**
	 * Retrieve raw provider settings for server-side adapter instantiation only.
	 *
	 * Must never be serialized directly to browser HTML or logs.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_raw_settings(): array {
		$defaults = self::get_defaults();
		$stored   = null;

		if ( function_exists( 'get_option' ) ) {
			$opt = get_option( self::OPTION_KEY, null );
			if ( is_array( $opt ) ) {
				$stored = $opt;
			}
		}

		if ( null === $stored && is_array( self::$memory_settings ) ) {
			$stored = self::$memory_settings;
		}

		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$merged = $defaults;
		if (
			isset( $stored['active_provider'] )
			&& is_string( $stored['active_provider'] )
			&& in_array( $stored['active_provider'], ProviderRegistry::SUPPORTED_PROVIDER_IDS, true )
		) {
			$merged['active_provider'] = $stored['active_provider'];
		}

		foreach ( ProviderRegistry::SUPPORTED_PROVIDER_IDS as $pid ) {
			if ( isset( $stored[ $pid ] ) && is_array( $stored[ $pid ] ) ) {
				foreach ( $defaults[ $pid ] as $field => $default_val ) {
					if ( isset( $stored[ $pid ][ $field ] ) && is_string( $stored[ $pid ][ $field ] ) ) {
						$merged[ $pid ][ $field ] = trim( $stored[ $pid ][ $field ] );
					}
				}
			}
		}

		if ( '' === $merged['gemini']['model'] ) {
			$merged['gemini']['model'] = GeminiProvider::DEFAULT_MODEL;
		}

		return $merged;
	}

	/**
	 * Update provider settings from admin form input while preserving existing
	 * stored secrets when secret inputs are left blank (unless `clear_credentials` is set).
	 *
	 * @param array<string, mixed> $input Submitted settings payload.
	 * @return bool True when saved.
	 */
	public static function update_settings( array $input ): bool {
		$current = self::get_raw_settings();

		if ( isset( $input['active_provider'] ) && is_string( $input['active_provider'] ) ) {
			$candidate = strtolower( trim( $input['active_provider'] ) );
			if ( in_array( $candidate, ProviderRegistry::SUPPORTED_PROVIDER_IDS, true ) ) {
				$current['active_provider'] = $candidate;
			}
		}

		$clear_all = ! empty( $input['clear_credentials'] );
		if ( $clear_all ) {
			$current['gemini']['api_key']       = '';
			$current['deepl']['api_key']        = '';
			$current['google_cloud']['api_key'] = '';
			$current['baidu']['app_id']         = '';
			$current['baidu']['app_secret']     = '';
		}

		// Gemini settings.
		if ( isset( $input['gemini_api_key'] ) && is_string( $input['gemini_api_key'] ) && '' !== trim( $input['gemini_api_key'] ) ) {
			$current['gemini']['api_key'] = trim( $input['gemini_api_key'] );
		}
		if ( isset( $input['gemini_model'] ) && is_string( $input['gemini_model'] ) ) {
			$clean_model                = preg_replace( '/[^a-zA-Z0-9._\-]/', '', trim( $input['gemini_model'] ) );
			$current['gemini']['model'] = '' !== (string) $clean_model ? (string) $clean_model : GeminiProvider::DEFAULT_MODEL;
		}
		if ( isset( $input['gemini_endpoint'] ) && is_string( $input['gemini_endpoint'] ) ) {
			$current['gemini']['endpoint'] = self::sanitize_https_endpoint( $input['gemini_endpoint'] );
		}

		// DeepL settings.
		if ( isset( $input['deepl_api_key'] ) && is_string( $input['deepl_api_key'] ) && '' !== trim( $input['deepl_api_key'] ) ) {
			$current['deepl']['api_key'] = trim( $input['deepl_api_key'] );
		}
		if ( isset( $input['deepl_endpoint'] ) && is_string( $input['deepl_endpoint'] ) ) {
			$current['deepl']['endpoint'] = self::sanitize_https_endpoint( $input['deepl_endpoint'] );
		}

		// Google Cloud settings.
		if ( isset( $input['google_cloud_api_key'] ) && is_string( $input['google_cloud_api_key'] ) && '' !== trim( $input['google_cloud_api_key'] ) ) {
			$current['google_cloud']['api_key'] = trim( $input['google_cloud_api_key'] );
		}
		if ( isset( $input['google_cloud_endpoint'] ) && is_string( $input['google_cloud_endpoint'] ) ) {
			$current['google_cloud']['endpoint'] = self::sanitize_https_endpoint( $input['google_cloud_endpoint'] );
		}

		// Baidu settings.
		if ( isset( $input['baidu_app_id'] ) && is_string( $input['baidu_app_id'] ) && '' !== trim( $input['baidu_app_id'] ) ) {
			$current['baidu']['app_id'] = trim( $input['baidu_app_id'] );
		}
		if ( isset( $input['baidu_app_secret'] ) && is_string( $input['baidu_app_secret'] ) && '' !== trim( $input['baidu_app_secret'] ) ) {
			$current['baidu']['app_secret'] = trim( $input['baidu_app_secret'] );
		}
		if ( isset( $input['baidu_endpoint'] ) && is_string( $input['baidu_endpoint'] ) ) {
			$current['baidu']['endpoint'] = self::sanitize_https_endpoint( $input['baidu_endpoint'] );
		}

		self::$memory_settings = $current;

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $current, false );
		}

		return true;
	}

	/**
	 * Mask a secret string so only a non-reversible status indicator is shown in UI.
	 *
	 * Never returns the raw secret. For secrets >= 8 chars, reveals only the last 3 chars
	 * prefixed by `••••••••`; for shorter secrets, returns `••••••••`.
	 *
	 * @param string $secret Raw secret string.
	 * @return string Masked representation (empty string when secret is not configured).
	 */
	public static function mask_secret( string $secret ): string {
		$trimmed = trim( $secret );
		if ( '' === $trimmed ) {
			return '';
		}

		if ( strlen( $trimmed ) >= 8 ) {
			return '••••••••' . substr( $trimmed, -3 );
		}

		return '••••••••';
	}

	/**
	 * Build a safe, masked view model for rendering the admin settings UI without
	 * exposing any raw API keys or secrets.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_masked_view_data(): array {
		$raw = self::get_raw_settings();

		return array(
			'active_provider' => (string) $raw['active_provider'],
			'gemini'          => array(
				'configured' => '' !== $raw['gemini']['api_key'],
				'masked_key' => self::mask_secret( (string) $raw['gemini']['api_key'] ),
				'model'      => (string) $raw['gemini']['model'],
				'endpoint'   => (string) $raw['gemini']['endpoint'],
			),
			'deepl'           => array(
				'configured' => '' !== $raw['deepl']['api_key'],
				'masked_key' => self::mask_secret( (string) $raw['deepl']['api_key'] ),
				'endpoint'   => (string) $raw['deepl']['endpoint'],
			),
			'google_cloud'    => array(
				'configured' => '' !== $raw['google_cloud']['api_key'],
				'masked_key' => self::mask_secret( (string) $raw['google_cloud']['api_key'] ),
				'endpoint'   => (string) $raw['google_cloud']['endpoint'],
			),
			'baidu'           => array(
				'configured'        => '' !== $raw['baidu']['app_id'] && '' !== $raw['baidu']['app_secret'],
				'masked_app_id'     => self::mask_secret( (string) $raw['baidu']['app_id'] ),
				'masked_app_secret' => self::mask_secret( (string) $raw['baidu']['app_secret'] ),
				'endpoint'          => (string) $raw['baidu']['endpoint'],
			),
		);
	}

	/**
	 * Sanitize an optional custom HTTPS endpoint URL.
	 *
	 * @param string $url Candidate URL.
	 * @return string Valid HTTPS URL or empty string.
	 */
	private static function sanitize_https_endpoint( string $url ): string {
		$trimmed = trim( $url );
		if ( '' === $trimmed ) {
			return '';
		}

		if ( 0 !== stripos( $trimmed, 'https://' ) ) {
			return '';
		}

		if ( false === filter_var( $trimmed, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		return $trimmed;
	}
}
