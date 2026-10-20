<?php
/**
 * Same-URL Multilingual Cache Isolation Guard.
 *
 * Ensures that a page rendered in Language A is never cached in a shared HTML
 * page cache and served to a visitor requesting Language B on the same URL.
 *
 * - Registers `lp_lang` as a dynamic/mandatory cookie for WP Rocket when active.
 * - Reports honest certification status for cache layers (never claiming universal compatibility).
 * - Enforces safe fallback (`DONOTCACHEPAGE`, `Cache-Control: private, no-store, no-cache`,
 *   `Vary: Cookie, Accept-Language`) whenever `lp_lang` is active or the cache stack is unverified.
 *
 * @package LocalePilot\Storefront
 */

declare(strict_types=1);

namespace LocalePilot\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates cache adapter hooks, certification status reporting, and safe cache-bypass headers.
 */
final class CacheIsolationGuard {

	/**
	 * Certification status constants.
	 */
	public const STATUS_REQUIRES_VERIFICATION = 'requires_dynamic_cookie_verification';
	public const STATUS_UNCERTIFIED_BYPASS    = 'uncertified_safe_bypass';
	public const STATUS_SAFE_BYPASS_ENFORCED  = 'safe_bypass_enforced';

	/**
	 * Register WordPress & cache plugin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! function_exists( 'add_filter' ) || ! function_exists( 'add_action' ) ) {
			return;
		}

		// WP Rocket dynamic & mandatory cookie integration filters.
		add_filter( 'rocket_cache_dynamic_cookies', array( $this, 'register_wp_rocket_cookie' ) );
		add_filter( 'rocket_cache_mandatory_cookies', array( $this, 'register_wp_rocket_cookie' ) );

		// Apply HTTP response cache isolation headers on storefront template_redirect.
		add_action( 'template_redirect', array( $this, 'apply_storefront_cache_guard' ), 1 );
	}

	/**
	 * Append `lp_lang` to WP Rocket's dynamic/mandatory cookie list so WP Rocket
	 * creates separate cache files per visitor language cookie.
	 *
	 * @param mixed $cookies Existing cookie list.
	 * @return array<int, string>
	 */
	public function register_wp_rocket_cookie( $cookies ): array {
		$list = is_array( $cookies ) ? $cookies : array();
		if ( ! in_array( StorefrontLocaleResolver::COOKIE_NAME, $list, true ) ) {
			$list[] = StorefrontLocaleResolver::COOKIE_NAME;
		}

		return $list;
	}

	/**
	 * Return the honest certification matrix for page cache / CDN integrations.
	 *
	 * @return array<string, array{status:string, shared_cache_allowed:bool, note:string}>
	 */
	public static function get_cache_integrations_status(): array {
		return array(
			'wp_rocket'               => array(
				'status'               => self::STATUS_REQUIRES_VERIFICATION,
				'shared_cache_allowed' => false,
				'note'                 => 'Provides rocket_cache_dynamic_cookies filter for lp_lang; requires staging Cache Health Check before enabling shared HTML cache.',
			),
			'litespeed_cache'         => array(
				'status'               => self::STATUS_UNCERTIFIED_BYPASS,
				'shared_cache_allowed' => false,
				'note'                 => 'Uncertified in MVP; safe bypass enforced when lp_lang or non-default locale is active.',
			),
			'cloudflare_apo_edge'     => array(
				'status'               => self::STATUS_UNCERTIFIED_BYPASS,
				'shared_cache_allowed' => false,
				'note'                 => 'Edge HTML caching without custom Cookie cache keys is incompatible with same-URL multilingual pages; safe bypass enforced.',
			),
			'unrecognized_page_cache' => array(
				'status'               => self::STATUS_SAFE_BYPASS_ENFORCED,
				'shared_cache_allowed' => false,
				'note'                 => 'Any unrecognized or unverified page cache defaults to safe HTML cache bypass.',
			),
		);
	}

	/**
	 * Evaluate the cache guard decision and HTTP response headers for a storefront request.
	 *
	 * By safety rule, shared page caching is bypassed (`bypass_shared_cache = true`) whenever:
	 * - The visitor has an active `lp_lang` cookie or `lp_set_lang` switch request, OR
	 * - The resolved locale differs from the store default locale, OR
	 * - The store's `cache_mode` is not `'verified_dynamic_cookie'`.
	 *
	 * @param string                    $resolved_locale Resolved storefront locale.
	 * @param array<string, mixed>|null $cookies         Optional `$_COOKIE` override.
	 * @param array<string, mixed>|null $settings        Optional storefront settings override.
	 * @return array{bypass_shared_cache:bool, headers:array<string,string>, reason:string}
	 */
	public static function evaluate_cache_guard(
		string $resolved_locale,
		?array $cookies = null,
		?array $settings = null
	): array {
		$cfg            = $settings ?? StorefrontLocaleResolver::get_settings();
		$default_locale = (string) $cfg['default_locale'];
		$cache_mode     = (string) $cfg['cache_mode'];
		$cookie_bag     = $cookies ?? $_COOKIE;

		$has_lp_cookie  = isset( $cookie_bag[ StorefrontLocaleResolver::COOKIE_NAME ] )
			&& '' !== trim( (string) $cookie_bag[ StorefrontLocaleResolver::COOKIE_NAME ] );
		$is_non_default = strtolower( $resolved_locale ) !== strtolower( $default_locale );
		$is_unverified  = 'verified_dynamic_cookie' !== $cache_mode;

		$headers = array(
			'Vary'                 => 'Cookie, Accept-Language',
			'X-LocalePilot-Locale' => $resolved_locale,
		);

		if ( $is_unverified || $has_lp_cookie || $is_non_default ) {
			$reason = 'unverified_cache_safe_bypass';
			if ( $has_lp_cookie ) {
				$reason = 'lp_lang_cookie_present_safe_bypass';
			} elseif ( $is_non_default ) {
				$reason = 'non_default_locale_safe_bypass';
			}

			$headers['Cache-Control']             = 'private, no-store, no-cache, must-revalidate, max-age=0';
			$headers['Pragma']                    = 'no-cache';
			$headers['X-LocalePilot-Cache-Guard'] = $reason;

			return array(
				'bypass_shared_cache' => true,
				'headers'             => $headers,
				'reason'              => $reason,
			);
		}

		$headers['X-LocalePilot-Cache-Guard'] = 'verified_dynamic_cookie_default_locale';

		return array(
			'bypass_shared_cache' => false,
			'headers'             => $headers,
			'reason'              => 'verified_dynamic_cookie_default_locale',
		);
	}

	/**
	 * Apply the evaluated cache isolation guard (`DONOTCACHEPAGE` constant, `nocache_headers()`,
	 * and HTTP headers) during a live storefront request.
	 *
	 * @param array<string, mixed>|null $cookies  Optional `$_COOKIE` override.
	 * @param array<string, mixed>|null $server   Optional `$_SERVER` override.
	 * @param array<string, mixed>|null $settings Optional storefront settings override.
	 * @return array{bypass_shared_cache:bool, headers:array<string,string>, reason:string}
	 */
	public function apply_storefront_cache_guard(
		?array $cookies = null,
		?array $server = null,
		?array $settings = null
	): array {
		$resolved = StorefrontLocaleResolver::resolve_visitor_locale( $cookies, $server, null, $settings );
		$decision = self::evaluate_cache_guard( $resolved, $cookies, $settings );

		if ( $decision['bypass_shared_cache'] ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
				// Object cache is safe because keys are scoped by locale, so we only block shared HTML page cache.
				define( 'DONOTCACHEDB', false );
			}
			if ( 'cli' !== PHP_SAPI && function_exists( 'nocache_headers' ) && ! headers_sent() ) {
				nocache_headers();
			}
		}

		if ( 'cli' !== PHP_SAPI && ! headers_sent() ) {
			foreach ( $decision['headers'] as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		return $decision;
	}
}
