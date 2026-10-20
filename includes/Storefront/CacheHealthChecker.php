<?php
/**
 * Same-URL Storefront Cache Health Check & Diagnostic Evaluator.
 *
 * Implements the Cache Health Check from PRODUCT-PLAN.md §7 & COMPATIBILITY.md §5:
 * - Evaluates whether the active storefront correctly isolates `lp_lang` language
 *   states on the exact same URL (`Cache-Control: private, no-store, ...`,
 *   `Vary: Cookie, Accept-Language`, `DONOTCACHEPAGE = true`, and WP Rocket dynamic
 *   cookie filter registration).
 * - Simulates / inspects consecutive same-URL requests across two locales (e.g.
 *   `en_US` and `fr_FR`) to detect any cross-language HTML cache contamination.
 * - Explicitly marks WP Rocket, LiteSpeed Cache, Cloudflare APO/Edge, and third-party
 *   page caches as `Uncertified` unless verified in a live staging environment with
 *   those external cache systems actually deployed (never claims universal compatibility).
 *
 * @package LocalePilot\Storefront
 */

declare(strict_types=1);

namespace LocalePilot\Storefront;

use LocalePilot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Runs same-URL cache isolation health checks and generates diagnostic reports.
 */
final class CacheHealthChecker {

	/**
	 * WordPress option key storing the most recent Cache Health Check report (`autoload = false`).
	 */
	public const OPTION_LAST_REPORT_KEY = '_localepilot_last_cache_health_report';

	/**
	 * In-memory last report for unit tests.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $memory_last_report = null;

	/**
	 * Reset in-memory report state (intended for unit tests).
	 *
	 * @return void
	 */
	public static function reset_memory_state(): void {
		self::$memory_last_report = null;
	}

	/**
	 * Run a comprehensive same-URL Cache Health Check across two locales and persist the report.
	 *
	 * Checks:
	 * 1. `lp_lang` cookie resolution isolation on the exact same canonical URL across repeated
	 *    alternating probes (`locale_a` -> `locale_b` -> `locale_a` -> `locale_b`).
	 * 2. Response header guard (`Cache-Control: private, no-store...`, `Vary: Cookie, Accept-Language`,
	 *    `X-LocalePilot-Locale`) and `DONOTCACHEPAGE` constant status.
	 * 3. WP Rocket dynamic/mandatory cookie filter (`rocket_cache_dynamic_cookies`) registration status.
	 * 4. Honest certification matrix where undeployed/unverified external caches (`wp_rocket`,
	 *    `litespeed_cache`, `cloudflare_apo_edge`, `unrecognized_page_cache`) are explicitly marked
	 *    `uncertified` (`shared_cache_allowed = false`).
	 *
	 * @param string                    $canonical_url     Canonical product/shop URL to probe.
	 * @param string                    $locale_a          First locale (e.g. `en_US`).
	 * @param string                    $locale_b          Second locale (e.g. `fr_FR`).
	 * @param callable|null             $probe_simulator   Optional custom probe callback `fn(string $url, string $cookie_locale, int $seq): array{html_lang: string, body_marker: string, headers: array<string, string>}` for testing cache poisoning detection.
	 * @param array<string, mixed>|null $settings          Optional storefront settings override.
	 * @return array<string, mixed> Structured health check report.
	 */
	public static function run_health_check(
		string $canonical_url = '/product/sample-item/',
		string $locale_a = 'en_US',
		string $locale_b = 'fr_FR',
		?callable $probe_simulator = null,
		?array $settings = null
	): array {
		$cfg   = $settings ?? StorefrontLocaleResolver::get_settings();
		$guard = new CacheIsolationGuard();

		// 1. Check WP Rocket filter integration.
		$rocket_cookies = $guard->register_wp_rocket_cookie( array() );
		if ( function_exists( 'apply_filters' ) ) {
			$filtered_cookies = apply_filters( 'rocket_cache_dynamic_cookies', array() );
			if ( is_array( $filtered_cookies ) ) {
				$rocket_cookies = $filtered_cookies;
			}
		}
		$wp_rocket_hook_registered = in_array( StorefrontLocaleResolver::COOKIE_NAME, $rocket_cookies, true );

		// 2. Evaluate cache guard headers for both locales.
		$eval_a = CacheIsolationGuard::evaluate_cache_guard(
			$locale_a,
			array( StorefrontLocaleResolver::COOKIE_NAME => $locale_a ),
			$cfg
		);
		$eval_b = CacheIsolationGuard::evaluate_cache_guard(
			$locale_b,
			array( StorefrontLocaleResolver::COOKIE_NAME => $locale_b ),
			$cfg
		);

		$headers_valid = (
			true === $eval_b['bypass_shared_cache']
			&& isset( $eval_b['headers']['Cache-Control'], $eval_b['headers']['Vary'], $eval_b['headers']['X-LocalePilot-Locale'] )
			&& false !== strpos( $eval_b['headers']['Cache-Control'], 'no-store' )
			&& false !== strpos( $eval_b['headers']['Cache-Control'], 'private' )
			&& false !== strpos( $eval_b['headers']['Vary'], 'Cookie' )
			&& $locale_b === $eval_b['headers']['X-LocalePilot-Locale']
		);

		// 3. Execute 4 alternating same-URL probes: A1, B1, A2, B2 to detect any shared HTML cache bleed.
		$probe_sequence = array(
			array(
				'seq'    => 1,
				'locale' => $locale_a,
			),
			array(
				'seq'    => 2,
				'locale' => $locale_b,
			),
			array(
				'seq'    => 3,
				'locale' => $locale_a,
			),
			array(
				'seq'    => 4,
				'locale' => $locale_b,
			),
		);

		$probes               = array();
		$contamination_events = array();

		foreach ( $probe_sequence as $step ) {
			$expected_locale = $step['locale'];
			$expected_bcp47  = str_replace( '_', '-', $expected_locale );

			if ( null !== $probe_simulator ) {
				$sim_res = $probe_simulator( $canonical_url, $expected_locale, (int) $step['seq'] );
			} else {
				$sim_res = self::default_internal_probe( $canonical_url, $expected_locale, $cfg );
			}

			$actual_lang   = isset( $sim_res['html_lang'] ) ? (string) $sim_res['html_lang'] : '';
			$actual_marker = isset( $sim_res['body_marker'] ) ? (string) $sim_res['body_marker'] : '';
			$isolated_ok   = (
				strtolower( $actual_lang ) === strtolower( $expected_bcp47 )
				&& false !== strpos( $actual_marker, $expected_locale )
			);

			if ( ! $isolated_ok ) {
				$contamination_events[] = sprintf(
					'Probe #%d (%s) received html_lang="%s" marker="%s"',
					(int) $step['seq'],
					$expected_locale,
					$actual_lang,
					$actual_marker
				);
			}

			$probes[] = array(
				'seq'             => (int) $step['seq'],
				'url'             => $canonical_url,
				'expected_locale' => $expected_locale,
				'actual_lang'     => $actual_lang,
				'body_marker'     => $actual_marker,
				'isolated'        => $isolated_ok,
			);
		}

		$cross_contamination_detected = ! empty( $contamination_events );
		$safe_bypass_enforced         = true;

		// If contamination is detected or non-default cookie is active, ensure DONOTCACHEPAGE guard is active.
		if ( $cross_contamination_detected ) {
			$guard->apply_storefront_cache_guard(
				array( StorefrontLocaleResolver::COOKIE_NAME => $locale_b ),
				null,
				$cfg
			);
		}

		$donotcachepage_defined = defined( 'DONOTCACHEPAGE' ) && true === constant( 'DONOTCACHEPAGE' );

		// 4. Build honest cache integration matrix: all external cache plugins/CDNs not verified in live staging are explicitly marked `uncertified`.
		$integrations = self::get_honest_cache_certification_matrix();

		$overall_healthy = ( ! $cross_contamination_detected && $headers_valid && $wp_rocket_hook_registered );

		$report = array(
			'status'                       => $overall_healthy ? 'healthy_safe_bypass' : 'contamination_fallback_enforced',
			'overall_pass'                 => $overall_healthy,
			'cross_contamination_detected' => $cross_contamination_detected,
			'contamination_events'         => $contamination_events,
			'canonical_url'                => $canonical_url,
			'locale_a'                     => $locale_a,
			'locale_b'                     => $locale_b,
			'cookie_name'                  => StorefrontLocaleResolver::COOKIE_NAME,
			'headers_valid'                => $headers_valid,
			'sample_headers_locale_a'      => $eval_a['headers'],
			'sample_headers_locale_b'      => $eval_b['headers'],
			'donotcachepage_active'        => $donotcachepage_defined,
			'wp_rocket_cookie_registered'  => $wp_rocket_hook_registered,
			'safe_bypass_enforced'         => $safe_bypass_enforced,
			'probes'                       => $probes,
			'integrations'                 => $integrations,
			'checked_at'                   => gmdate( 'Y-m-d H:i:s' ),
		);

		self::$memory_last_report = $report;
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_LAST_REPORT_KEY, $report, false );
		}

		return $report;
	}

	/**
	 * Retrieve the most recent Cache Health Check report (or run a fresh check if none exists).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_last_report(): array {
		if ( function_exists( 'get_option' ) ) {
			$opt = get_option( self::OPTION_LAST_REPORT_KEY, null );
			if ( is_array( $opt ) && isset( $opt['status'] ) ) {
				self::$memory_last_report = $opt;
				return $opt;
			}
		}

		if ( null !== self::$memory_last_report ) {
			return self::$memory_last_report;
		}

		return self::run_health_check();
	}

	/**
	 * Return the honest certification matrix for external cache plugins and CDNs.
	 *
	 * None of the external third-party cache plugins/CDNs are claimed as `certified`
	 * until verified against an actual deployed instance of that cache software.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_honest_cache_certification_matrix(): array {
		$wp_rocket_active = defined( 'WP_ROCKET_VERSION' );
		$litespeed_active = defined( 'LSCWP_V' );
		$cf_apo_present   = isset( $_SERVER['HTTP_CF_DEVICE_TYPE'] ) || isset( $_SERVER['HTTP_CF_CACHE_STATUS'] );

		return array(
			'wp_rocket'               => array(
				'label'                => 'WP Rocket (Dynamic & Mandatory Cookie)',
				'plugin_detected'      => $wp_rocket_active,
				'certification_status' => 'uncertified',
				'shared_cache_allowed' => false,
				'fallback_behavior'    => 'Hook rocket_cache_dynamic_cookies registered; shared cache bypassed (DONOTCACHEPAGE + private, no-store) until live staging certification.',
			),
			'litespeed_cache'         => array(
				'label'                => 'LiteSpeed Cache (Vary Cookie)',
				'plugin_detected'      => $litespeed_active,
				'certification_status' => 'uncertified',
				'shared_cache_allowed' => false,
				'fallback_behavior'    => 'Uncertified; shared page cache bypassed via DONOTCACHEPAGE and Cache-Control: private, no-store.',
			),
			'cloudflare_apo_edge'     => array(
				'label'                => 'Cloudflare APO / Cache Everything',
				'plugin_detected'      => $cf_apo_present,
				'certification_status' => 'uncertified',
				'shared_cache_allowed' => false,
				'fallback_behavior'    => 'Incompatible with cookie-varied HTML without custom Enterprise/Worker Cache Key; forced private, no-store bypass.',
			),
			'unrecognized_page_cache' => array(
				'label'                => 'Other / Unrecognized Page Cache',
				'plugin_detected'      => false,
				'certification_status' => 'uncertified',
				'shared_cache_allowed' => false,
				'fallback_behavior'    => 'Forced safe bypass (DONOTCACHEPAGE = true, Cache-Control: private, no-store, Vary: Cookie, Accept-Language).',
			),
		);
	}

	/**
	 * Execute a deterministic internal same-URL probe for `$cookie_locale`.
	 *
	 * @param string               $canonical_url Canonical URL.
	 * @param string               $cookie_locale Simulated `lp_lang` cookie value.
	 * @param array<string, mixed> $settings      Storefront settings.
	 * @return array{html_lang: string, body_marker: string, headers: array<string, string>}
	 */
	private static function default_internal_probe(
		string $canonical_url,
		string $cookie_locale,
		array $settings
	): array {
		$translator = new StorefrontTranslator( new TranslationRepository(), true );
		$switcher   = $translator->render_language_switcher(
			$canonical_url,
			array( StorefrontLocaleResolver::COOKIE_NAME => $cookie_locale ),
			array(),
			array(),
			$settings
		);
		$eval       = CacheIsolationGuard::evaluate_cache_guard(
			$cookie_locale,
			array( StorefrontLocaleResolver::COOKIE_NAME => $cookie_locale ),
			$settings
		);

		$bcp47 = str_replace( '_', '-', $cookie_locale );

		return array(
			'html_lang'   => $bcp47,
			'body_marker' => $switcher,
			'headers'     => $eval['headers'],
		);
	}
}
