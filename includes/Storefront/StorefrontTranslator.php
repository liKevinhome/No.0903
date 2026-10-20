<?php
/**
 * Same-URL Storefront Translation Reader & Filter Coordinator.
 *
 * Reads only locally stored `approved` translations from `TranslationRepository`
 * and injects them into explicitly supported WooCommerce product and taxonomy
 * filters on the same URL.
 *
 * Strictly enforces:
 * - Zero outbound translation API calls during storefront rendering.
 * - Fallback to original source text when translation is `pending_review`, missing,
 *   stale (`source_hash` mismatch), or when resolved locale equals default locale.
 * - Zero mutation of permalinks, URLs, query parameters, prices, currencies, taxes,
 *   shipping addresses, or checkout state.
 *
 * @package LocalePilot\Storefront
 */

declare(strict_types=1);

namespace LocalePilot\Storefront;

use LocalePilot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Filters supported storefront product/taxonomy fields and renders the same-URL language switcher.
 */
final class StorefrontTranslator {

	/**
	 * Shortcode tag for the storefront language switcher.
	 */
	public const SHORTCODE_SWITCHER = 'localepilot_language_switcher';

	/**
	 * Persistent local translation repository.
	 *
	 * @var TranslationRepository
	 */
	private TranslationRepository $repository;

	/**
	 * Optional forced storefront context flag for unit/integration testing.
	 *
	 * @var bool|null
	 */
	private ?bool $force_storefront_context;

	/**
	 * Whether an order transactional email is currently being rendered.
	 *
	 * @var bool
	 */
	private static bool $email_rendering_active = false;

	/**
	 * Construct the storefront translator.
	 *
	 * @param TranslationRepository|null $repository               Optional translation repository.
	 * @param bool|null                  $force_storefront_context Optional context override for testing.
	 */
	public function __construct(
		?TranslationRepository $repository = null,
		?bool $force_storefront_context = null
	) {
		$this->repository               = $repository ?? new TranslationRepository();
		$this->force_storefront_context = $force_storefront_context;
	}

	/**
	 * Set whether order email rendering is currently active (enables approved translation
	 * filters even when an email is triggered from `wp-admin`).
	 *
	 * @param bool $active True when rendering an order email.
	 * @return void
	 */
	public static function set_email_rendering_active( bool $active ): void {
		self::$email_rendering_active = $active;
	}

	/**
	 * Check whether order email rendering is currently active.
	 *
	 * @return bool
	 */
	public static function is_email_rendering_active(): bool {
		return self::$email_rendering_active;
	}

	/**
	 * Determine whether the current request targets the WooCommerce Store API (`/wc/store/`)
	 * used by WooCommerce Cart & Checkout Blocks.
	 *
	 * @param array<string, mixed>|null $server     Optional `$_SERVER` override.
	 * @param array<string, mixed>|null $get_params Optional `$_GET` override.
	 * @return bool
	 */
	public static function is_store_api_request( ?array $server = null, ?array $get_params = null ): bool {
		$srv = $server ?? $_SERVER;
		$get = $get_params ?? $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route inspection.

		$uri = isset( $srv['REQUEST_URI'] ) && is_string( $srv['REQUEST_URI'] ) ? $srv['REQUEST_URI'] : '';
		if ( false !== strpos( $uri, '/wc/store/' ) ) {
			return true;
		}

		$rest_route = isset( $get['rest_route'] ) && is_string( $get['rest_route'] ) ? $get['rest_route'] : '';
		if ( 0 === strpos( $rest_route, '/wc/store/' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Register WordPress & WooCommerce storefront filters and shortcode.
	 *
	 * Never hooks permalink filters (`post_link`, `post_type_link`, `term_link`, `home_url`)
	 * so all URLs and query parameters remain 100% unchanged across languages.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		// WooCommerce product & variation data getters (explicitly supported scope).
		add_filter( 'woocommerce_product_get_name', array( $this, 'filter_product_title' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_name', array( $this, 'filter_product_title' ), 20, 2 );
		add_filter( 'woocommerce_product_title', array( $this, 'filter_product_title' ), 20, 2 );
		add_filter( 'woocommerce_product_get_short_description', array( $this, 'filter_product_short_description' ), 20, 2 );
		add_filter( 'woocommerce_product_get_description', array( $this, 'filter_product_description' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_description', array( $this, 'filter_product_description' ), 20, 2 );

		// WordPress post title / content fallback for product post type on storefront.
		add_filter( 'the_title', array( $this, 'filter_wp_post_title' ), 20, 2 );

		// Product categories, tags, and attribute terms.
		add_filter( 'get_term', array( $this, 'filter_taxonomy_term' ), 20, 2 );
		add_filter( 'woocommerce_attribute_label', array( $this, 'filter_attribute_label' ), 20, 2 );

		// HTML document `lang` attribute on the same URL.
		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ), 20, 1 );

		if ( function_exists( 'add_shortcode' ) ) {
			add_shortcode( self::SHORTCODE_SWITCHER, array( $this, 'render_switcher_shortcode' ) );
		}
	}

	/**
	 * Check whether the current execution context is a storefront rendering request
	 * (including storefront `wc-ajax`, WooCommerce Store API `/wc/store/`, or order email
	 * rendering), and NOT a backend `wp-admin` editing screen or non-Store-API REST endpoint.
	 *
	 * @return bool
	 */
	public function is_storefront_context(): bool {
		if ( self::$email_rendering_active ) {
			return true;
		}

		if ( null !== $this->force_storefront_context ) {
			return $this->force_storefront_context;
		}

		if ( self::is_store_api_request() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) ) {
			return false;
		}

		// Storefront WooCommerce AJAX requests (`?wc-ajax=...`) are storefront contexts.
		if ( isset( $_GET['wc-ajax'] ) && '' !== (string) $_GET['wc-ajax'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Context check only.
			return true;
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}

		return true;
	}

	/**
	 * Filter WooCommerce product or variation title using locally approved translations.
	 *
	 * @param mixed $title   Original product title.
	 * @param mixed $product WC_Product instance or ID.
	 * @return string
	 */
	public function filter_product_title( $title, $product = null ): string {
		$original = is_string( $title ) ? $title : '';
		if ( '' === trim( $original ) || ! $this->is_storefront_context() ) {
			return $original;
		}

		$product_id = self::extract_object_id( $product );
		$translated = $this->resolve_approved_text( 'product_title', $product_id, 'post_title', $original );
		if ( $translated === $original && is_object( $product ) && method_exists( $product, 'get_parent_id' ) ) {
			$parent_id = (int) $product->get_parent_id();
			if ( $parent_id > 0 && $parent_id !== $product_id ) {
				$translated = $this->resolve_approved_text( 'product_title', $parent_id, 'post_title', $original );
			}
		}

		return $translated;
	}

	/**
	 * Filter WooCommerce product short description using locally approved translations.
	 *
	 * @param mixed $short_desc Original short description.
	 * @param mixed $product    WC_Product instance or ID.
	 * @return string
	 */
	public function filter_product_short_description( $short_desc, $product = null ): string {
		$original = is_string( $short_desc ) ? $short_desc : '';
		if ( '' === trim( $original ) || ! $this->is_storefront_context() ) {
			return $original;
		}

		$product_id = self::extract_object_id( $product );
		return $this->resolve_approved_text( 'product_short_description', $product_id, 'post_excerpt', $original );
	}

	/**
	 * Filter WooCommerce product or variation description using locally approved translations.
	 *
	 * @param mixed $description Original description.
	 * @param mixed $product     WC_Product instance or ID.
	 * @return string
	 */
	public function filter_product_description( $description, $product = null ): string {
		$original = is_string( $description ) ? $description : '';
		if ( '' === trim( $original ) || ! $this->is_storefront_context() ) {
			return $original;
		}

		$product_id = self::extract_object_id( $product );
		return $this->resolve_approved_text( 'product_description', $product_id, 'post_content', $original );
	}

	/**
	 * Filter WordPress `the_title` for `product` post types on the storefront.
	 *
	 * @param mixed $title   Post title.
	 * @param mixed $post_id Post ID.
	 * @return string
	 */
	public function filter_wp_post_title( $title, $post_id = 0 ): string {
		$original = is_string( $title ) ? $title : '';
		$id       = is_numeric( $post_id ) ? (int) $post_id : 0;

		if ( '' === trim( $original ) || $id <= 0 || ! $this->is_storefront_context() ) {
			return $original;
		}

		if ( function_exists( 'get_post_type' ) ) {
			$post_type = get_post_type( $id );
			if ( ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
				return $original;
			}
		}

		return $this->resolve_approved_text( 'product_title', $id, 'post_title', $original );
	}

	/**
	 * Filter WooCommerce product category, tag, and attribute taxonomy terms on the storefront.
	 *
	 * @param mixed  $term     WP_Term object or array.
	 * @param string $taxonomy Taxonomy slug.
	 * @return mixed
	 */
	public function filter_taxonomy_term( $term, string $taxonomy = '' ) {
		if ( ! is_object( $term ) || ! isset( $term->term_id, $term->name ) || ! $this->is_storefront_context() ) {
			return $term;
		}

		$tax = '' !== $taxonomy ? $taxonomy : ( isset( $term->taxonomy ) ? (string) $term->taxonomy : '' );
		if ( '' !== $tax && ! in_array( $tax, array( 'product_cat', 'product_tag' ), true ) && 0 !== strpos( $tax, 'pa_' ) ) {
			return $term;
		}

		$term_id       = (int) $term->term_id;
		$original_name = (string) $term->name;
		if ( '' !== trim( $original_name ) ) {
			$term->name = $this->resolve_approved_text( 'taxonomy_term', $term_id, 'name', $original_name );
		}

		if ( isset( $term->description ) && is_string( $term->description ) && '' !== trim( $term->description ) ) {
			$term->description = $this->resolve_approved_text( 'taxonomy_term', $term_id, 'description', $term->description );
		}

		return $term;
	}

	/**
	 * Filter WooCommerce product attribute label on the storefront.
	 *
	 * @param mixed  $label Attribute display label.
	 * @param string $name  Attribute slug/name.
	 * @return string
	 */
	public function filter_attribute_label( $label, string $name = '' ): string {
		$original = is_string( $label ) ? $label : '';
		if ( '' === trim( $original ) || ! $this->is_storefront_context() ) {
			return $original;
		}

		$field_key = '' !== trim( $name ) ? trim( $name ) : 'attribute_label';
		return $this->resolve_approved_text( 'product_attribute', 0, $field_key, $original );
	}

	/**
	 * Translate an explicitly registered storefront UI string using local `approved` translations.
	 *
	 * @param string $original_text Original UI string.
	 * @param string $string_key    Optional identifier key.
	 * @return string
	 */
	public function translate_ui_string( string $original_text, string $string_key = 'ui_string' ): string {
		if ( '' === trim( $original_text ) || ! $this->is_storefront_context() ) {
			return $original_text;
		}

		return $this->resolve_approved_text( 'ui_string', 0, $string_key, $original_text );
	}

	/**
	 * Filter HTML `lang="..."` attribute on the storefront so the same URL reflects
	 * the active visitor locale (e.g. `lang="fr-FR"`).
	 *
	 * @param mixed $output Existing `language_attributes` output.
	 * @return string
	 */
	public function filter_language_attributes( $output ): string {
		$raw = is_string( $output ) ? $output : '';
		if ( ! $this->is_storefront_context() ) {
			return $raw;
		}

		$active_locale = StorefrontLocaleResolver::resolve_visitor_locale();
		$bcp47         = str_replace( '_', '-', $active_locale );

		if ( preg_match( '/lang="[^"]*"/i', $raw ) ) {
			return (string) preg_replace( '/lang="[^"]*"/i', 'lang="' . htmlspecialchars( $bcp47, ENT_QUOTES, 'UTF-8' ) . '"', $raw, 1 );
		}

		return trim( $raw . ' lang="' . htmlspecialchars( $bcp47, ENT_QUOTES, 'UTF-8' ) . '"' );
	}

	/**
	 * Render the same-URL storefront language switcher HTML.
	 *
	 * Preserves the exact current URL and query parameters (no `/fr/` or `/de/` path rewrites).
	 *
	 * @param string                    $current_url    Current request URL (e.g. `/product/coat/?utm_source=ad`).
	 * @param array<string, mixed>|null $cookies        Optional `$_COOKIE` override.
	 * @param array<string, mixed>|null $server         Optional `$_SERVER` override.
	 * @param array<string, mixed>|null $request_params Optional request params override.
	 * @param array<string, mixed>|null $settings       Optional storefront settings override.
	 * @return string Escaped HTML markup.
	 */
	public function render_language_switcher(
		string $current_url = '',
		?array $cookies = null,
		?array $server = null,
		?array $request_params = null,
		?array $settings = null
	): string {
		$cfg     = $settings ?? StorefrontLocaleResolver::get_settings();
		$details = StorefrontLocaleResolver::resolve_visitor_locale_details( $cookies, $server, $request_params, $cfg );
		$active  = $details['locale'];
		$enabled = (array) $cfg['enabled_locales'];

		if ( '' === $current_url ) {
			$server_bag  = $server ?? $_SERVER;
			$current_url = isset( $server_bag['REQUEST_URI'] ) && is_string( $server_bag['REQUEST_URI'] )
				? $server_bag['REQUEST_URI']
				: '/';
		}

		$canonical_url = StorefrontLocaleResolver::preserve_canonical_url( $current_url );

		$html  = '<div class="lp-language-switcher" data-lp-current-locale="' . self::escape_attr( $active ) . '" data-lp-canonical-url="' . self::escape_attr( $canonical_url ) . '">';
		$html .= '<ul class="lp-language-switcher__list">';

		foreach ( $enabled as $locale_code ) {
			$is_current = ( $locale_code === $active );
			$switch_url = StorefrontLocaleResolver::build_same_url_switch_link( $canonical_url, (string) $locale_code );
			$active_cls = $is_current ? ' lp-language-switcher__item--active' : '';

			$html .= '<li class="lp-language-switcher__item' . $active_cls . '">';
			$html .= '<a class="lp-language-switcher__link" href="' . self::escape_attr( $switch_url ) . '" data-lp-switch-locale="' . self::escape_attr( (string) $locale_code ) . '">';
			$html .= self::escape_html( (string) $locale_code );
			$html .= '</a></li>';
		}

		$html .= '</ul>';

		// Optional non-intrusive IP country suggestion notice when visitor has no manual cookie yet.
		if ( null !== $details['geoip_suggestion'] && 'cookie' !== $details['source'] && 'manual_switch' !== $details['source'] ) {
			$html .= '<div class="lp-geoip-suggestion" data-lp-geoip-suggestion="' . self::escape_attr( $details['geoip_suggestion'] ) . '"></div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Shortcode callback for `[localepilot_language_switcher]`.
	 *
	 * @return string
	 */
	public function render_switcher_shortcode(): string {
		return $this->render_language_switcher();
	}

	/**
	 * Look up an approved local translation for the active (or explicit) storefront locale,
	 * falling back to `$original_text` whenever the locale is default, missing,
	 * `pending_review`, or stale (`source_hash` mismatch).
	 *
	 * @param string      $object_type     Content object type.
	 * @param int         $object_id       Object ID.
	 * @param string      $field_key       Field key.
	 * @param string      $original_text   Original source text.
	 * @param string|null $explicit_locale Optional explicit target locale override.
	 * @return string
	 */
	public function resolve_approved_text(
		string $object_type,
		int $object_id,
		string $field_key,
		string $original_text,
		?string $explicit_locale = null
	): string {
		$cfg           = StorefrontLocaleResolver::get_settings();
		$default_loc   = (string) $cfg['default_locale'];
		$active_locale = null !== $explicit_locale && '' !== trim( $explicit_locale )
			? trim( $explicit_locale )
			: StorefrontLocaleResolver::resolve_visitor_locale( null, null, null, $cfg );

		if ( '' === $active_locale || strtolower( $active_locale ) === strtolower( $default_loc ) ) {
			return $original_text;
		}

		$source_hash = hash( 'sha256', trim( $original_text ) );
		$approved    = $this->repository->find_approved_for_storefront(
			$object_type,
			$object_id,
			$field_key,
			$active_locale,
			$source_hash
		);

		if ( null === $approved || '' === trim( $approved ) ) {
			return $original_text;
		}

		return $approved;
	}

	/**
	 * Extract integer ID from a `WC_Product`, `WP_Post`, or numeric value.
	 *
	 * @param mixed $entity Product/post object or ID.
	 * @return int
	 */
	private static function extract_object_id( $entity ): int {
		if ( is_int( $entity ) ) {
			return max( 0, $entity );
		}

		if ( is_object( $entity ) ) {
			if ( method_exists( $entity, 'get_id' ) ) {
				return max( 0, (int) $entity->get_id() );
			}
			if ( isset( $entity->ID ) ) {
				return max( 0, (int) $entity->ID );
			}
		}

		return 0;
	}

	/**
	 * Escape HTML text safely.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function escape_html( string $text ): string {
		if ( function_exists( 'esc_html' ) ) {
			return esc_html( $text );
		}

		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Escape HTML attribute safely.
	 *
	 * @param string $text Raw attribute text.
	 * @return string
	 */
	private static function escape_attr( string $text ): string {
		if ( function_exists( 'esc_attr' ) ) {
			return esc_attr( $text );
		}

		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
