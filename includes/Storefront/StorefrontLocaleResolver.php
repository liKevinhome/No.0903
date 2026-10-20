<?php
/**
 * Same-URL Storefront Locale Resolver.
 *
 * Resolves the active storefront visitor locale strictly on the same URL
 * (without `/fr/`, `/de/` path prefixes or domain rewrites) according to:
 * 1. Visitor manual selection (`lp_set_lang` request / `lp_lang` Cookie)
 * 2. Browser `Accept-Language` header matched against enabled locales
 * 3. Trusted proxy IP country hint/fallback (`CF-IPCountry` / `HTTP_X_COUNTRY_CODE`)
 * 4. Store default locale
 *
 * IP country hints never modify WooCommerce customer shipping/billing address,
 * currency, prices, taxes, shipping rates, or checkout state.
 *
 * @package LocalePilot\Storefront
 */

declare(strict_types=1);

namespace LocalePilot\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves visitor language preferences and manages the `lp_lang` cookie and same-URL links.
 */
final class StorefrontLocaleResolver {

	/**
	 * Visitor language preference cookie name.
	 */
	public const COOKIE_NAME = 'lp_lang';

	/**
	 * Query/POST parameter used when a visitor manually switches language on the same URL.
	 */
	public const SWITCH_PARAM = 'lp_set_lang';

	/**
	 * WordPress option key for storefront language settings.
	 */
	public const OPTION_KEY = '_localepilot_storefront_settings';

	/**
	 * Regex pattern for valid locale identifiers (e.g. `en_US`, `fr_FR`, `zh_CN`).
	 */
	private const VALID_LOCALE_REGEX = '/^[a-z]{2,3}(?:_[A-Z]{2}|_[A-Za-z]{4})?$/';

	/**
	 * In-memory fallback settings for standalone CLI unit tests.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $memory_settings = null;

	/**
	 * Last cookie payload set during the current request (for test inspection).
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $last_cookie_set = null;

	/**
	 * Temporary runtime locale override used while rendering order transactional emails.
	 *
	 * @var string|null
	 */
	private static ?string $runtime_locale_override = null;

	/**
	 * Reset in-memory state (intended for isolated unit tests).
	 *
	 * @return void
	 */
	public static function reset_memory_state(): void {
		self::$memory_settings         = null;
		self::$last_cookie_set         = null;
		self::$runtime_locale_override = null;
	}

	/**
	 * Set or clear a temporary runtime locale override (used during order email rendering).
	 *
	 * @param string|null $locale Validated locale code or null to restore normal resolution.
	 * @return void
	 */
	public static function set_runtime_locale_override( ?string $locale ): void {
		if ( null === $locale || '' === trim( $locale ) ) {
			self::$runtime_locale_override = null;
			return;
		}

		$clean                         = self::sanitize_locale_code( $locale );
		self::$runtime_locale_override = '' !== $clean ? $clean : null;
	}

	/**
	 * Return the active temporary runtime locale override if set.
	 *
	 * @return string|null
	 */
	public static function get_runtime_locale_override(): ?string {
		return self::$runtime_locale_override;
	}

	/**
	 * Return default storefront locale settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'default_locale'    => 'en_US',
			'enabled_locales'   => array( 'en_US', 'fr_FR', 'de_DE', 'es_ES', 'ja_JP', 'zh_CN' ),
			'trust_proxy_geoip' => false,
			'geoip_country_map' => array(
				'US' => 'en_US',
				'GB' => 'en_US',
				'FR' => 'fr_FR',
				'DE' => 'de_DE',
				'ES' => 'es_ES',
				'JP' => 'ja_JP',
				'CN' => 'zh_CN',
			),
			'cookie_ttl_days'   => 30,
			'cache_mode'        => 'safe_bypass',
		);
	}

	/**
	 * Retrieve storefront locale configuration.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
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

		$default_locale = isset( $stored['default_locale'] ) && is_string( $stored['default_locale'] )
			? self::sanitize_locale_code( $stored['default_locale'] )
			: '';
		if ( '' === $default_locale ) {
			$default_locale = $defaults['default_locale'];
		}

		$enabled_locales = array();
		if ( isset( $stored['enabled_locales'] ) && is_array( $stored['enabled_locales'] ) ) {
			foreach ( $stored['enabled_locales'] as $loc ) {
				if ( is_string( $loc ) ) {
					$clean_loc = self::sanitize_locale_code( $loc );
					if ( '' !== $clean_loc && ! in_array( $clean_loc, $enabled_locales, true ) ) {
						$enabled_locales[] = $clean_loc;
					}
				}
			}
		}
		if ( empty( $enabled_locales ) ) {
			$enabled_locales = $defaults['enabled_locales'];
		}
		if ( ! in_array( $default_locale, $enabled_locales, true ) ) {
			array_unshift( $enabled_locales, $default_locale );
		}

		$trust_geoip = ! empty( $stored['trust_proxy_geoip'] );

		$country_map = $defaults['geoip_country_map'];
		if ( isset( $stored['geoip_country_map'] ) && is_array( $stored['geoip_country_map'] ) ) {
			$custom_map = array();
			foreach ( $stored['geoip_country_map'] as $cc => $loc ) {
				if ( is_string( $cc ) && is_string( $loc ) ) {
					$clean_cc  = strtoupper( trim( $cc ) );
					$clean_loc = self::sanitize_locale_code( $loc );
					if ( 2 === strlen( $clean_cc ) && '' !== $clean_loc ) {
						$custom_map[ $clean_cc ] = $clean_loc;
					}
				}
			}
			if ( ! empty( $custom_map ) ) {
				$country_map = $custom_map;
			}
		}

		$ttl_days = isset( $stored['cookie_ttl_days'] ) ? (int) $stored['cookie_ttl_days'] : 30;
		$ttl_days = max( 1, min( 365, $ttl_days ) );

		$cache_mode = isset( $stored['cache_mode'] ) && 'verified_dynamic_cookie' === $stored['cache_mode']
			? 'verified_dynamic_cookie'
			: 'safe_bypass';

		return array(
			'default_locale'    => $default_locale,
			'enabled_locales'   => $enabled_locales,
			'trust_proxy_geoip' => $trust_geoip,
			'geoip_country_map' => $country_map,
			'cookie_ttl_days'   => $ttl_days,
			'cache_mode'        => $cache_mode,
		);
	}

	/**
	 * Update storefront locale settings in WordPress options (and in-memory fallback).
	 *
	 * @param array<string, mixed> $settings Settings to merge and save.
	 * @return bool
	 */
	public static function update_settings( array $settings ): bool {
		$current = self::get_settings();
		$merged  = array_merge( $current, $settings );

		self::$memory_settings = $merged;
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $merged, false );
		}

		return true;
	}

	/**
	 * Sanitize and canonicalize a locale code (e.g. `fr-fr` -> `fr_FR`), or return
	 * empty string when invalid/malicious.
	 *
	 * @param string $raw_locale Raw locale input.
	 * @return string Canonical locale or empty string.
	 */
	public static function sanitize_locale_code( string $raw_locale ): string {
		$trimmed = trim( str_replace( '-', '_', $raw_locale ) );
		if ( '' === $trimmed || strlen( $trimmed ) > 12 ) {
			return '';
		}

		$parts = explode( '_', $trimmed );
		if ( 1 === count( $parts ) ) {
			$candidate = strtolower( $parts[0] );
		} elseif ( 2 === count( $parts ) ) {
			$region    = 2 === strlen( $parts[1] ) ? strtoupper( $parts[1] ) : ucfirst( strtolower( $parts[1] ) );
			$candidate = strtolower( $parts[0] ) . '_' . $region;
		} else {
			return '';
		}

		if ( ! preg_match( self::VALID_LOCALE_REGEX, $candidate ) ) {
			return '';
		}

		return $candidate;
	}

	/**
	 * Match a candidate locale or primary language tag (e.g. `fr-CA` or `fr`)
	 * against the merchant's enabled locales list.
	 *
	 * @param string            $candidate       Raw locale or language tag.
	 * @param array<int,string> $enabled_locales Enabled locale list.
	 * @return string|null Matched enabled locale or null.
	 */
	public static function match_enabled_locale( string $candidate, array $enabled_locales ): ?string {
		$sanitized = self::sanitize_locale_code( $candidate );
		if ( '' === $sanitized ) {
			return null;
		}

		// 1. Exact canonical match (e.g. `fr_FR` === `fr_FR`).
		foreach ( $enabled_locales as $enabled ) {
			if ( strtolower( $enabled ) === strtolower( $sanitized ) ) {
				return $enabled;
			}
		}

		// 2. Primary language prefix match (e.g. `fr` or `fr_CA` -> `fr_FR` if `fr_FR` is enabled).
		$primary = explode( '_', strtolower( $sanitized ) )[0];
		foreach ( $enabled_locales as $enabled ) {
			$enabled_primary = explode( '_', strtolower( $enabled ) )[0];
			if ( $primary === $enabled_primary ) {
				return $enabled;
			}
		}

		return null;
	}

	/**
	 * Resolve the active storefront visitor locale and source reason:
	 * `manual_switch` > `cookie` > `accept_language` > `geoip_hint` > `default`.
	 *
	 * @param array<string, mixed>|null $cookies        Optional `$_COOKIE` override.
	 * @param array<string, mixed>|null $server         Optional `$_SERVER` override.
	 * @param array<string, mixed>|null $request_params Optional `$_GET`/`$_POST` override.
	 * @param array<string, mixed>|null $settings       Optional storefront settings override.
	 * @return array{locale:string, source:string, geoip_suggestion:?string}
	 */
	public static function resolve_visitor_locale_details(
		?array $cookies = null,
		?array $server = null,
		?array $request_params = null,
		?array $settings = null
	): array {
		$cfg             = $settings ?? self::get_settings();
		$default_locale  = (string) $cfg['default_locale'];
		$enabled_locales = (array) $cfg['enabled_locales'];

		if ( null !== self::$runtime_locale_override && '' !== self::$runtime_locale_override ) {
			$matched_override = self::match_enabled_locale( self::$runtime_locale_override, $enabled_locales ) ?? self::$runtime_locale_override;
			return array(
				'locale'           => $matched_override,
				'source'           => 'order_email_override',
				'geoip_suggestion' => null,
			);
		}

		$cookie_bag = $cookies ?? $_COOKIE;
		$server_bag = $server ?? $_SERVER;
		$req_bag    = $request_params ?? array_merge( $_GET, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Public visitor language preference read, strictly validated against enabled_locales whitelist.

		$geoip_suggestion = self::resolve_geoip_locale( $server_bag, $cfg );

		// 1a. Explicit visitor manual switch request parameter (`lp_set_lang`).
		if ( isset( $req_bag[ self::SWITCH_PARAM ] ) && is_string( $req_bag[ self::SWITCH_PARAM ] ) ) {
			$matched_switch = self::match_enabled_locale( $req_bag[ self::SWITCH_PARAM ], $enabled_locales );
			if ( null !== $matched_switch ) {
				self::set_visitor_cookie( $matched_switch, (int) $cfg['cookie_ttl_days'] );
				return array(
					'locale'           => $matched_switch,
					'source'           => 'manual_switch',
					'geoip_suggestion' => $geoip_suggestion,
				);
			}
		}

		// 1b. Visitor `lp_lang` cookie.
		if ( isset( $cookie_bag[ self::COOKIE_NAME ] ) && is_string( $cookie_bag[ self::COOKIE_NAME ] ) ) {
			$matched_cookie = self::match_enabled_locale( $cookie_bag[ self::COOKIE_NAME ], $enabled_locales );
			if ( null !== $matched_cookie ) {
				return array(
					'locale'           => $matched_cookie,
					'source'           => 'cookie',
					'geoip_suggestion' => $geoip_suggestion,
				);
			}
		}

		// 2. Browser `Accept-Language` header.
		$accept_lang = isset( $server_bag['HTTP_ACCEPT_LANGUAGE'] ) && is_string( $server_bag['HTTP_ACCEPT_LANGUAGE'] )
			? $server_bag['HTTP_ACCEPT_LANGUAGE']
			: '';
		if ( '' !== $accept_lang ) {
			foreach ( self::parse_accept_language( $accept_lang ) as $tag ) {
				$matched_browser = self::match_enabled_locale( $tag, $enabled_locales );
				if ( null !== $matched_browser ) {
					return array(
						'locale'           => $matched_browser,
						'source'           => 'accept_language',
						'geoip_suggestion' => $geoip_suggestion,
					);
				}
			}
		}

		// 3. Trusted proxy IP country fallback (only when `trust_proxy_geoip` is enabled).
		if ( null !== $geoip_suggestion ) {
			return array(
				'locale'           => $geoip_suggestion,
				'source'           => 'geoip_fallback',
				'geoip_suggestion' => $geoip_suggestion,
			);
		}

		// 4. Store default locale.
		return array(
			'locale'           => $default_locale,
			'source'           => 'default',
			'geoip_suggestion' => null,
		);
	}

	/**
	 * Resolve and return the active storefront locale code.
	 *
	 * @param array<string, mixed>|null $cookies        Optional `$_COOKIE` override.
	 * @param array<string, mixed>|null $server         Optional `$_SERVER` override.
	 * @param array<string, mixed>|null $request_params Optional `$_GET`/`$_POST` override.
	 * @param array<string, mixed>|null $settings       Optional storefront settings override.
	 * @return string Resolved enabled locale code.
	 */
	public static function resolve_visitor_locale(
		?array $cookies = null,
		?array $server = null,
		?array $request_params = null,
		?array $settings = null
	): string {
		$details = self::resolve_visitor_locale_details( $cookies, $server, $request_params, $settings );
		return $details['locale'];
	}

	/**
	 * Parse an HTTP `Accept-Language` header into an ordered list of language tags
	 * sorted by quality weight (`q=...`) descending while preserving relative order on ties.
	 *
	 * @param string $header Raw `Accept-Language` header value.
	 * @return array<int, string>
	 */
	public static function parse_accept_language( string $header ): array {
		$trimmed = trim( $header );
		if ( '' === $trimmed || strlen( $trimmed ) > 512 ) {
			return array();
		}

		$items  = explode( ',', $trimmed );
		$parsed = array();
		$index  = 0;

		foreach ( $items as $item ) {
			$subparts = explode( ';', trim( $item ) );
			$tag      = trim( $subparts[0] );
			if ( '' === $tag || '*' === $tag ) {
				continue;
			}

			$q = 1.0;
			if ( isset( $subparts[1] ) && preg_match( '/^q=([01](?:\.\d{1,3})?)$/i', trim( $subparts[1] ), $m ) ) {
				$q = (float) $m[1];
			}

			if ( $q <= 0.0 ) {
				continue;
			}

			$parsed[] = array(
				'tag'   => $tag,
				'q'     => $q,
				'index' => $index++,
			);
		}

		usort(
			$parsed,
			static function ( array $a, array $b ): int {
				if ( abs( $a['q'] - $b['q'] ) > 0.0001 ) {
					return ( $a['q'] > $b['q'] ) ? -1 : 1;
				}
				return $a['index'] <=> $b['index'];
			}
		);

		return array_column( $parsed, 'tag' );
	}

	/**
	 * Set the `lp_lang` visitor cookie safely and record cookie metadata for inspection.
	 *
	 * @param string $locale   Validated enabled locale.
	 * @param int    $ttl_days Cookie TTL in days.
	 * @return array<string, mixed> Cookie descriptor.
	 */
	public static function set_visitor_cookie( string $locale, int $ttl_days = 30 ): array {
		$expires   = time() + ( max( 1, min( 365, $ttl_days ) ) * 86400 );
		$is_secure = function_exists( 'is_ssl' ) ? is_ssl() : ( isset( $_SERVER['HTTPS'] ) && 'on' === strtolower( (string) $_SERVER['HTTPS'] ) );
		$path      = defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$domain    = defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';

		$descriptor = array(
			'name'     => self::COOKIE_NAME,
			'value'    => $locale,
			'expires'  => $expires,
			'path'     => $path,
			'domain'   => $domain,
			'secure'   => $is_secure,
			'httponly' => false,
			'samesite' => 'Lax',
		);

		self::$last_cookie_set        = $descriptor;
		$_COOKIE[ self::COOKIE_NAME ] = $locale;

		if ( 'cli' !== PHP_SAPI && ! headers_sent() ) {
			setcookie(
				self::COOKIE_NAME,
				$locale,
				array(
					'expires'  => $expires,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => $is_secure,
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		}

		return $descriptor;
	}

	/**
	 * Return the last cookie descriptor set during the current request.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_last_cookie_set(): ?array {
		return self::$last_cookie_set;
	}

	/**
	 * Build a same-URL language switch link that keeps the exact path and existing
	 * query parameters intact (never adding `/fr/` or `/de/` path segments).
	 *
	 * @param string $current_url   Current page URL or relative URI.
	 * @param string $target_locale Target locale code.
	 * @return string URL with existing path and query parameters plus `lp_set_lang`.
	 */
	public static function build_same_url_switch_link( string $current_url, string $target_locale ): string {
		$clean_target = self::sanitize_locale_code( $target_locale );
		$url          = '' !== trim( $current_url ) ? trim( $current_url ) : '/';

		$fragment = '';
		$hash_pos = strpos( $url, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $url, $hash_pos );
			$url      = substr( $url, 0, $hash_pos );
		}

		$query_str = '';
		$q_pos     = strpos( $url, '?' );
		if ( false !== $q_pos ) {
			$query_str = substr( $url, $q_pos + 1 );
			$url       = substr( $url, 0, $q_pos );
		}

		parse_str( $query_str, $query_params );
		if ( '' !== $clean_target ) {
			$query_params[ self::SWITCH_PARAM ] = $clean_target;
		}

		$rebuilt_query = http_build_query( $query_params, '', '&' );
		return $url . ( '' !== $rebuilt_query ? '?' . $rebuilt_query : '' ) . $fragment;
	}

	/**
	 * Strip only the transient `lp_set_lang` switch parameter from a URL while
	 * preserving the exact path and all other query parameters (e.g. `utm_source`, `sku`).
	 *
	 * @param string $url Input URL.
	 * @return string Canonical same-URL string with query parameters preserved.
	 */
	public static function preserve_canonical_url( string $url ): string {
		$trimmed = trim( $url );
		if ( '' === $trimmed ) {
			return '/';
		}

		$fragment = '';
		$hash_pos = strpos( $trimmed, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $trimmed, $hash_pos );
			$trimmed  = substr( $trimmed, 0, $hash_pos );
		}

		$q_pos = strpos( $trimmed, '?' );
		if ( false === $q_pos ) {
			return $trimmed . $fragment;
		}

		$base_path = substr( $trimmed, 0, $q_pos );
		$query_str = substr( $trimmed, $q_pos + 1 );
		parse_str( $query_str, $params );
		unset( $params[ self::SWITCH_PARAM ] );

		$rebuilt = http_build_query( $params, '', '&' );
		return $base_path . ( '' !== $rebuilt ? '?' . $rebuilt : '' ) . $fragment;
	}

	/**
	 * Resolve a suggested locale from trusted proxy GeoIP country headers (`CF-IPCountry`
	 * or `HTTP_X_COUNTRY_CODE`) ONLY when `trust_proxy_geoip` is explicitly enabled.
	 *
	 * @param array<string, mixed> $server_bag Server variables.
	 * @param array<string, mixed> $cfg        Storefront settings.
	 * @return string|null Matched enabled locale or null.
	 */
	private static function resolve_geoip_locale( array $server_bag, array $cfg ): ?string {
		if ( empty( $cfg['trust_proxy_geoip'] ) ) {
			return null;
		}

		$raw_country = '';
		if ( isset( $server_bag['HTTP_CF_IPCOUNTRY'] ) && is_string( $server_bag['HTTP_CF_IPCOUNTRY'] ) ) {
			$raw_country = strtoupper( trim( $server_bag['HTTP_CF_IPCOUNTRY'] ) );
		} elseif ( isset( $server_bag['HTTP_X_COUNTRY_CODE'] ) && is_string( $server_bag['HTTP_X_COUNTRY_CODE'] ) ) {
			$raw_country = strtoupper( trim( $server_bag['HTTP_X_COUNTRY_CODE'] ) );
		}

		if ( '' === $raw_country || 'XX' === $raw_country || 'T1' === $raw_country || 2 !== strlen( $raw_country ) ) {
			return null;
		}

		$country_map = isset( $cfg['geoip_country_map'] ) && is_array( $cfg['geoip_country_map'] )
			? $cfg['geoip_country_map']
			: array();

		if ( ! isset( $country_map[ $raw_country ] ) ) {
			return null;
		}

		return self::match_enabled_locale( (string) $country_map[ $raw_country ], (array) $cfg['enabled_locales'] );
	}
}
