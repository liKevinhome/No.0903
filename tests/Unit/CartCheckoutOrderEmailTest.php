<?php
/**
 * Unit tests for WooCommerce Cart, Checkout (Classic & Blocks/Store API),
 * Shipping/Payment labels, Coupon/Validation notices, Order Locale Snapshot,
 * and Order Transactional Email compatibility.
 *
 * @package LocalePilot\Tests\Unit
 */

declare(strict_types=1);

namespace LocalePilot\Tests\Unit;

use LocalePilot\Compatibility;
use LocalePilot\Storefront\CartCheckoutCoordinator;
use LocalePilot\Storefront\OrderLocaleCoordinator;
use LocalePilot\Storefront\StorefrontLocaleResolver;
use LocalePilot\Storefront\StorefrontTranslator;
use LocalePilot\Translation\TranslationRepository;
use LocalePilot\Translation\TranslationResult;
use PHPUnit\Framework\TestCase;

/**
 * Verifies CartCheckoutCoordinator and OrderLocaleCoordinator behavior and boundaries.
 */
final class CartCheckoutOrderEmailTest extends TestCase {

	/**
	 * Reset in-memory state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		StorefrontLocaleResolver::reset_memory_state();
		TranslationRepository::reset_memory_store();
		unset( $_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] );
	}

	/**
	 * Clean up superglobals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		StorefrontLocaleResolver::reset_memory_state();
		unset( $_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] );
		parent::tearDown();
	}

	/**
	 * Verify Store API (`/wc/store/v1/*`) requests for Cart & Checkout Blocks are recognized
	 * as storefront rendering context, while admin/ERP REST API (`/wc/v3/*`) requests are not.
	 *
	 * @return void
	 */
	public function test_store_api_context_detection_distinguishes_blocks_from_admin_rest(): void {
		$this->assertTrue(
			StorefrontTranslator::is_store_api_request(
				array( 'REQUEST_URI' => '/wp-json/wc/store/v1/cart' ),
				array()
			)
		);
		$this->assertTrue(
			StorefrontTranslator::is_store_api_request(
				array( 'REQUEST_URI' => '/wp-json/wc/store/v1/checkout' ),
				array()
			)
		);
		$this->assertTrue(
			StorefrontTranslator::is_store_api_request(
				array( 'REQUEST_URI' => '/index.php' ),
				array( 'rest_route' => '/wc/store/v1/cart/apply-coupon' )
			)
		);

		// Non-Store-API REST endpoints must NOT be treated as storefront rendering.
		$this->assertFalse(
			StorefrontTranslator::is_store_api_request(
				array( 'REQUEST_URI' => '/wp-json/wc/v3/orders' ),
				array()
			)
		);
		$this->assertFalse(
			StorefrontTranslator::is_store_api_request(
				array( 'REQUEST_URI' => '/wp-json/wp/v2/posts' ),
				array()
			)
		);
	}

	/**
	 * Verify cart item name localization preserves HTML anchor tags, product/variation IDs,
	 * and only uses approved translations.
	 *
	 * @return void
	 */
	public function test_cart_item_name_and_variation_localization_approved_only(): void {
		StorefrontLocaleResolver::update_settings(
			array(
				'default_locale'  => 'en_US',
				'enabled_locales' => array( 'en_US', 'fr_FR', 'de_DE' ),
			)
		);

		$repo       = new TranslationRepository();
		$translator = new StorefrontTranslator( $repo, true );
		$cart_coord = new CartCheckoutCoordinator( $translator );

		$orig_title = 'Merino Wool Base Layer';
		$repo->save(
			array(
				'object_type'     => 'product_title',
				'object_id'       => 801,
				'field_key'       => 'post_title',
				'source_locale'   => 'en_US',
				'target_locale'   => 'fr_FR',
				'source_hash'     => hash( 'sha256', $orig_title ),
				'provider_id'     => 'deepl',
				'translated_text' => 'Sous-vêtement en laine mérinos',
				'review_status'   => TranslationResult::STATUS_APPROVED,
			)
		);
		$repo->save(
			array(
				'object_type'     => 'product_title',
				'object_id'       => 801,
				'field_key'       => 'post_title',
				'source_locale'   => 'en_US',
				'target_locale'   => 'de_DE',
				'source_hash'     => hash( 'sha256', $orig_title ),
				'provider_id'     => 'deepl',
				'translated_text' => 'Merinowolle Funktionsunterwäsche',
				'review_status'   => TranslationResult::STATUS_PENDING_REVIEW,
			)
		);
		$repo->save(
			array(
				'object_type'     => 'variation_option',
				'object_id'       => 0,
				'field_key'       => 'variation_option',
				'source_locale'   => 'en_US',
				'target_locale'   => 'fr_FR',
				'source_hash'     => hash( 'sha256', 'Midnight Blue' ),
				'provider_id'     => 'deepl',
				'translated_text' => 'Bleu nuit',
				'review_status'   => TranslationResult::STATUS_APPROVED,
			)
		);

		$cart_item = array(
			'product_id'   => 801,
			'variation_id' => 0,
			'quantity'     => 2,
			'line_total'   => 158.00,
		);

		// French (`approved`): translates plain name, linked name, and variation option while preserving cart numbers.
		$_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] = 'fr_FR';
		$this->assertSame(
			'Sous-vêtement en laine mérinos',
			$cart_coord->filter_cart_item_name( $orig_title, $cart_item, 'cart_key_1' )
		);
		$this->assertSame(
			'<a href="https://example.test/product/merino/">Sous-vêtement en laine mérinos</a>',
			$cart_coord->filter_cart_item_name(
				'<a href="https://example.test/product/merino/">Merino Wool Base Layer</a>',
				$cart_item,
				'cart_key_1'
			)
		);
		$this->assertSame( 'Bleu nuit', $cart_coord->filter_variation_option_name( 'Midnight Blue' ) );
		$this->assertSame( 2, $cart_item['quantity'] );
		$this->assertSame( 158.00, $cart_item['line_total'] );

		// German (`pending_review`): falls back to original English title.
		$_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] = 'de_DE';
		$this->assertSame(
			$orig_title,
			$cart_coord->filter_cart_item_name( $orig_title, $cart_item, 'cart_key_1' )
		);
	}

	/**
	 * Verify shipping rates and payment gateways translate only display labels/descriptions
	 * while strictly preserving IDs, costs, taxes, and availability.
	 *
	 * @return void
	 */
	public function test_shipping_rates_and_payment_gateways_preserve_costs_taxes_and_ids(): void {
		StorefrontLocaleResolver::update_settings(
			array(
				'default_locale'  => 'en_US',
				'enabled_locales' => array( 'en_US', 'fr_FR', 'de_DE' ),
			)
		);

		$repo       = new TranslationRepository();
		$translator = new StorefrontTranslator( $repo, true );
		$cart_coord = new CartCheckoutCoordinator( $translator );

		// Save custom merchant shipping label and gateway description in `TranslationRepository` (`approved`).
		$repo->save(
			array(
				'object_type'     => 'shipping_method_label',
				'object_id'       => 0,
				'field_key'       => 'label',
				'source_locale'   => 'en_US',
				'target_locale'   => 'fr_FR',
				'source_hash'     => hash( 'sha256', 'Express International Courier' ),
				'provider_id'     => 'deepl',
				'translated_text' => 'Courrier international express',
				'review_status'   => TranslationResult::STATUS_APPROVED,
			)
		);
		$repo->save(
			array(
				'object_type'     => 'payment_gateway_description',
				'object_id'       => 0,
				'field_key'       => 'bacs_description',
				'source_locale'   => 'en_US',
				'target_locale'   => 'fr_FR',
				'source_hash'     => hash( 'sha256', 'Transfer directly to our IBAN account.' ),
				'provider_id'     => 'deepl',
				'translated_text' => 'Virez directement sur notre compte IBAN.',
				'review_status'   => TranslationResult::STATUS_APPROVED,
			)
		);

		// Create mock shipping rate objects (one with setter/getter, one with public property).
		$rate_one = new class() {
			/**
			 * Rate ID.
			 *
			 * @var string
			 */
			private string $id = 'flat_rate:1';

			/**
			 * Rate label.
			 *
			 * @var string
			 */
			private string $label = 'Flat rate';

			/**
			 * Rate cost.
			 *
			 * @var float
			 */
			private float $cost = 12.50;

			/**
			 * Rate taxes.
			 *
			 * @var array<int, float>
			 */
			private array $taxes = array( 1 => 2.50 );

			/**
			 * Return rate ID.
			 *
			 * @return string
			 */
			public function get_id(): string {
				return $this->id;
			}

			/**
			 * Return rate label.
			 *
			 * @return string
			 */
			public function get_label(): string {
				return $this->label;
			}

			/**
			 * Set rate label.
			 *
			 * @param string $label New label.
			 * @return void
			 */
			public function set_label( string $label ): void {
				$this->label = $label;
			}

			/**
			 * Return rate cost.
			 *
			 * @return float
			 */
			public function get_cost(): float {
				return $this->cost;
			}

			/**
			 * Return rate taxes.
			 *
			 * @return array<int, float>
			 */
			public function get_taxes(): array {
				return $this->taxes;
			}
		};

		$rate_two        = new \stdClass();
		$rate_two->id    = 'custom_courier:2';
		$rate_two->label = 'Express International Courier';
		$rate_two->cost  = 29.00;
		$rate_two->taxes = array( 1 => 5.80 );

		$_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] = 'fr_FR';

		$filtered_rates = $cart_coord->filter_package_rates(
			array(
				'flat_rate:1'      => $rate_one,
				'custom_courier:2' => $rate_two,
			),
			array()
		);

		// Labels are translated (via static dictionary for 'Flat rate' and approved DB record for custom courier).
		$this->assertSame( 'Forfait', $filtered_rates['flat_rate:1']->get_label() );
		$this->assertSame( 'Courrier international express', $filtered_rates['custom_courier:2']->label );

		// Rate IDs, costs, and taxes are 100% untouched.
		$this->assertSame( 'flat_rate:1', $filtered_rates['flat_rate:1']->get_id() );
		$this->assertSame( 12.50, $filtered_rates['flat_rate:1']->get_cost() );
		$this->assertSame( array( 1 => 2.50 ), $filtered_rates['flat_rate:1']->get_taxes() );
		$this->assertSame( 'custom_courier:2', $filtered_rates['custom_courier:2']->id );
		$this->assertSame( 29.00, $filtered_rates['custom_courier:2']->cost );
		$this->assertSame( array( 1 => 5.80 ), $filtered_rates['custom_courier:2']->taxes );

		// Payment gateways: title & description localized without mutating gateway ID or availability.
		$gateway_bacs              = new \stdClass();
		$gateway_bacs->id          = 'bacs';
		$gateway_bacs->title       = 'Direct bank transfer';
		$gateway_bacs->description = 'Transfer directly to our IBAN account.';
		$gateway_bacs->enabled     = 'yes';

		$gateways = $cart_coord->filter_available_payment_gateways( array( 'bacs' => $gateway_bacs ) );
		$this->assertSame( 'bacs', $gateways['bacs']->id );
		$this->assertSame( 'yes', $gateways['bacs']->enabled );
		$this->assertSame( 'Virement bancaire', $gateways['bacs']->title );
		$this->assertSame( 'Virez directement sur notre compte IBAN.', $gateways['bacs']->description );
	}

	/**
	 * Verify coupon messages, checkout fields, and checkout validation errors are localized
	 * in approved/safe-dictionary mode.
	 *
	 * @return void
	 */
	public function test_coupon_messages_checkout_fields_and_validation_notices(): void {
		StorefrontLocaleResolver::update_settings(
			array(
				'default_locale'  => 'en_US',
				'enabled_locales' => array( 'en_US', 'fr_FR', 'de_DE' ),
			)
		);

		$repo       = new TranslationRepository();
		$translator = new StorefrontTranslator( $repo, true );
		$cart_coord = new CartCheckoutCoordinator( $translator );

		$_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] = 'fr_FR';

		// Coupon messages & errors (including %s placeholder and WC 11.2+ message variants).
		$this->assertSame(
			'Code promo appliqué avec succès.',
			$cart_coord->filter_coupon_message( 'Coupon code applied successfully.', 200, null )
		);
		$this->assertSame(
			'Le code promo « WINTER10 » n’existe pas !',
			$cart_coord->filter_coupon_error( 'Coupon "WINTER10" does not exist!', 105, null )
		);
		$this->assertSame(
			'Le code promo « WINTER10 » n’existe pas !',
			$cart_coord->filter_coupon_error( 'Coupon "WINTER10" cannot be applied because it does not exist.', 105, null )
		);
		$this->assertSame(
			'Le code promo « WINTER10 » n’existe pas !',
			$cart_coord->filter_coupon_error( '"WINTER10" is an invalid coupon code.', 105, null )
		);
		$this->assertSame(
			'Le code promo « WINTER10 » a expiré.',
			$cart_coord->filter_coupon_error( 'Coupon "WINTER10" has expired.', 107, null )
		);
		$this->assertSame(
			'wc_ship_hash_123_lp_fr_FR',
			$cart_coord->filter_shipping_package_hash( 'wc_ship_hash_123' )
		);

		// Checkout fields.
		$fields   = array(
			'billing' => array(
				'billing_first_name' => array(
					'label'    => 'First name',
					'required' => true,
				),
				'billing_email'      => array(
					'label'    => 'Email address',
					'required' => true,
				),
			),
			'order'   => array(
				'order_comments' => array(
					'label'       => 'Order notes',
					'placeholder' => 'Notes about your order, e.g. special notes for delivery.',
				),
			),
		);
		$filtered = $cart_coord->filter_checkout_fields( $fields );
		$this->assertSame( 'Prénom', $filtered['billing']['billing_first_name']['label'] );
		$this->assertTrue( $filtered['billing']['billing_first_name']['required'] );
		$this->assertSame( 'Adresse e-mail', $filtered['billing']['billing_email']['label'] );
		$this->assertSame( 'Notes de commande', $filtered['order']['order_comments']['label'] );
		$this->assertSame(
			'Commentaires concernant votre commande, ex. : consignes de livraison.',
			$filtered['order']['order_comments']['placeholder']
		);

		// Checkout required-field validation notice (`<strong>Billing First name</strong> is a required field.`).
		$this->assertSame(
			'<strong>Facturation Prénom</strong> est un champ obligatoire.',
			$cart_coord->filter_woocommerce_error_message( '<strong>Billing First name</strong> is a required field.' )
		);
		$this->assertSame(
			'Commander',
			$cart_coord->filter_order_button_text( 'Place order' )
		);
		$this->assertSame(
			'Merci. Votre commande a été reçue.',
			$cart_coord->filter_thankyou_order_received_text( 'Thank you. Your order has been received.', null )
		);
	}

	/**
	 * Verify OrderLocaleCoordinator snapshots `_localepilot_order_locale` on Classic and Store API
	 * checkout without modifying order amounts/status, and switches/restores locale during order emails.
	 *
	 * @return void
	 */
	public function test_order_locale_snapshot_and_transactional_email_locale_switching(): void {
		StorefrontLocaleResolver::update_settings(
			array(
				'default_locale'  => 'en_US',
				'enabled_locales' => array( 'en_US', 'fr_FR', 'de_DE' ),
			)
		);

		$repo        = new TranslationRepository();
		$translator  = new StorefrontTranslator( $repo, false );
		$order_coord = new OrderLocaleCoordinator( $translator );

		$orig_title = 'Carbon Trekking Poles';
		$repo->save(
			array(
				'object_type'     => 'product_title',
				'object_id'       => 905,
				'field_key'       => 'post_title',
				'source_locale'   => 'en_US',
				'target_locale'   => 'de_DE',
				'source_hash'     => hash( 'sha256', $orig_title ),
				'provider_id'     => 'deepl',
				'translated_text' => 'Carbon-Trekkingstöcke',
				'review_status'   => TranslationResult::STATUS_APPROVED,
			)
		);

		// Create HPOS-compatible mock WC_Order object.
		$order = new class() {
			/**
			 * Order ID.
			 *
			 * @var int
			 */
			private int $id = 5001;

			/**
			 * Order total.
			 *
			 * @var float
			 */
			private float $total = 129.90;

			/**
			 * Order currency.
			 *
			 * @var string
			 */
			private string $currency = 'EUR';

			/**
			 * Order status.
			 *
			 * @var string
			 */
			private string $status = 'processing';

			/**
			 * Order metadata store.
			 *
			 * @var array<string, string>
			 */
			private array $meta_store = array();

			/**
			 * Whether save() was invoked.
			 *
			 * @var bool
			 */
			public bool $saved = false;

			/**
			 * Return order ID.
			 *
			 * @return int
			 */
			public function get_id(): int {
				return $this->id;
			}

			/**
			 * Return order total.
			 *
			 * @return float
			 */
			public function get_total(): float {
				return $this->total;
			}

			/**
			 * Return order currency.
			 *
			 * @return string
			 */
			public function get_currency(): string {
				return $this->currency;
			}

			/**
			 * Return order status.
			 *
			 * @return string
			 */
			public function get_status(): string {
				return $this->status;
			}

			/**
			 * Update order meta value.
			 *
			 * @param string $key   Meta key.
			 * @param mixed  $value Meta value.
			 * @return void
			 */
			public function update_meta_data( string $key, $value ): void {
				$this->meta_store[ $key ] = (string) $value;
			}

			/**
			 * Read order meta value.
			 *
			 * @param string $key    Meta key.
			 * @param bool   $single Whether to return a single value.
			 * @return string
			 */
			public function get_meta( string $key, bool $single = true ): string {
				unset( $single );
				return $this->meta_store[ $key ] ?? '';
			}

			/**
			 * Persist order changes.
			 *
			 * @return void
			 */
			public function save(): void {
				$this->saved = true;
			}
		};

		// 1. Visitor places order via Store API / Checkout Blocks while in `de_DE`.
		$_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] = 'de_DE';
		$order_coord->capture_store_api_order_locale( $order );

		$this->assertSame( 'de_DE', $order->get_meta( Compatibility::ORDER_LOCALE_META_KEY, true ) );
		$this->assertSame( 'de_DE', OrderLocaleCoordinator::get_order_locale( $order ) );
		$this->assertTrue( $order->saved );

		// Order totals, currency, and status remain untouched.
		$this->assertSame( 129.90, $order->get_total() );
		$this->assertSame( 'EUR', $order->get_currency() );
		$this->assertSame( 'processing', $order->get_status() );

		// 2. Later, a backend admin action (where cookie is `en_US` and `force_storefront_context` is false) triggers order email.
		$_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] = 'en_US';

		$order_item = new class() {
			/**
			 * Return product ID.
			 *
			 * @return int
			 */
			public function get_product_id(): int {
				return 905;
			}

			/**
			 * Return variation ID.
			 *
			 * @return int
			 */
			public function get_variation_id(): int {
				return 0;
			}
		};

		// Before email hook starts: admin context does not translate item name.
		$this->assertFalse( $order_coord->is_email_locale_active() );
		$this->assertSame( $orig_title, $order_coord->filter_order_item_name( $orig_title, $order_item ) );

		// During `woocommerce_email_before_order_table`: switches runtime locale to order snapshot `de_DE`.
		$order_coord->begin_order_email_locale( $order, false, false, null );
		$this->assertTrue( $order_coord->is_email_locale_active() );
		$this->assertSame( 'de_DE', $order_coord->get_active_email_locale() );
		$this->assertSame( 'de_DE', StorefrontLocaleResolver::resolve_visitor_locale() );
		$this->assertSame( 'Carbon-Trekkingstöcke', $order_coord->filter_order_item_name( $orig_title, $order_item ) );

		// After `woocommerce_email_after_order_table`: restores runtime locale and email state.
		$order_coord->end_order_email_locale( $order, false, false, null );
		$this->assertFalse( $order_coord->is_email_locale_active() );
		$this->assertNull( StorefrontLocaleResolver::get_runtime_locale_override() );
		$this->assertSame( 'en_US', StorefrontLocaleResolver::resolve_visitor_locale() );
		$this->assertSame( $orig_title, $order_coord->filter_order_item_name( $orig_title, $order_item ) );
	}
}
