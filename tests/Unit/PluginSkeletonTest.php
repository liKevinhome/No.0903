<?php
/**
 * Unit tests for the Stage 1 LocalePilot plugin skeleton.
 *
 * @package LocalePilot\Tests\Unit
 */

declare(strict_types=1);

namespace LocalePilot\Tests\Unit;

use LocalePilot\Autoloader;
use LocalePilot\Compatibility;
use LocalePilot\Environment;
use LocalePilot\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Verifies core skeleton bootstrap, autoloader safety, environment checks,
 * and WooCommerce feature compatibility declarations.
 */
final class PluginSkeletonTest extends TestCase {

	/**
	 * Reset singleton state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Plugin::reset_instance();
	}

	/**
	 * Ensure the built-in PSR-4 runtime autoloader is registered and blocks path traversal.
	 *
	 * @return void
	 */
	public function test_autoloader_resolves_valid_classes_and_rejects_invalid_paths(): void {
		$this->assertTrue( Autoloader::is_registered() );

		$base_dir = LOCALEPILOT_PATH . 'includes';

		$resolved = Autoloader::resolve_path( 'LocalePilot\\Plugin', $base_dir );
		$this->assertNotNull( $resolved );
		$this->assertStringEndsWith( 'Plugin.php', $resolved );

		$this->assertNull( Autoloader::resolve_path( 'OtherVendor\\Plugin', $base_dir ) );
		$this->assertNull( Autoloader::resolve_path( 'LocalePilot\\..\\WpConfig', $base_dir ) );
		$this->assertNull( Autoloader::resolve_path( 'LocalePilot\\', $base_dir ) );
	}

	/**
	 * Ensure Environment version checks accurately validate minimum versions.
	 *
	 * @return void
	 */
	public function test_environment_version_checks(): void {
		$this->assertTrue( Environment::is_php_compatible( '7.4.0' ) );
		$this->assertTrue( Environment::is_php_compatible( '8.2.10' ) );
		$this->assertFalse( Environment::is_php_compatible( '7.3.99' ) );

		$this->assertTrue( Environment::is_wp_compatible( '6.2' ) );
		$this->assertTrue( Environment::is_wp_compatible( '6.6.1' ) );
		$this->assertFalse( Environment::is_wp_compatible( '6.1.9' ) );
		$this->assertFalse( Environment::is_wp_compatible( '' ) );

		$this->assertTrue( Environment::is_woocommerce_compatible( '8.0.0', true ) );
		$this->assertFalse( Environment::is_woocommerce_compatible( '7.9.0', true ) );
		$this->assertFalse( Environment::is_woocommerce_compatible( '8.5.0', false ) );
	}

	/**
	 * Ensure missing requirements are reported accurately.
	 *
	 * @return void
	 */
	public function test_missing_requirements_reporting(): void {
		$this->assertSame(
			array(),
			Environment::get_missing_requirements( '8.1.0', '6.5', '8.8.0', true )
		);

		$this->assertSame(
			array( 'php', 'wordpress', 'woocommerce' ),
			Environment::get_missing_requirements( '7.2.0', '5.9', '7.0.0', false )
		);
	}

	/**
	 * Ensure WooCommerce compatibility matrix and generalization guardrails align with PRODUCT-PLAN.md.
	 *
	 * @return void
	 */
	public function test_woocommerce_compatibility_matrix(): void {
		$matrix = Compatibility::get_feature_matrix();

		$this->assertArrayHasKey( 'custom_order_tables', $matrix );
		$this->assertTrue( $matrix['custom_order_tables'] );

		$this->assertArrayHasKey( 'cart_checkout_blocks', $matrix );
		$this->assertTrue( $matrix['cart_checkout_blocks'] );

		$this->assertSame( 'lp_lang', Compatibility::COOKIE_NAME );
		$this->assertSame( '_localepilot_order_locale', Compatibility::ORDER_LOCALE_META_KEY );

		$guardrails = Compatibility::get_compatibility_guardrails();
		$this->assertTrue( $guardrails['zero_composer_runtime'] );
		$this->assertTrue( $guardrails['merchant_agnostic_no_hardcode'] );
		$this->assertTrue( $guardrails['hpos_order_crud_only'] );
		$this->assertTrue( $guardrails['preserve_currency_and_tax'] );
		$this->assertTrue( $guardrails['storefront_fail_open'] );
		$this->assertTrue( $guardrails['preserve_data_on_uninstall'] );
	}

	/**
	 * Ensure Plugin singleton initializes safely when WordPress/WooCommerce runtime is absent.
	 *
	 * @return void
	 */
	public function test_plugin_singleton_fails_closed_without_wc_runtime(): void {
		$plugin = Plugin::instance();

		$this->assertSame( LOCALEPILOT_VERSION, $plugin->get_version() );
		$this->assertFalse( $plugin->init() );
		$this->assertFalse( $plugin->is_booted() );
		$this->assertContains( 'woocommerce', $plugin->get_missing_requirements() );
	}
}
