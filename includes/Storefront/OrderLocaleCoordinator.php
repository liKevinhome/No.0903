<?php
/**
 * WooCommerce HPOS Order Language Snapshot & Transactional Email Coordinator.
 *
 * - Captures the visitor's active storefront language (`lp_lang`) when an order
 *   is created via Classic Checkout (`woocommerce_checkout_create_order`),
 *   Checkout Blocks / Store API (`woocommerce_store_api_checkout_update_order_from_request`),
 *   or storefront order creation (`woocommerce_new_order`).
 * - Persists `_localepilot_order_locale` strictly through `WC_Order` CRUD methods
 *   (`update_meta_data` / `get_meta`) with 100% HPOS (`custom_order_tables`) compatibility
 *   and zero direct `wp_posts` / `wp_postmeta` SQL.
 * - Switches the active translation & WordPress locale context to the order's
 *   snapshot locale when rendering order transactional emails (Confirmation,
 *   Processing, Completed, Refunded, Customer Note), even when triggered from `wp-admin`,
 *   and restores the previous locale immediately afterward.
 *
 * @package LocalePilot\Storefront
 */

declare(strict_types=1);

namespace LocalePilot\Storefront;

use LocalePilot\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates order locale snapshot persistence and order email locale switching.
 */
final class OrderLocaleCoordinator {

	/**
	 * HPOS-compatible order metadata key storing the order's language snapshot.
	 */
	public const ORDER_LOCALE_META_KEY = Compatibility::ORDER_LOCALE_META_KEY;

	/**
	 * Storefront translator instance.
	 *
	 * @var StorefrontTranslator
	 */
	private StorefrontTranslator $translator;

	/**
	 * Whether `switch_to_locale()` was invoked for the current email render cycle.
	 *
	 * @var bool
	 */
	private bool $wp_locale_switched = false;

	/**
	 * Active email locale currently being rendered (`''` when inactive).
	 *
	 * @var string
	 */
	private string $active_email_locale = '';

	/**
	 * Construct the order locale coordinator.
	 *
	 * @param StorefrontTranslator|null $translator Optional storefront translator.
	 */
	public function __construct( ?StorefrontTranslator $translator = null ) {
		$this->translator = $translator ?? new StorefrontTranslator();
	}

	/**
	 * Register WooCommerce order creation and transactional email hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) || ! function_exists( 'add_filter' ) ) {
			return;
		}

		// Classic Checkout order creation hook (before order save).
		add_action( 'woocommerce_checkout_create_order', array( $this, 'record_order_locale_on_checkout' ), 20, 2 );

		// WooCommerce Cart & Checkout Blocks / Store API order hooks.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'record_order_locale_on_store_api' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'record_order_locale_on_store_api_processed' ), 20, 1 );

		// Fallback for orders created during a storefront request via `wc_create_order()`.
		add_action( 'woocommerce_new_order', array( $this, 'record_order_locale_on_new_order' ), 20, 2 );

		// Order line item display name filter (used in order-received page & order emails).
		add_filter( 'woocommerce_order_item_name', array( $this, 'filter_order_item_name' ), 20, 2 );

		// Order transactional email locale switching hooks.
		add_action( 'woocommerce_email_before_order_table', array( $this, 'begin_order_email_locale' ), 1, 4 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'end_order_email_locale' ), 99, 4 );
	}

	/**
	 * Record the active storefront locale onto a `WC_Order` during Classic Checkout.
	 *
	 * Never modifies order amounts, currency, shipping address, tax, or order status.
	 *
	 * @param mixed $order `WC_Order` instance.
	 * @param mixed $data  Posted checkout data.
	 * @return void
	 */
	public function record_order_locale_on_checkout( $order, $data = array() ): void {
		unset( $data );
		$this->stamp_order_locale( $order, false );
	}

	/**
	 * Alias for `record_order_locale_on_checkout()`.
	 *
	 * @param mixed $order `WC_Order` instance.
	 * @param mixed $data  Posted checkout data.
	 * @return void
	 */
	public function capture_classic_checkout_order_locale( $order, $data = array() ): void {
		$this->record_order_locale_on_checkout( $order, $data );
	}

	/**
	 * Record the active storefront locale onto a `WC_Order` during Store API / Checkout Blocks request.
	 *
	 * @param mixed $order   `WC_Order` instance.
	 * @param mixed $request `WP_REST_Request` instance.
	 * @return void
	 */
	public function record_order_locale_on_store_api( $order, $request = null ): void {
		unset( $request );
		$this->stamp_order_locale( $order, true );
	}

	/**
	 * Alias for `record_order_locale_on_store_api()`.
	 *
	 * @param mixed $order   `WC_Order` instance.
	 * @param mixed $request `WP_REST_Request` instance.
	 * @return void
	 */
	public function capture_store_api_order_locale( $order, $request = null ): void {
		$this->record_order_locale_on_store_api( $order, $request );
	}

	/**
	 * Ensure the active storefront locale is persisted on a `WC_Order` processed by Store API.
	 *
	 * @param mixed $order `WC_Order` instance.
	 * @return void
	 */
	public function record_order_locale_on_store_api_processed( $order ): void {
		$this->stamp_order_locale( $order, true );
	}

	/**
	 * Fallback hook when a new order is created on the storefront (`woocommerce_new_order`).
	 *
	 * Does not overwrite an existing `_localepilot_order_locale` and ignores orders
	 * manually created by admins inside `wp-admin`.
	 *
	 * @param mixed $order_id Order ID.
	 * @param mixed $order    Optional `WC_Order` instance.
	 * @return void
	 */
	public function record_order_locale_on_new_order( $order_id, $order = null ): void {
		if ( ! $this->translator->is_storefront_context() ) {
			return;
		}

		$target_order = $order;
		if ( ! is_object( $target_order ) && function_exists( 'wc_get_order' ) && is_numeric( $order_id ) ) {
			$target_order = wc_get_order( (int) $order_id );
		}

		if ( is_object( $target_order ) ) {
			$this->stamp_order_locale( $target_order, true );
		}
	}

	/**
	 * Retrieve the persisted snapshot locale for an order (via HPOS-safe `WC_Order::get_meta`).
	 *
	 * @param mixed $order_or_id `WC_Order` object or integer order ID.
	 * @return string Validated locale code (or empty string if not recorded).
	 */
	public static function get_order_locale( $order_or_id ): string {
		$order = $order_or_id;
		if ( is_numeric( $order_or_id ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order_or_id );
		}

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}

		$raw = $order->get_meta( self::ORDER_LOCALE_META_KEY, true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return '';
		}

		return StorefrontLocaleResolver::sanitize_locale_code( $raw );
	}

	/**
	 * Switch the active storefront & WordPress locale context to the order's
	 * persisted `_localepilot_order_locale` prior to rendering an order email.
	 *
	 * @param mixed $order         `WC_Order` instance.
	 * @param bool  $sent_to_admin Whether the email is sent to store admin.
	 * @param bool  $plain_text    Whether plain text email.
	 * @param mixed $email         Optional `WC_Email` instance.
	 * @return string Active email locale (`''` if unchanged).
	 */
	public function begin_order_email_locale(
		$order,
		bool $sent_to_admin = false,
		bool $plain_text = false,
		$email = null
	): string {
		unset( $sent_to_admin, $plain_text, $email );
		$order_locale = self::get_order_locale( $order );
		if ( '' === $order_locale ) {
			return '';
		}

		$this->active_email_locale = $order_locale;
		StorefrontLocaleResolver::set_runtime_locale_override( $order_locale );
		StorefrontTranslator::set_email_rendering_active( true );

		$this->wp_locale_switched = false;
		if ( function_exists( 'switch_to_locale' ) ) {
			$this->wp_locale_switched = (bool) switch_to_locale( $order_locale );
		}

		return $order_locale;
	}

	/**
	 * Restore the previous storefront & WordPress locale after an order email finishes rendering.
	 *
	 * @param mixed $order         Optional `WC_Order` instance.
	 * @param bool  $sent_to_admin Whether sent to admin.
	 * @param bool  $plain_text    Whether plain text.
	 * @param mixed $email         Optional `WC_Email` instance.
	 * @return void
	 */
	public function end_order_email_locale(
		$order = null,
		bool $sent_to_admin = false,
		bool $plain_text = false,
		$email = null
	): void {
		unset( $order, $sent_to_admin, $plain_text, $email );
		$this->active_email_locale = '';
		StorefrontLocaleResolver::set_runtime_locale_override( null );
		StorefrontTranslator::set_email_rendering_active( false );

		if ( $this->wp_locale_switched && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}
		$this->wp_locale_switched = false;
	}

	/**
	 * Check whether order email locale override is currently active.
	 *
	 * @return bool
	 */
	public function is_email_locale_active(): bool {
		return '' !== $this->active_email_locale && StorefrontTranslator::is_email_rendering_active();
	}

	/**
	 * Return the currently active order email locale (`''` when inactive).
	 *
	 * @return string
	 */
	public function get_active_email_locale(): string {
		return $this->active_email_locale;
	}

	/**
	 * Execute a rendering callback within the order's snapshot locale context and
	 * guarantee restoration of the previous locale afterward.
	 *
	 * @param mixed    $order    `WC_Order` object or order ID.
	 * @param callable $callback Callback receiving `($order_locale)` and returning rendered output.
	 * @return mixed Callback return value.
	 */
	public function render_in_order_locale( $order, callable $callback ) {
		$active_locale = $this->begin_order_email_locale( $order );
		try {
			return $callback( $active_locale );
		} finally {
			$this->end_order_email_locale();
		}
	}

	/**
	 * Filter order line item display name on order-received views and inside order emails
	 * using the order's persisted `_localepilot_order_locale`.
	 *
	 * Never modifies stored order item database rows, quantities, or totals.
	 *
	 * @param mixed $item_name Order item display name.
	 * @param mixed $item      `WC_Order_Item_Product` instance.
	 * @return string
	 */
	public function filter_order_item_name( $item_name, $item = null ): string {
		$original = is_string( $item_name ) ? $item_name : '';
		if ( '' === trim( $original ) || ! $this->translator->is_storefront_context() ) {
			return $original;
		}

		$product_id      = 0;
		$explicit_locale = StorefrontLocaleResolver::get_runtime_locale_override();

		if ( is_object( $item ) ) {
			if ( method_exists( $item, 'get_variation_id' ) && (int) $item->get_variation_id() > 0 ) {
				$product_id = (int) $item->get_variation_id();
			} elseif ( method_exists( $item, 'get_product_id' ) ) {
				$product_id = (int) $item->get_product_id();
			}

			if ( null === $explicit_locale && method_exists( $item, 'get_order' ) ) {
				$order = $item->get_order();
				$loc   = self::get_order_locale( $order );
				if ( '' !== $loc ) {
					$explicit_locale = $loc;
				}
			}
		}

		if ( preg_match( '/^(<a\b[^>]*>)(.*?)(<\/a>)$/is', $original, $matches ) ) {
			$inner_translated = $this->translator->resolve_approved_text(
				'product_title',
				$product_id,
				'post_title',
				$matches[2],
				$explicit_locale
			);
			return $matches[1] . $inner_translated . $matches[3];
		}

		return $this->translator->resolve_approved_text(
			'product_title',
			$product_id,
			'post_title',
			$original,
			$explicit_locale
		);
	}

	/**
	 * Stamp `_localepilot_order_locale` onto a `WC_Order` via CRUD if not already set.
	 *
	 * @param mixed $order     `WC_Order` instance.
	 * @param bool  $save_meta Whether to call `save_meta_data()` / `save()` immediately.
	 * @return void
	 */
	private function stamp_order_locale( $order, bool $save_meta ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$existing = (string) $order->get_meta( self::ORDER_LOCALE_META_KEY, true );
		if ( '' !== trim( $existing ) ) {
			return;
		}

		$active_locale = StorefrontLocaleResolver::resolve_visitor_locale();
		if ( '' === $active_locale ) {
			return;
		}

		$order->update_meta_data( self::ORDER_LOCALE_META_KEY, $active_locale );

		if ( $save_meta ) {
			if ( method_exists( $order, 'save_meta_data' ) ) {
				$order->save_meta_data();
			} elseif ( method_exists( $order, 'save' ) ) {
				$order->save();
			}
		}
	}
}
