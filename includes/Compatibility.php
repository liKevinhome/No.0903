<?php
/**
 * WooCommerce feature compatibility declarations.
 *
 * @package LocalePilot
 */

declare(strict_types=1);

namespace LocalePilot;

defined( 'ABSPATH' ) || exit;

/**
 * Declares compatibility matrix and generalization guardrails per PRODUCT-PLAN.md.
 */
final class Compatibility {

	/**
	 * Default visitor language preference cookie name.
	 */
	public const COOKIE_NAME = 'lp_lang';

	/**
	 * HPOS-safe WooCommerce order metadata key for storing order locale.
	 */
	public const ORDER_LOCALE_META_KEY = '_localepilot_order_locale';

	/**
	 * Return the declared WooCommerce feature compatibility map.
	 *
	 * - `custom_order_tables` (HPOS): Compatible (`true`). Order language meta
	 *   `_localepilot_order_locale` uses `WC_Order` CRUD APIs (`update_meta_data` /
	 *   `get_meta`) with zero direct `wp_postmeta` SQL.
	 * - `cart_checkout_blocks`: Compatible (`true`). Storefront product, shipping rate,
	 *   payment gateway, and coupon filters plus `woocommerce_store_api_checkout_update_order_from_request`
	 *   support both Classic Cart/Checkout (`wc-ajax`) and Cart/Checkout Blocks (`Store API`).
	 *
	 * @return array<string, bool>
	 */
	public static function get_feature_matrix(): array {
		return array(
			'custom_order_tables'  => true,
			'cart_checkout_blocks' => true,
		);
	}

	/**
	 * Return the core generalization and compatibility guardrails for subsequent phases.
	 *
	 * @return array<string, bool>
	 */
	public static function get_compatibility_guardrails(): array {
		return array(
			'zero_composer_runtime'         => true,
			'merchant_agnostic_no_hardcode' => true,
			'hpos_order_crud_only'          => true,
			'preserve_currency_and_tax'     => true,
			'storefront_fail_open'          => true,
			'preserve_data_on_uninstall'    => true,
		);
	}

	/**
	 * Register feature declarations with WooCommerce's FeaturesUtil when available.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @return void
	 */
	public static function declare_woocommerce_features( string $plugin_file ): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		foreach ( self::get_feature_matrix() as $feature_id => $is_compatible ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				$feature_id,
				$plugin_file,
				$is_compatible
			);
		}
	}
}
