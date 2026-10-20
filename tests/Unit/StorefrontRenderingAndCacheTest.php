<?php
/**
 * Unit tests for Same-URL Storefront Locale Resolution, Local Approved
 * Translation Reading, Same-URL Language Switcher, and Cache Isolation Guard.
 *
 * @package LocalePilot\Tests\Unit
 */

declare(strict_types=1);

namespace LocalePilot\Tests\Unit;

use LocalePilot\Storefront\CacheIsolationGuard;
use LocalePilot\Storefront\StorefrontLocaleResolver;
use LocalePilot\Storefront\StorefrontTranslator;
use LocalePilot\Translation\TranslationRepository;
use LocalePilot\Translation\TranslationResult;
use PHPUnit\Framework\TestCase;

/**
 * Verifies same-URL storefront locale priority, approved-only translation rendering,
 * query parameter preservation, and cache isolation headers.
 */
final class StorefrontRenderingAndCacheTest extends TestCase {

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
	 * Verify locale resolution priority:
	 * Manual Switch (`lp_set_lang`) > `lp_lang` Cookie > `Accept-Language` > Trusted GeoIP > Default Locale,
	 * and verify invalid/unenabled locales are safely ignored.
	 *
	 * @return void
	 */
	public function test_locale_resolution_priority_and_invalid_locale_filtering(): void {
		$settings = array(
			'default_locale'    => 'en_US',
			'enabled_locales'   => array( 'en_US', 'fr_FR', 'de_DE', 'es_ES', 'ja_JP' ),
			'trust_proxy_geoip' => true,
			'geoip_country_map' => array(
				'FR' => 'fr_FR',
				'DE' => 'de_DE',
				'JP' => 'ja_JP',
			),
			'cookie_ttl_days'   => 30,
			'cache_mode'        => 'safe_bypass',
		);

		// 1. Default fallback when no cookie, no Accept-Language, no GeoIP.
		$res_default = StorefrontLocaleResolver::resolve_visitor_locale_details( array(), array(), array(), $settings );
		$this->assertSame( 'en_US', $res_default['locale'] );
		$this->assertSame( 'default', $res_default['source'] );

		// 2. Trusted GeoIP fallback when `trust_proxy_geoip = true` and no cookie/Accept-Language.
		$res_geoip = StorefrontLocaleResolver::resolve_visitor_locale_details(
			array(),
			array( 'HTTP_CF_IPCOUNTRY' => 'JP' ),
			array(),
			$settings
		);
		$this->assertSame( 'ja_JP', $res_geoip['locale'] );
		$this->assertSame( 'geoip_fallback', $res_geoip['source'] );
		$this->assertSame( 'ja_JP', $res_geoip['geoip_suggestion'] );

		// 2b. Untrusted GeoIP is ignored when `trust_proxy_geoip = false`.
		$untrusted_settings                      = $settings;
		$untrusted_settings['trust_proxy_geoip'] = false;
		$res_untrusted_geo                       = StorefrontLocaleResolver::resolve_visitor_locale_details(
			array(),
			array( 'HTTP_CF_IPCOUNTRY' => 'JP' ),
			array(),
			$untrusted_settings
		);
		$this->assertSame( 'en_US', $res_untrusted_geo['locale'] );
		$this->assertSame( 'default', $res_untrusted_geo['source'] );
		$this->assertNull( $res_untrusted_geo['geoip_suggestion'] );

		// 3. Browser `Accept-Language` overrides GeoIP fallback and respects q-weights.
		$res_browser = StorefrontLocaleResolver::resolve_visitor_locale_details(
			array(),
			array(
				'HTTP_ACCEPT_LANGUAGE' => 'ru-RU,ru;q=0.9,es-MX;q=0.7,de-DE;q=0.8',
				'HTTP_CF_IPCOUNTRY'    => 'JP',
			),
			array(),
			$settings
		);
		$this->assertSame( 'de_DE', $res_browser['locale'], 'Highest q-weight enabled locale (de-DE q=0.8 > es-MX q=0.7; ru-RU not enabled) must win.' );
		$this->assertSame( 'accept_language', $res_browser['source'] );

		// 4. Visitor `lp_lang` Cookie overrides both `Accept-Language` and GeoIP.
		$res_cookie = StorefrontLocaleResolver::resolve_visitor_locale_details(
			array( StorefrontLocaleResolver::COOKIE_NAME => 'fr_FR' ),
			array(
				'HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9',
				'HTTP_CF_IPCOUNTRY'    => 'JP',
			),
			array(),
			$settings
		);
		$this->assertSame( 'fr_FR', $res_cookie['locale'] );
		$this->assertSame( 'cookie', $res_cookie['source'] );

		// 5. Explicit manual switch (`lp_set_lang`) overrides previous cookie and sets new `lp_lang` cookie.
		$res_switch = StorefrontLocaleResolver::resolve_visitor_locale_details(
			array( StorefrontLocaleResolver::COOKIE_NAME => 'fr_FR' ),
			array( 'HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9' ),
			array( StorefrontLocaleResolver::SWITCH_PARAM => 'es_ES' ),
			$settings
		);
		$this->assertSame( 'es_ES', $res_switch['locale'] );
		$this->assertSame( 'manual_switch', $res_switch['source'] );
		$cookie_set = StorefrontLocaleResolver::get_last_cookie_set();
		$this->assertNotNull( $cookie_set );
		$this->assertSame( 'lp_lang', $cookie_set['name'] );
		$this->assertSame( 'es_ES', $cookie_set['value'] );
		$this->assertSame( 'Lax', $cookie_set['samesite'] );

		// 6. Invalid, malicious, or disabled locales in cookie and request params are ignored and fall through.
		foreach ( array( '../../etc/passwd', '<script>alert(1)</script>', 'ru_RU', 'invalid_locale_999' ) as $bad_locale ) {
			$res_bad = StorefrontLocaleResolver::resolve_visitor_locale_details(
				array( StorefrontLocaleResolver::COOKIE_NAME => $bad_locale ),
				array( 'HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9' ),
				array( StorefrontLocaleResolver::SWITCH_PARAM => $bad_locale ),
				$settings
			);
			$this->assertSame( 'fr_FR', $res_bad['locale'] );
			$this->assertSame( 'accept_language', $res_bad['source'] );
		}
	}

	/**
	 * Verify same-URL link construction, query parameter preservation, and switcher HTML output.
	 *
	 * @return void
	 */
	public function test_same_url_query_preservation_and_switcher_rendering(): void {
		$original_url = '/product/waterproof-jacket/?utm_source=instagram&color=olive&lp_set_lang=en_US#details';
		$canonical    = StorefrontLocaleResolver::preserve_canonical_url( $original_url );

		$this->assertSame( '/product/waterproof-jacket/?utm_source=instagram&color=olive#details', $canonical );
		$this->assertStringNotContainsString( '/fr/', $canonical );
		$this->assertStringNotContainsString( '/de/', $canonical );

		$fr_switch_url = StorefrontLocaleResolver::build_same_url_switch_link( $canonical, 'fr_FR' );
		$this->assertSame( '/product/waterproof-jacket/?utm_source=instagram&color=olive&lp_set_lang=fr_FR#details', $fr_switch_url );

		$settings   = array(
			'default_locale'    => 'en_US',
			'enabled_locales'   => array( 'en_US', 'fr_FR', 'de_DE' ),
			'trust_proxy_geoip' => true,
			'geoip_country_map' => array( 'FR' => 'fr_FR' ),
			'cookie_ttl_days'   => 30,
			'cache_mode'        => 'safe_bypass',
		);
		$translator = new StorefrontTranslator( new TranslationRepository(), true );
		$html       = $translator->render_language_switcher(
			'/product/waterproof-jacket/?utm_source=instagram&color=olive',
			array(),
			array( 'HTTP_CF_IPCOUNTRY' => 'FR' ),
			array(),
			$settings
		);

		$this->assertStringContainsString( 'data-lp-current-locale="fr_FR"', $html );
		$this->assertStringContainsString( 'data-lp-canonical-url="/product/waterproof-jacket/?utm_source=instagram&amp;color=olive"', $html );
		$this->assertStringContainsString( 'utm_source=instagram&amp;color=olive&amp;lp_set_lang=de_DE', $html );
		$this->assertStringContainsString( 'data-lp-geoip-suggestion="fr_FR"', $html );
	}

	/**
	 * Verify storefront rendering reads ONLY `approved` translations, falls back to
	 * original text on `pending_review`, missing, or stale source hash, and never
	 * mutates admin editing screens.
	 *
	 * @return void
	 */
	public function test_storefront_renders_approved_only_and_falls_back_on_pending_missing_or_stale(): void {
		StorefrontLocaleResolver::update_settings(
			array(
				'default_locale'  => 'en_US',
				'enabled_locales' => array( 'en_US', 'fr_FR', 'de_DE', 'es_ES' ),
			)
		);

		$repo       = new TranslationRepository();
		$orig_title = 'Alpine Down Jacket';
		$orig_desc  = 'Warm winter down jacket for extreme cold.';

		// Save French translation as `approved`.
		$fr_title_id = $repo->save(
			array(
				'object_type'     => 'product_title',
				'object_id'       => 701,
				'field_key'       => 'post_title',
				'source_locale'   => 'en_US',
				'target_locale'   => 'fr_FR',
				'source_hash'     => hash( 'sha256', $orig_title ),
				'provider_id'     => 'deepl',
				'translated_text' => 'Doudoune alpine',
				'review_status'   => TranslationResult::STATUS_APPROVED,
			)
		);
		$repo->save(
			array(
				'object_type'     => 'product_description',
				'object_id'       => 701,
				'field_key'       => 'post_content',
				'source_locale'   => 'en_US',
				'target_locale'   => 'fr_FR',
				'source_hash'     => hash( 'sha256', $orig_desc ),
				'provider_id'     => 'deepl',
				'translated_text' => 'Doudoune chaude pour grand froid.',
				'review_status'   => TranslationResult::STATUS_APPROVED,
			)
		);

		// Save German translation as `pending_review` (must NOT appear on storefront yet).
		$repo->save(
			array(
				'object_type'     => 'product_title',
				'object_id'       => 701,
				'field_key'       => 'post_title',
				'source_locale'   => 'en_US',
				'target_locale'   => 'de_DE',
				'source_hash'     => hash( 'sha256', $orig_title ),
				'provider_id'     => 'deepl',
				'translated_text' => 'Alpine Daunenjacke',
				'review_status'   => TranslationResult::STATUS_PENDING_REVIEW,
			)
		);

		$this->assertGreaterThan( 0, $fr_title_id );

		$storefront_translator = new StorefrontTranslator( $repo, true );
		$admin_translator      = new StorefrontTranslator( $repo, false );

		// 1. Default locale (`en_US`) -> returns original English text.
		$_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] = 'en_US';
		$this->assertSame( $orig_title, $storefront_translator->filter_product_title( $orig_title, 701 ) );
		$this->assertSame( 'lang="en-US"', $storefront_translator->filter_language_attributes( 'lang="en-US"' ) );

		// 2. French (`fr_FR`, `approved`) on the SAME product ID -> returns approved French translation.
		$_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] = 'fr_FR';
		$this->assertSame( 'Doudoune alpine', $storefront_translator->filter_product_title( $orig_title, 701 ) );
		$this->assertSame( 'Doudoune chaude pour grand froid.', $storefront_translator->filter_product_description( $orig_desc, 701 ) );
		$this->assertSame( 'lang="fr-FR"', $storefront_translator->filter_language_attributes( 'lang="en-US"' ) );

		// 3. In wp-admin context (`force_storefront_context = false`), original English text is preserved even when `lp_lang=fr_FR`.
		$this->assertSame( $orig_title, $admin_translator->filter_product_title( $orig_title, 701 ) );

		// 4. German (`de_DE`, `pending_review`) -> falls back to original English text until approved.
		$_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] = 'de_DE';
		$this->assertSame( $orig_title, $storefront_translator->filter_product_title( $orig_title, 701 ) );

		// 5. Spanish (`es_ES`, missing translation) -> falls back to original English text.
		$_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] = 'es_ES';
		$this->assertSame( $orig_title, $storefront_translator->filter_product_title( $orig_title, 701 ) );

		// 6. Stale source hash (when merchant edits the English product title to a new string) -> falls back to new English string.
		$_COOKIE[ StorefrontLocaleResolver::COOKIE_NAME ] = 'fr_FR';
		$this->assertSame(
			'Alpine Down Jacket 2027 Edition',
			$storefront_translator->filter_product_title( 'Alpine Down Jacket 2027 Edition', 701 )
		);
	}

	/**
	 * Verify CacheIsolationGuard WP Rocket cookie registration, certification matrix,
	 * and safe cache-bypass header enforcement.
	 *
	 * @return void
	 */
	public function test_cache_isolation_guard_headers_and_wp_rocket_cookie_registration(): void {
		$guard   = new CacheIsolationGuard();
		$cookies = $guard->register_wp_rocket_cookie( array( 'woocommerce_items_in_cart' ) );

		$this->assertContains( 'lp_lang', $cookies );
		$this->assertCount( 2, $guard->register_wp_rocket_cookie( $cookies ), 'Must not duplicate lp_lang.' );

		$matrix = CacheIsolationGuard::get_cache_integrations_status();
		$this->assertArrayHasKey( 'wp_rocket', $matrix );
		$this->assertArrayHasKey( 'litespeed_cache', $matrix );
		$this->assertArrayHasKey( 'cloudflare_apo_edge', $matrix );
		$this->assertFalse( $matrix['cloudflare_apo_edge']['shared_cache_allowed'] );
		$this->assertFalse( $matrix['unrecognized_page_cache']['shared_cache_allowed'] );

		// When `lp_lang=fr_FR` cookie is active, shared HTML cache MUST be bypassed.
		$decision_fr = CacheIsolationGuard::evaluate_cache_guard(
			'fr_FR',
			array( StorefrontLocaleResolver::COOKIE_NAME => 'fr_FR' ),
			StorefrontLocaleResolver::get_defaults()
		);
		$this->assertTrue( $decision_fr['bypass_shared_cache'] );
		$this->assertSame( 'lp_lang_cookie_present_safe_bypass', $decision_fr['reason'] );
		$this->assertSame( 'Cookie, Accept-Language', $decision_fr['headers']['Vary'] );
		$this->assertSame( 'fr_FR', $decision_fr['headers']['X-LocalePilot-Locale'] );
		$this->assertStringContainsString( 'no-store', $decision_fr['headers']['Cache-Control'] );
		$this->assertStringContainsString( 'private', $decision_fr['headers']['Cache-Control'] );
	}
}
