<?php
/**
 * WooCommerce Cart & Checkout Multilingual Coordinator (Classic & Store API / Blocks).
 *
 * Preserves the active same-URL visitor language (`lp_lang`) across:
 * - Cart & Mini-Cart item names and variation option names
 * - `wc-ajax` cart/checkout fragment refreshes and `Store API` (`/wc/store/v1/*`) requests
 * - Coupon success and error messages
 * - Checkout field labels/placeholders and validation notices
 * - Shipping method rate labels (`WC_Shipping_Rate`)
 * - Payment gateway titles and descriptions
 * - Order-received (thank-you) page message
 *
 * Strictly guarantees:
 * - Only locally stored `approved` translations or built-in safe static UI strings are rendered;
 *   `pending_review` or missing translations fall back to original store text.
 * - Zero outbound translation API calls on the storefront.
 * - Zero mutation of product prices, cart subtotals, coupon discount amounts,
 *   tax calculations, shipping costs/zones, currency codes, customer shipping/billing
 *   addresses, payment gateway availability/processing, order statuses, or stock levels.
 *
 * @package LocalePilot\Storefront
 */

declare(strict_types=1);

namespace LocalePilot\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates WooCommerce cart, shipping, payment gateway, coupon, and checkout filters.
 */
final class CartCheckoutCoordinator {

	/**
	 * Safe static UI dictionary for standard WooCommerce cart, checkout, coupon,
	 * shipping, and payment strings when no merchant override exists in DB.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const STATIC_UI_DICTIONARY = array(
		'Flat rate'                                => array(
			'fr_FR' => 'Forfait',
			'de_DE' => 'Pauschale Versandkosten',
			'es_ES' => 'Precio fijo',
			'ja_JP' => '定額送料',
			'zh_CN' => '统一运费',
		),
		'Free shipping'                            => array(
			'fr_FR' => 'Livraison gratuite',
			'de_DE' => 'Kostenlose Lieferung',
			'es_ES' => 'Envío gratuito',
			'ja_JP' => '送料無料',
			'zh_CN' => '免费配送',
		),
		'Local pickup'                             => array(
			'fr_FR' => 'Point de vente',
			'de_DE' => 'Abholung vor Ort',
			'es_ES' => 'Recogida local',
			'ja_JP' => '店頭受取',
			'zh_CN' => '本地自提',
		),
		'Direct bank transfer'                     => array(
			'fr_FR' => 'Virement bancaire',
			'de_DE' => 'Direkte Banküberweisung',
			'es_ES' => 'Transferencia bancaria directa',
			'ja_JP' => '銀行振込',
			'zh_CN' => '银行汇款（线下转账）',
		),
		'Cash on delivery'                         => array(
			'fr_FR' => 'Paiement à la livraison',
			'de_DE' => 'Nachnahme',
			'es_ES' => 'Contra reembolso',
			'ja_JP' => '代金引換',
			'zh_CN' => '货到付款',
		),
		'Pay with cash upon delivery.'             => array(
			'fr_FR' => 'Payer en espèces à la livraison.',
			'de_DE' => 'Bezahlen Sie bar bei Lieferung.',
			'es_ES' => 'Paga en efectivo al momento de la entrega.',
			'ja_JP' => '商品お届け時に現金でお支払いください。',
			'zh_CN' => '收到商品时用现金付款。',
		),
		'Check payments'                           => array(
			'fr_FR' => 'Paiements par chèque',
			'de_DE' => 'Scheckzahlung',
			'es_ES' => 'Pagos por cheque',
			'ja_JP' => '小切手支払い',
			'zh_CN' => '支票付款',
		),
		'Coupon code applied successfully.'        => array(
			'fr_FR' => 'Code promo appliqué avec succès.',
			'de_DE' => 'Gutscheincode erfolgreich angewendet.',
			'es_ES' => 'Código de cupón aplicado con éxito.',
			'ja_JP' => 'クーポンコードが正常に適用されました。',
			'zh_CN' => '优惠券代码已成功应用。',
		),
		'Coupon code removed successfully.'        => array(
			'fr_FR' => 'Code promo supprimé avec succès.',
			'de_DE' => 'Gutscheincode erfolgreich entfernt.',
			'es_ES' => 'Código de cupón eliminado con éxito.',
			'ja_JP' => 'クーポンコードが削除されました。',
			'zh_CN' => '优惠券代码已成功移除。',
		),
		'Please enter a coupon code.'              => array(
			'fr_FR' => 'Veuillez saisir un code promo.',
			'de_DE' => 'Bitte geben Sie einen Gutscheincode ein.',
			'es_ES' => 'Por favor, introduce un código de cupón.',
			'ja_JP' => 'クーポンコードを入力してください。',
			'zh_CN' => '请输入优惠券代码。',
		),
		'This coupon has expired.'                 => array(
			'fr_FR' => 'Ce code promo a expiré.',
			'de_DE' => 'Dieser Gutschein ist abgelaufen.',
			'es_ES' => 'Este cupón ha caducado.',
			'ja_JP' => 'このクーポンは有効期限が切れています。',
			'zh_CN' => '此优惠券已过期。',
		),
		'Coupon does not exist.'                   => array(
			'fr_FR' => 'Ce code promo n’existe pas.',
			'de_DE' => 'Dieser Gutschein existiert nicht.',
			'es_ES' => 'El cupón no existe.',
			'ja_JP' => 'クーポンは存在しません。',
			'zh_CN' => '优惠券不存在。',
		),
		'First name'                               => array(
			'fr_FR' => 'Prénom',
			'de_DE' => 'Vorname',
			'es_ES' => 'Nombre',
			'ja_JP' => '名',
			'zh_CN' => '名字',
		),
		'Last name'                                => array(
			'fr_FR' => 'Nom',
			'de_DE' => 'Nachname',
			'es_ES' => 'Apellidos',
			'ja_JP' => '姓',
			'zh_CN' => '姓氏',
		),
		'Company name'                             => array(
			'fr_FR' => 'Nom de l’entreprise',
			'de_DE' => 'Firmenname',
			'es_ES' => 'Nombre de la empresa',
			'ja_JP' => '会社名',
			'zh_CN' => '公司名称',
		),
		'Country / Region'                         => array(
			'fr_FR' => 'Pays / Région',
			'de_DE' => 'Land / Region',
			'es_ES' => 'País / Región',
			'ja_JP' => '国 / 地域',
			'zh_CN' => '国家 / 地区',
		),
		'Street address'                           => array(
			'fr_FR' => 'Numéro et nom de rue',
			'de_DE' => 'Straße und Hausnummer',
			'es_ES' => 'Dirección de la calle',
			'ja_JP' => '番地・建物名',
			'zh_CN' => '街道地址',
		),
		'Town / City'                              => array(
			'fr_FR' => 'Ville',
			'de_DE' => 'Ort / Stadt',
			'es_ES' => 'Población',
			'ja_JP' => '市区町村',
			'zh_CN' => '城镇 / 城市',
		),
		'State / County'                           => array(
			'fr_FR' => 'État / Comté',
			'de_DE' => 'Bundesland / Landkreis',
			'es_ES' => 'Provincia',
			'ja_JP' => '都道府県',
			'zh_CN' => '省 / 州',
		),
		'Postcode / ZIP'                           => array(
			'fr_FR' => 'Code postal',
			'de_DE' => 'Postleitzahl',
			'es_ES' => 'Código postal',
			'ja_JP' => '郵便番号',
			'zh_CN' => '邮政编码',
		),
		'Phone'                                    => array(
			'fr_FR' => 'Téléphone',
			'de_DE' => 'Telefon',
			'es_ES' => 'Teléfono',
			'ja_JP' => '電話番号',
			'zh_CN' => '电话',
		),
		'Email address'                            => array(
			'fr_FR' => 'Adresse e-mail',
			'de_DE' => 'E-Mail-Adresse',
			'es_ES' => 'Dirección de correo electrónico',
			'ja_JP' => 'メールアドレス',
			'zh_CN' => '电子邮箱地址',
		),
		'Order notes'                              => array(
			'fr_FR' => 'Notes de commande',
			'de_DE' => 'Bestellhinweise',
			'es_ES' => 'Notas del pedido',
			'ja_JP' => '注文メモ',
			'zh_CN' => '订单备注',
		),
		'Notes about your order, e.g. special notes for delivery.' => array(
			'fr_FR' => 'Commentaires concernant votre commande, ex. : consignes de livraison.',
			'de_DE' => 'Anmerkungen zu Ihrer Bestellung, z. B. besondere Hinweise für die Lieferung.',
			'es_ES' => 'Notas sobre tu pedido, por ejemplo, notas especiales para la entrega.',
			'ja_JP' => '配達に関する特記事項など、ご注文に関するメモ。',
			'zh_CN' => '有关您订单的备注，例如配送特殊说明。',
		),
		'Place order'                              => array(
			'fr_FR' => 'Commander',
			'de_DE' => 'kostenpflichtig bestellen',
			'es_ES' => 'Realizar el pedido',
			'ja_JP' => '注文する',
			'zh_CN' => '下单',
		),
		'Thank you. Your order has been received.' => array(
			'fr_FR' => 'Merci. Votre commande a été reçue.',
			'de_DE' => 'Vielen Dank. Ihre Bestellung ist eingegangen.',
			'es_ES' => 'Gracias. Tu pedido ha sido recibido.',
			'ja_JP' => 'ありがとうございます。ご注文を承りました。',
			'zh_CN' => '谢谢。您的订单已收到。',
		),
	);

	/**
	 * Storefront translator instance.
	 *
	 * @var StorefrontTranslator
	 */
	private StorefrontTranslator $translator;

	/**
	 * Construct the cart & checkout coordinator.
	 *
	 * @param StorefrontTranslator|null $translator Optional storefront translator.
	 */
	public function __construct( ?StorefrontTranslator $translator = null ) {
		$this->translator = $translator ?? new StorefrontTranslator();
	}

	/**
	 * Register WooCommerce cart, shipping, gateway, coupon, and checkout filters.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		// Cart item & variation option display text.
		add_filter( 'woocommerce_cart_item_name', array( $this, 'filter_cart_item_name' ), 20, 3 );
		add_filter( 'woocommerce_variation_option_name', array( $this, 'filter_variation_option_name' ), 20, 4 );

		// Shipping package rates (label only; costs, taxes, and IDs untouched) and session hash locale key.
		add_filter( 'woocommerce_package_rates', array( $this, 'filter_shipping_package_rates' ), 20, 2 );
		add_filter( 'woocommerce_shipping_package_hash', array( $this, 'filter_shipping_package_hash' ), 20, 1 );

		// Payment gateway titles and descriptions (display text only; processing untouched).
		add_filter( 'woocommerce_gateway_title', array( $this, 'filter_gateway_title' ), 20, 2 );
		add_filter( 'woocommerce_gateway_description', array( $this, 'filter_gateway_description' ), 20, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_available_payment_gateways' ), 20, 1 );

		// Coupon messages and errors.
		add_filter( 'woocommerce_coupon_message', array( $this, 'filter_coupon_message' ), 20, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'filter_coupon_error' ), 20, 3 );

		// Checkout field labels & placeholders (Classic & Store API default address schema).
		add_filter( 'woocommerce_checkout_fields', array( $this, 'filter_checkout_fields' ), 20, 1 );
		add_filter( 'woocommerce_default_address_fields', array( $this, 'filter_default_address_fields' ), 20, 1 );
		add_filter( 'woocommerce_order_button_text', array( $this, 'filter_order_button_text' ), 20, 1 );

		// WooCommerce notices & validation messages.
		add_filter( 'woocommerce_add_error', array( $this, 'filter_wc_notice' ), 20, 1 );
		add_filter( 'woocommerce_add_message', array( $this, 'filter_wc_notice' ), 20, 1 );
		add_filter( 'woocommerce_add_notice', array( $this, 'filter_wc_notice' ), 20, 1 );

		// Order-received (thank-you) page text.
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'filter_order_received_text' ), 20, 2 );
	}

	/**
	 * Filter cart item display name while preserving any surrounding HTML anchor tag
	 * and leaving cart item quantity, price, and line totals untouched.
	 *
	 * @param mixed $product_name  Cart item display name or HTML link.
	 * @param mixed $cart_item     Cart item array.
	 * @param mixed $cart_item_key Cart item key.
	 * @return string
	 */
	public function filter_cart_item_name( $product_name, $cart_item = array(), $cart_item_key = '' ): string {
		unset( $cart_item_key );
		$original = is_string( $product_name ) ? $product_name : '';
		if ( '' === trim( $original ) || ! $this->translator->is_storefront_context() ) {
			return $original;
		}

		$product_id = 0;
		if ( is_array( $cart_item ) ) {
			if ( ! empty( $cart_item['variation_id'] ) ) {
				$product_id = (int) $cart_item['variation_id'];
			} elseif ( ! empty( $cart_item['product_id'] ) ) {
				$product_id = (int) $cart_item['product_id'];
			} elseif ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_id' ) ) {
				$product_id = (int) $cart_item['data']->get_id();
			}
		}

		// If the cart item name is wrapped in an `<a ...>Inner Title</a>` tag, translate only the inner title.
		if ( preg_match( '/^(<a\b[^>]*>)(.*?)(<\/a>)$/is', $original, $matches ) ) {
			$inner_original   = $matches[2];
			$inner_translated = $this->translator->resolve_approved_text( 'product_title', $product_id, 'post_title', $inner_original );
			return $matches[1] . $inner_translated . $matches[3];
		}

		return $this->translator->resolve_approved_text( 'product_title', $product_id, 'post_title', $original );
	}

	/**
	 * Filter variation attribute option display text on product, cart, and checkout views.
	 *
	 * @param mixed $term_name Option display name.
	 * @param mixed $term      Optional WP_Term object.
	 * @param mixed $attribute Optional attribute taxonomy/name.
	 * @param mixed $product   Optional WC_Product.
	 * @return string
	 */
	public function filter_variation_option_name( $term_name, $term = null, $attribute = '', $product = null ): string {
		unset( $product );
		$original = is_string( $term_name ) ? $term_name : '';
		if ( '' === trim( $original ) || ! $this->translator->is_storefront_context() ) {
			return $original;
		}

		if ( is_object( $term ) && isset( $term->term_id ) ) {
			$by_term = $this->translator->resolve_approved_text( 'taxonomy_term', (int) $term->term_id, 'name', $original );
			if ( $by_term !== $original ) {
				return $by_term;
			}
		}

		$field_key = is_string( $attribute ) && '' !== trim( $attribute ) ? trim( $attribute ) : 'variation_option';
		return $this->translator->resolve_approved_text( 'variation_option', 0, $field_key, $original );
	}

	/**
	 * Filter WooCommerce shipping package rate labels using approved local translations
	 * or safe static UI dictionary entries.
	 *
	 * Strictly preserves rate IDs, method IDs, instance IDs, shipping costs, and tax arrays.
	 *
	 * @param mixed $rates   Array of `WC_Shipping_Rate` objects.
	 * @param mixed $package Shipping package array.
	 * @return mixed
	 */
	public function filter_shipping_package_rates( $rates, $package = array() ) {
		unset( $package );
		if ( ! is_array( $rates ) || empty( $rates ) || ! $this->translator->is_storefront_context() ) {
			return $rates;
		}

		foreach ( $rates as $rate_key => $rate ) {
			if ( ! is_object( $rate ) ) {
				continue;
			}

			$original_label = '';
			if ( method_exists( $rate, 'get_label' ) ) {
				$original_label = (string) $rate->get_label();
			} elseif ( isset( $rate->label ) && is_string( $rate->label ) ) {
				$original_label = $rate->label;
			}

			if ( '' === trim( $original_label ) ) {
				continue;
			}

			$rate_id = method_exists( $rate, 'get_id' )
				? (string) $rate->get_id()
				: ( isset( $rate->id ) ? (string) $rate->id : (string) $rate_key );

			$translated = $this->resolve_store_text(
				'shipping_method_label',
				'' !== $rate_id ? $rate_id : 'shipping_rate',
				$original_label
			);

			if ( $translated !== $original_label ) {
				if ( method_exists( $rate, 'set_label' ) ) {
					$rate->set_label( $translated );
				} elseif ( isset( $rate->label ) ) {
					$rate->label = $translated;
				}
			}
		}

		return $rates;
	}

	/**
	 * Alias for `filter_shipping_package_rates()` (`woocommerce_package_rates`).
	 *
	 * @param mixed $rates   Array of shipping rate objects.
	 * @param mixed $package Shipping package array.
	 * @return mixed
	 */
	public function filter_package_rates( $rates, $package = array() ) {
		return $this->filter_shipping_package_rates( $rates, $package );
	}

	/**
	 * Append the active storefront locale to `woocommerce_shipping_package_hash` so
	 * `WC_Shipping` re-evaluates rate display labels when a visitor switches `lp_lang`,
	 * while keeping shipping costs, taxes, and rate IDs identical.
	 *
	 * @param mixed $hash Existing shipping package hash.
	 * @return string
	 */
	public function filter_shipping_package_hash( $hash ): string {
		$original = is_string( $hash ) ? $hash : '';
		if ( '' === $original || ! $this->translator->is_storefront_context() ) {
			return $original;
		}

		$active_locale = StorefrontLocaleResolver::resolve_visitor_locale();
		return '' !== $active_locale ? $original . '_lp_' . $active_locale : $original;
	}

	/**
	 * Filter payment gateway title on Classic Checkout and Store API / Checkout Blocks.
	 *
	 * Never modifies gateway ID, availability, or payment processing.
	 *
	 * @param mixed $title      Original gateway title.
	 * @param mixed $gateway_id Gateway ID string (e.g. `bacs`, `cod`, `cheque`).
	 * @return string
	 */
	public function filter_gateway_title( $title, $gateway_id = '' ): string {
		$original = is_string( $title ) ? $title : '';
		if ( '' === trim( $original ) || ! $this->translator->is_storefront_context() ) {
			return $original;
		}

		$field_key = is_string( $gateway_id ) && '' !== trim( $gateway_id ) ? trim( $gateway_id ) : 'gateway_title';
		return $this->resolve_store_text( 'payment_gateway_title', $field_key, $original );
	}

	/**
	 * Alias for `filter_gateway_title()`.
	 *
	 * @param mixed $title      Original gateway title.
	 * @param mixed $gateway_id Gateway ID string.
	 * @return string
	 */
	public function filter_payment_gateway_title( $title, $gateway_id = '' ): string {
		return $this->filter_gateway_title( $title, $gateway_id );
	}

	/**
	 * Filter payment gateway description on Classic Checkout and Store API / Checkout Blocks.
	 *
	 * @param mixed $description Original gateway description.
	 * @param mixed $gateway_id  Gateway ID string.
	 * @return string
	 */
	public function filter_gateway_description( $description, $gateway_id = '' ): string {
		$original = is_string( $description ) ? $description : '';
		if ( '' === trim( $original ) || ! $this->translator->is_storefront_context() ) {
			return $original;
		}

		$field_key = is_string( $gateway_id ) && '' !== trim( $gateway_id ) ? trim( $gateway_id ) : 'gateway_description';
		return $this->resolve_store_text( 'payment_gateway_description', $field_key, $original );
	}

	/**
	 * Alias for `filter_gateway_description()`.
	 *
	 * @param mixed $description Original gateway description.
	 * @param mixed $gateway_id  Gateway ID string.
	 * @return string
	 */
	public function filter_payment_gateway_description( $description, $gateway_id = '' ): string {
		return $this->filter_gateway_description( $description, $gateway_id );
	}

	/**
	 * Filter available payment gateways array (`woocommerce_available_payment_gateways`)
	 * so both Classic Checkout and Store API `/wc/store/v1/checkout` receive localized
	 * gateway `title` and `description` properties while preserving gateway IDs and availability.
	 *
	 * @param mixed $gateways Associative array of payment gateway objects.
	 * @return mixed
	 */
	public function filter_available_payment_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || empty( $gateways ) || ! $this->translator->is_storefront_context() ) {
			return $gateways;
		}

		foreach ( $gateways as $gateway_key => $gateway ) {
			if ( ! is_object( $gateway ) ) {
				continue;
			}

			$gateway_id = isset( $gateway->id ) && is_string( $gateway->id ) ? $gateway->id : (string) $gateway_key;

			if ( isset( $gateway->title ) && is_string( $gateway->title ) && '' !== trim( $gateway->title ) ) {
				$gateway->title = $this->filter_gateway_title( $gateway->title, $gateway_id );
			}

			if ( isset( $gateway->description ) && is_string( $gateway->description ) && '' !== trim( $gateway->description ) ) {
				$gateway->description = $this->filter_gateway_description( $gateway->description, $gateway_id );
			}
		}

		return $gateways;
	}

	/**
	 * Filter WooCommerce coupon success message using approved local translations
	 * or safe static UI dictionary entries.
	 *
	 * @param mixed $msg      Coupon message text.
	 * @param mixed $msg_code Message code integer.
	 * @param mixed $coupon   WC_Coupon object.
	 * @return string
	 */
	public function filter_coupon_message( $msg, $msg_code = null, $coupon = null ): string {
		unset( $coupon );
		$original = is_string( $msg ) ? $msg : '';
		if ( '' === trim( $original ) || ! $this->translator->is_storefront_context() ) {
			return $original;
		}

		$field_key = null !== $msg_code ? 'coupon_msg_' . (string) $msg_code : 'coupon_message';
		return $this->resolve_store_text( 'coupon_message', $field_key, $original );
	}

	/**
	 * Filter WooCommerce coupon error message using approved local translations
	 * or safe static UI dictionary entries (including coupon code placeholders).
	 *
	 * @param mixed $err      Coupon error text.
	 * @param mixed $err_code Error code integer.
	 * @param mixed $coupon   WC_Coupon object.
	 * @return string
	 */
	public function filter_coupon_error( $err, $err_code = null, $coupon = null ): string {
		unset( $coupon );
		$original = is_string( $err ) ? $err : '';
		if ( '' === trim( $original ) || ! $this->translator->is_storefront_context() ) {
			return $original;
		}

		$field_key  = null !== $err_code ? 'coupon_err_' . (string) $err_code : 'coupon_error';
		$translated = $this->resolve_store_text( 'coupon_message', $field_key, $original );
		if ( $translated !== $original ) {
			return $translated;
		}

		// Pattern match standard WooCommerce `Coupon "%s" does not exist!` / `Coupon "%s" cannot be applied because it does not exist.` / `"%s" is an invalid coupon code.` errors while preserving coupon code.
		if (
			preg_match( '/^Coupon\s+(?:"|&quot;|&#039;|\')([^"\'&]+)(?:"|&quot;|&#039;|\')\s+(?:does not exist!|cannot be applied because it does not exist\.)$/i', trim( $original ), $matches )
			|| preg_match( '/^(?:"|&quot;|&#039;|\')([^"\'&]+)(?:"|&quot;|&#039;|\')\s+is an invalid coupon code\.$/i', trim( $original ), $matches )
		) {
			$code          = $matches[1];
			$active_locale = StorefrontLocaleResolver::resolve_visitor_locale();
			$templates     = array(
				'fr_FR' => 'Le code promo « %s » n’existe pas !',
				'de_DE' => 'Der Gutschein „%s“ existiert nicht!',
				'es_ES' => '¡El cupón «%s» no existe!',
				'ja_JP' => 'クーポン「%s」は存在しません。',
				'zh_CN' => '优惠券“%s”不存在！',
			);
			if ( isset( $templates[ $active_locale ] ) ) {
				return sprintf( $templates[ $active_locale ], $code );
			}
		}

		if ( preg_match( '/^Coupon\s+(?:"|&quot;|&#039;|\')([^"\'&]+)(?:"|&quot;|&#039;|\')\s+has expired\.$/i', trim( $original ), $matches ) ) {
			$code          = $matches[1];
			$active_locale = StorefrontLocaleResolver::resolve_visitor_locale();
			$templates     = array(
				'fr_FR' => 'Le code promo « %s » a expiré.',
				'de_DE' => 'Der Gutschein „%s“ ist abgelaufen.',
				'es_ES' => 'El cupón «%s» ha caducado.',
				'ja_JP' => 'クーポン「%s」は有効期限が切れています。',
				'zh_CN' => '优惠券“%s”已过期。',
			);
			if ( isset( $templates[ $active_locale ] ) ) {
				return sprintf( $templates[ $active_locale ], $code );
			}
		}

		return $original;
	}

	/**
	 * Filter WooCommerce checkout fields (`billing`, `shipping`, `account`, `order`),
	 * translating only `label` and `placeholder` strings while keeping field keys,
	 * `required` rules, validation callbacks, and customer values intact.
	 *
	 * @param mixed $fields Checkout fields array.
	 * @return mixed
	 */
	public function filter_checkout_fields( $fields ) {
		if ( ! is_array( $fields ) || ! $this->translator->is_storefront_context() ) {
			return $fields;
		}

		foreach ( $fields as $group_key => $group_fields ) {
			if ( ! is_array( $group_fields ) ) {
				continue;
			}

			foreach ( $group_fields as $field_key => $field_cfg ) {
				if ( ! is_array( $field_cfg ) ) {
					continue;
				}

				if ( isset( $field_cfg['label'] ) && is_string( $field_cfg['label'] ) && '' !== trim( $field_cfg['label'] ) ) {
					$fields[ $group_key ][ $field_key ]['label'] = $this->resolve_store_text(
						'checkout_field_label',
						(string) $field_key . '_label',
						$field_cfg['label']
					);
				}

				if ( isset( $field_cfg['placeholder'] ) && is_string( $field_cfg['placeholder'] ) && '' !== trim( $field_cfg['placeholder'] ) ) {
					$fields[ $group_key ][ $field_key ]['placeholder'] = $this->resolve_store_text(
						'checkout_field_label',
						(string) $field_key . '_placeholder',
						$field_cfg['placeholder']
					);
				}
			}
		}

		return $fields;
	}

	/**
	 * Filter WooCommerce default address fields (used by both Classic Checkout and Blocks).
	 *
	 * @param mixed $fields Default address fields array.
	 * @return mixed
	 */
	public function filter_default_address_fields( $fields ) {
		if ( ! is_array( $fields ) || ! $this->translator->is_storefront_context() ) {
			return $fields;
		}

		foreach ( $fields as $field_key => $field_cfg ) {
			if ( ! is_array( $field_cfg ) ) {
				continue;
			}

			if ( isset( $field_cfg['label'] ) && is_string( $field_cfg['label'] ) && '' !== trim( $field_cfg['label'] ) ) {
				$fields[ $field_key ]['label'] = $this->resolve_store_text(
					'checkout_field_label',
					(string) $field_key . '_label',
					$field_cfg['label']
				);
			}

			if ( isset( $field_cfg['placeholder'] ) && is_string( $field_cfg['placeholder'] ) && '' !== trim( $field_cfg['placeholder'] ) ) {
				$fields[ $field_key ]['placeholder'] = $this->resolve_store_text(
					'checkout_field_label',
					(string) $field_key . '_placeholder',
					$field_cfg['placeholder']
				);
			}
		}

		return $fields;
	}

	/**
	 * Filter checkout "Place order" submit button text (`woocommerce_order_button_text`).
	 *
	 * @param mixed $button_text Original button text.
	 * @return string
	 */
	public function filter_order_button_text( $button_text ): string {
		$original = is_string( $button_text ) ? $button_text : '';
		if ( '' === trim( $original ) || ! $this->translator->is_storefront_context() ) {
			return $original;
		}

		return $this->resolve_store_text( 'ui_string', 'order_button_text', $original );
	}

	/**
	 * Filter WooCommerce validation/error/info notices using approved local `ui_string`
	 * translations or safe static validation patterns.
	 *
	 * @param mixed $message Notice message string.
	 * @return string
	 */
	public function filter_wc_notice( $message ): string {
		$original = is_string( $message ) ? $message : '';
		if ( '' === trim( $original ) || ! $this->translator->is_storefront_context() ) {
			return $original;
		}

		$translated = $this->resolve_store_text( 'ui_string', 'wc_notice', $original );
		if ( $translated !== $original ) {
			return $translated;
		}

		// Pattern match `<strong>Billing First name</strong> is a required field.` validation notice.
		if ( preg_match( '/^<strong>(.*?)<\/strong>\s+is a required field\.$/i', trim( $original ), $matches ) ) {
			$raw_field_label = trim( $matches[1] );
			$active_locale   = StorefrontLocaleResolver::resolve_visitor_locale();
			$localized_label = $this->localize_validation_field_label( $raw_field_label, $active_locale );

			$required_templates = array(
				'fr_FR' => '<strong>%s</strong> est un champ obligatoire.',
				'de_DE' => '<strong>%s</strong> ist ein Pflichtfeld.',
				'es_ES' => '<strong>%s</strong> es un campo obligatorio.',
				'ja_JP' => '<strong>%s</strong> は必須項目です。',
				'zh_CN' => '<strong>%s</strong> 是必填字段。',
			);
			if ( isset( $required_templates[ $active_locale ] ) ) {
				return sprintf( $required_templates[ $active_locale ], $localized_label );
			}
		}

		return $original;
	}

	/**
	 * Alias for `filter_wc_notice()` (`woocommerce_add_error`).
	 *
	 * @param mixed $message Error notice string.
	 * @return string
	 */
	public function filter_woocommerce_error_message( $message ): string {
		return $this->filter_wc_notice( $message );
	}

	/**
	 * Filter the WooCommerce order-received (thank-you) page message.
	 *
	 * If an order object is supplied and has a recorded `_localepilot_order_locale`,
	 * resolves the thank-you text in that order's snapshot locale.
	 *
	 * @param mixed $text  Default thank-you text.
	 * @param mixed $order Optional `WC_Order` instance.
	 * @return string
	 */
	public function filter_order_received_text( $text, $order = null ): string {
		$original = is_string( $text ) ? $text : '';
		if ( '' === trim( $original ) || ! $this->translator->is_storefront_context() ) {
			return $original;
		}

		$explicit_locale = null;
		if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
			$order_loc = (string) $order->get_meta( OrderLocaleCoordinator::ORDER_LOCALE_META_KEY, true );
			if ( '' !== trim( $order_loc ) ) {
				$explicit_locale = trim( $order_loc );
			}
		}

		return $this->resolve_store_text(
			'ui_string',
			'order_received_text',
			$original,
			$explicit_locale
		);
	}

	/**
	 * Alias for `filter_order_received_text()` (`woocommerce_thankyou_order_received_text`).
	 *
	 * @param mixed $text  Default thank-you text.
	 * @param mixed $order Optional `WC_Order` instance.
	 * @return string
	 */
	public function filter_thankyou_order_received_text( $text, $order = null ): string {
		return $this->filter_order_received_text( $text, $order );
	}

	/**
	 * Resolve a public store string by checking approved `TranslationRepository` records first
	 * (under `$object_type` and `ui_string`), then checking the safe static UI dictionary,
	 * and finally falling back to `$original_text`.
	 *
	 * @param string      $object_type     Allowed public content type.
	 * @param string      $field_key       Field identifier.
	 * @param string      $original_text   Original source text.
	 * @param string|null $explicit_locale Optional explicit locale override.
	 * @return string
	 */
	private function resolve_store_text(
		string $object_type,
		string $field_key,
		string $original_text,
		?string $explicit_locale = null
	): string {
		$translated = $this->translator->resolve_approved_text(
			$object_type,
			0,
			$field_key,
			$original_text,
			$explicit_locale
		);
		if ( $translated !== $original_text ) {
			return $translated;
		}

		if ( 'ui_string' !== $object_type ) {
			$by_ui = $this->translator->resolve_approved_text(
				'ui_string',
				0,
				$field_key,
				$original_text,
				$explicit_locale
			);
			if ( $by_ui !== $original_text ) {
				return $by_ui;
			}
		}

		$cfg           = StorefrontLocaleResolver::get_settings();
		$default_loc   = (string) $cfg['default_locale'];
		$active_locale = null !== $explicit_locale && '' !== trim( $explicit_locale )
			? trim( $explicit_locale )
			: StorefrontLocaleResolver::resolve_visitor_locale( null, null, null, $cfg );

		if ( '' === $active_locale || strtolower( $active_locale ) === strtolower( $default_loc ) ) {
			return $original_text;
		}

		$clean_text = trim( $original_text );
		if ( isset( self::STATIC_UI_DICTIONARY[ $clean_text ][ $active_locale ] ) ) {
			return self::STATIC_UI_DICTIONARY[ $clean_text ][ $active_locale ];
		}

		return $original_text;
	}

	/**
	 * Localize a composite checkout validation field label such as `Billing First name` or `Shipping Postcode / ZIP`.
	 *
	 * @param string $raw_label     Raw field label inside `<strong>...</strong>`.
	 * @param string $active_locale Active storefront locale.
	 * @return string
	 */
	private function localize_validation_field_label( string $raw_label, string $active_locale ): string {
		$section_prefixes = array(
			'Billing '  => array(
				'fr_FR' => 'Facturation ',
				'de_DE' => 'Rechnungsadresse ',
				'es_ES' => 'Facturación ',
				'ja_JP' => '請求先 ',
				'zh_CN' => '账单 ',
			),
			'Shipping ' => array(
				'fr_FR' => 'Livraison ',
				'de_DE' => 'Lieferadresse ',
				'es_ES' => 'Envío ',
				'ja_JP' => '配送先 ',
				'zh_CN' => '配送 ',
			),
		);

		foreach ( $section_prefixes as $en_prefix => $locale_map ) {
			if ( 0 === stripos( $raw_label, $en_prefix ) && isset( $locale_map[ $active_locale ] ) ) {
				$rest           = trim( substr( $raw_label, strlen( $en_prefix ) ) );
				$localized_rest = $this->resolve_store_text( 'checkout_field_label', 'validation_label', $rest, $active_locale );
				return $locale_map[ $active_locale ] . $localized_rest;
			}
		}

		return $this->resolve_store_text( 'checkout_field_label', 'validation_label', $raw_label, $active_locale );
	}
}
