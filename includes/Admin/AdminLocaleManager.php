<?php
/**
 * Admin UI locale resolution and per-admin user override manager.
 *
 * Resolves the LocalePilot admin interface language (`zh_CN` or `en_US`)
 * following:
 * 1. Per-admin user manual override (`_localepilot_admin_ui_locale` user meta).
 * 2. Current admin user's WordPress profile locale (when explicitly set).
 * 3. Site default locale (`WPLANG` / `get_locale()`) when user profile is set
 *    to "Site Default" (empty user `locale` meta).
 * 4. Fallback to `en_US`.
 *
 * Strictly isolated to admin UI rendering: never modifies storefront language,
 * `WPLANG` site option, or visitor `lp_lang` cookies.
 *
 * @package LocalePilot\Admin
 */

declare(strict_types=1);

namespace LocalePilot\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Manages admin UI language resolution and per-user override preferences.
 */
final class AdminLocaleManager {

	/**
	 * User meta key storing the per-admin UI language override mode.
	 */
	public const USER_META_KEY = '_localepilot_admin_ui_locale';

	/**
	 * Automatic mode: follow admin user's WP profile locale, falling back to site locale.
	 */
	public const MODE_AUTO = 'auto';

	/**
	 * Simplified Chinese admin UI locale code.
	 */
	public const LOCALE_ZH_CN = 'zh_CN';

	/**
	 * English (US) admin UI locale code.
	 */
	public const LOCALE_EN_US = 'en_US';

	/**
	 * Nonce action for updating the current admin user's UI language preference.
	 */
	public const NONCE_ACTION = 'localepilot_switch_admin_ui_locale';

	/**
	 * Nonce field name for the admin UI language switcher form.
	 */
	public const NONCE_NAME = '_lp_admin_locale_nonce';

	/**
	 * In-memory user meta store used when WordPress user meta functions are unavailable in isolated unit tests.
	 *
	 * @var array<int, array<string, string>>
	 */
	private static array $memory_user_meta = array();

	/**
	 * Return supported admin UI locales.
	 *
	 * @return array<int, string>
	 */
	public static function get_supported_locales(): array {
		return array(
			self::LOCALE_ZH_CN,
			self::LOCALE_EN_US,
		);
	}

	/**
	 * Return allowed user preference modes.
	 *
	 * @return array<int, string>
	 */
	public static function get_allowed_modes(): array {
		return array(
			self::MODE_AUTO,
			self::LOCALE_ZH_CN,
			self::LOCALE_EN_US,
		);
	}

	/**
	 * Normalize a raw locale string into a supported admin UI locale (`zh_CN` or `en_US`).
	 *
	 * @param string $raw_locale Raw locale identifier (e.g. `zh_CN`, `zh-Hans`, `en_GB`).
	 * @return string|null Normalized locale (`zh_CN` or `en_US`) or null if unsupported/empty.
	 */
	public static function normalize_locale( string $raw_locale ): ?string {
		$cleaned = strtolower( trim( str_replace( '-', '_', $raw_locale ) ) );
		if ( '' === $cleaned ) {
			return null;
		}

		if ( 'zh' === $cleaned || 0 === strpos( $cleaned, 'zh_' ) ) {
			return self::LOCALE_ZH_CN;
		}

		if ( 'en' === $cleaned || 0 === strpos( $cleaned, 'en_' ) ) {
			return self::LOCALE_EN_US;
		}

		return null;
	}

	/**
	 * Retrieve the stored admin UI language override mode for a specific admin user.
	 *
	 * @param int|null $user_id Optional user ID (defaults to current logged-in user).
	 * @return string One of `auto`, `zh_CN`, or `en_US`.
	 */
	public static function get_user_override_mode( ?int $user_id = null ): string {
		$resolved_user_id = self::resolve_user_id( $user_id );
		if ( $resolved_user_id <= 0 ) {
			return self::MODE_AUTO;
		}

		$raw_value = self::read_user_meta( $resolved_user_id, self::USER_META_KEY );
		if ( in_array( $raw_value, array( self::LOCALE_ZH_CN, self::LOCALE_EN_US ), true ) ) {
			return $raw_value;
		}

		return self::MODE_AUTO;
	}

	/**
	 * Persist the admin UI language override mode for a specific admin user.
	 *
	 * @param int    $user_id Admin user ID (must be > 0).
	 * @param string $mode    Target mode (`auto`, `zh_CN`, or `en_US`).
	 * @return bool True when valid and saved.
	 */
	public static function set_user_override_mode( int $user_id, string $mode ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$trimmed_mode = trim( $mode );
		if ( ! in_array( $trimmed_mode, self::get_allowed_modes(), true ) ) {
			return false;
		}

		return self::write_user_meta( $user_id, self::USER_META_KEY, $trimmed_mode );
	}

	/**
	 * Set a user's WordPress profile `locale` meta in memory (for isolated unit testing).
	 *
	 * @param int    $user_id User ID.
	 * @param string $locale  Profile locale string (empty string for "Site Default").
	 * @return void
	 */
	public static function set_test_user_profile_locale( int $user_id, string $locale ): void {
		if ( $user_id > 0 ) {
			self::$memory_user_meta[ $user_id ]['locale'] = $locale;
		}
	}

	/**
	 * Reset in-memory user meta state (intended for isolated unit tests).
	 *
	 * @return void
	 */
	public static function reset_memory_state(): void {
		self::$memory_user_meta = array();
	}

	/**
	 * Resolve the effective admin UI locale (`zh_CN` or `en_US`).
	 *
	 * Resolution order:
	 * 1. Per-admin user manual override (`_localepilot_admin_ui_locale` == `zh_CN` or `en_US`).
	 * 2. Current admin user's explicit WordPress profile locale (`locale` user meta).
	 * 3. Site default locale (`WPLANG` / `get_locale()`) when user profile is set to "Site Default".
	 * 4. Fallback to `en_US`.
	 *
	 * @param int|null    $user_id             Optional user ID override.
	 * @param string|null $user_profile_locale Optional user profile locale override.
	 * @param string|null $site_locale         Optional site default locale override.
	 * @return string Always returns `zh_CN` or `en_US`.
	 */
	public static function resolve_admin_locale(
		?int $user_id = null,
		?string $user_profile_locale = null,
		?string $site_locale = null
	): string {
		$resolved_user_id = self::resolve_user_id( $user_id );

		// 1. Per-admin manual override takes highest priority.
		$override_mode = self::get_user_override_mode( $resolved_user_id );
		if ( in_array( $override_mode, self::get_supported_locales(), true ) ) {
			return $override_mode;
		}

		// 2. Admin user's WordPress profile locale (non-empty when user chose a specific language).
		$profile_locale = $user_profile_locale ?? self::detect_user_profile_locale( $resolved_user_id );
		if ( '' !== trim( $profile_locale ) ) {
			$normalized_user = self::normalize_locale( $profile_locale );
			if ( null !== $normalized_user ) {
				return $normalized_user;
			}

			return self::LOCALE_EN_US;
		}

		// 3. User profile is set to "Site Default" (empty): fall back to site locale.
		$effective_site_locale = $site_locale ?? self::detect_site_locale();
		$normalized_site       = self::normalize_locale( $effective_site_locale );
		if ( null !== $normalized_site ) {
			return $normalized_site;
		}

		// 4. Final safe fallback.
		return self::LOCALE_EN_US;
	}

	/**
	 * Detect a user's explicit WordPress profile locale (empty string means "Site Default").
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function detect_user_profile_locale( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		return self::read_user_meta( $user_id, 'locale' );
	}

	/**
	 * Detect the WordPress site default locale.
	 *
	 * @return string
	 */
	public static function detect_site_locale(): string {
		if ( function_exists( 'get_option' ) ) {
			$wplang = get_option( 'WPLANG' );
			if ( is_string( $wplang ) && '' !== trim( $wplang ) ) {
				return trim( $wplang );
			}
		}

		if ( function_exists( 'get_locale' ) ) {
			$locale = get_locale();
			if ( is_string( $locale ) && '' !== trim( $locale ) ) {
				return trim( $locale );
			}
		}

		return self::LOCALE_EN_US;
	}

	/**
	 * Resolve user ID, falling back to `get_current_user_id()` when available.
	 *
	 * @param int|null $user_id Optional user ID.
	 * @return int
	 */
	private static function resolve_user_id( ?int $user_id ): int {
		if ( null !== $user_id ) {
			return max( 0, $user_id );
		}

		if ( function_exists( 'get_current_user_id' ) ) {
			return max( 0, (int) get_current_user_id() );
		}

		return 0;
	}

	/**
	 * Read a string user meta value via WordPress API or in-memory test store.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key.
	 * @return string
	 */
	private static function read_user_meta( int $user_id, string $meta_key ): string {
		if ( isset( self::$memory_user_meta[ $user_id ][ $meta_key ] ) ) {
			return self::$memory_user_meta[ $user_id ][ $meta_key ];
		}

		if ( function_exists( 'get_user_meta' ) ) {
			$val = get_user_meta( $user_id, $meta_key, true );
			return is_string( $val ) ? trim( $val ) : '';
		}

		return '';
	}

	/**
	 * Write a string user meta value via WordPress API and in-memory test store.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $meta_key   Meta key.
	 * @param string $meta_value Meta value.
	 * @return bool
	 */
	private static function write_user_meta( int $user_id, string $meta_key, string $meta_value ): bool {
		self::$memory_user_meta[ $user_id ][ $meta_key ] = $meta_value;

		if ( function_exists( 'update_user_meta' ) ) {
			update_user_meta( $user_id, $meta_key, $meta_value );
		}

		return true;
	}
}
