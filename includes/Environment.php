<?php
/**
 * Runtime environment and dependency checks.
 *
 * @package LocalePilot
 */

declare(strict_types=1);

namespace LocalePilot;

defined( 'ABSPATH' ) || exit;

/**
 * Validates minimum PHP, WordPress, and WooCommerce requirements.
 */
final class Environment {

	/**
	 * Minimum supported PHP version.
	 */
	public const MIN_PHP_VERSION = '7.4.0';

	/**
	 * Minimum supported WordPress version.
	 */
	public const MIN_WP_VERSION = '6.2';

	/**
	 * Minimum supported WooCommerce version.
	 */
	public const MIN_WC_VERSION = '8.0.0';

	/**
	 * Check whether a PHP version meets the minimum requirement.
	 *
	 * @param string|null $php_version Optional PHP version string for testing.
	 * @return bool
	 */
	public static function is_php_compatible( ?string $php_version = null ): bool {
		$version = $php_version ?? PHP_VERSION;
		return version_compare( $version, self::MIN_PHP_VERSION, '>=' );
	}

	/**
	 * Check whether a WordPress version meets the minimum requirement.
	 *
	 * @param string|null $wp_version Optional WordPress version string for testing.
	 * @return bool
	 */
	public static function is_wp_compatible( ?string $wp_version = null ): bool {
		if ( null === $wp_version ) {
			global $wp_version;
		}

		if ( ! is_string( $wp_version ) || '' === $wp_version ) {
			return false;
		}

		return version_compare( $wp_version, self::MIN_WP_VERSION, '>=' );
	}

	/**
	 * Check whether WooCommerce is active and meets the minimum version.
	 *
	 * @param string|null $wc_version Optional WooCommerce version string for testing.
	 * @param bool|null   $wc_active  Optional WooCommerce active flag for testing.
	 * @return bool
	 */
	public static function is_woocommerce_compatible( ?string $wc_version = null, ?bool $wc_active = null ): bool {
		$is_active = $wc_active ?? ( class_exists( '\WooCommerce', false ) || defined( 'WC_VERSION' ) );
		if ( ! $is_active ) {
			return false;
		}

		$version = $wc_version ?? ( defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : '' );
		if ( '' === $version ) {
			return false;
		}

		return version_compare( $version, self::MIN_WC_VERSION, '>=' );
	}

	/**
	 * Return a list of unmet requirement codes.
	 *
	 * @param string|null $php_version Optional PHP version override.
	 * @param string|null $wp_version  Optional WP version override.
	 * @param string|null $wc_version  Optional WC version override.
	 * @param bool|null   $wc_active   Optional WC active override.
	 * @return array<int, string>
	 */
	public static function get_missing_requirements(
		?string $php_version = null,
		?string $wp_version = null,
		?string $wc_version = null,
		?bool $wc_active = null
	): array {
		$missing = array();

		if ( ! self::is_php_compatible( $php_version ) ) {
			$missing[] = 'php';
		}

		if ( ! self::is_wp_compatible( $wp_version ) ) {
			$missing[] = 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Machine-readable requirement identifier.
		}

		if ( ! self::is_woocommerce_compatible( $wc_version, $wc_active ) ) {
			$missing[] = 'woocommerce';
		}

		return $missing;
	}
}
