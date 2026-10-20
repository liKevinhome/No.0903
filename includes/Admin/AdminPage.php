<?php
/**
 * Admin UI controller and view renderer for LocalePilot.
 *
 * Registers the LocalePilot admin page, processes per-admin user UI language
 * override submissions, manages translation provider configuration & connection
 * testing, and processes admin-submitted product/UI text translations (Fail-Closed
 * with capability & nonce checks) while keeping storefront locale strictly independent.
 *
 * @package LocalePilot\Admin
 */

declare(strict_types=1);

namespace LocalePilot\Admin;

use LocalePilot\Compatibility;
use LocalePilot\Storefront\CacheHealthChecker;
use LocalePilot\Translation\HttpClientInterface;
use LocalePilot\Translation\ProviderRegistry;
use LocalePilot\Translation\TranslationQueueManager;
use LocalePilot\Translation\TranslationRepository;
use LocalePilot\Translation\TranslationRequest;
use LocalePilot\Translation\TranslationResult;

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin menu registration, per-user language preference updates, provider
 * settings, connection tests, manual translation tasks, and UI rendering.
 */
final class AdminPage {

	/**
	 * Admin menu slug.
	 */
	public const MENU_SLUG = 'localepilot';

	/**
	 * Form action identifier for switching admin UI language.
	 */
	public const FORM_ACTION = 'localepilot_set_admin_ui_locale';

	/**
	 * Form action identifier for saving translation provider settings.
	 */
	public const FORM_ACTION_SAVE_PROVIDERS = 'localepilot_save_provider_settings';

	/**
	 * Form action identifier for testing provider connection.
	 */
	public const FORM_ACTION_TEST_CONNECTION = 'localepilot_test_provider_connection';

	/**
	 * Form action identifier for submitting a manual product/UI translation task.
	 */
	public const FORM_ACTION_SUBMIT_TRANSLATION = 'localepilot_submit_translation';

	/**
	 * Form action identifier for updating translation review status (`pending_review` / `approved`).
	 */
	public const FORM_ACTION_UPDATE_REVIEW_STATUS = 'localepilot_update_review_status';

	/**
	 * Form action identifier for enqueueing a background translation job via Action Scheduler.
	 */
	public const FORM_ACTION_ENQUEUE_TRANSLATION = 'localepilot_enqueue_translation_job';

	/**
	 * Form action identifier for cancelling a queued background translation job.
	 */
	public const FORM_ACTION_CANCEL_JOB = 'localepilot_cancel_translation_job';

	/**
	 * Form action identifier for running the same-URL Cache Health Check.
	 */
	public const FORM_ACTION_RUN_CACHE_HEALTH_CHECK = 'localepilot_run_cache_health_check';

	/**
	 * Request parameter key for the selected language mode.
	 */
	public const PARAM_MODE = 'localepilot_admin_ui_mode';

	/**
	 * Register WordPress admin hooks when in an admin (non-storefront AJAX) context.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! self::is_admin_ui_context() ) {
			return;
		}

		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_init', array( $this, 'handle_locale_switch_submission' ) );
			add_action( 'admin_init', array( $this, 'handle_provider_settings_submission' ) );
		}
	}

	/**
	 * Determine whether the current request is a genuine admin UI request
	 * and NOT a storefront `wc-ajax` request.
	 *
	 * @param bool|null                $is_admin_override Optional override for testing.
	 * @param array<string,mixed>|null $request_params    Optional request array override for testing.
	 * @return bool
	 */
	public static function is_admin_ui_context( ?bool $is_admin_override = null, ?array $request_params = null ): bool {
		$in_admin = $is_admin_override ?? ( function_exists( 'is_admin' ) ? is_admin() : false );
		if ( ! $in_admin ) {
			return false;
		}

		// Storefront WooCommerce AJAX requests (`?wc-ajax=...`) must never be treated as admin UI.
		$req = $request_params ?? $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context check.
		if ( isset( $req['wc-ajax'] ) && '' !== (string) $req['wc-ajax'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Check whether the current user has permission to manage LocalePilot admin settings.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage(): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Register the LocalePilot submenu under WooCommerce (or Settings fallback).
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$locale     = AdminLocaleManager::resolve_admin_locale();
		$page_title = AdminTranslations::translate( 'page_title', $locale );
		$menu_title = AdminTranslations::translate( 'menu_title', $locale );
		$capability = function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' )
			? 'manage_woocommerce'
			: 'manage_options';

		if ( function_exists( 'add_submenu_page' ) ) {
			add_submenu_page(
				'woocommerce',
				$page_title,
				$menu_title,
				$capability,
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);
		}
	}

	/**
	 * Handle POST submission for switching the current admin user's UI language preference.
	 *
	 * Enforces Fail-Closed security: requires admin context, capability (`manage_woocommerce`
	 * or `manage_options`), and a valid nonce before persisting to user meta.
	 *
	 * @param array<string, mixed>|null $post_data      Optional POST payload for unit testing.
	 * @param int|null                  $user_id        Optional user ID for unit testing.
	 * @param bool|null                 $has_capability Optional capability flag for unit testing.
	 * @param bool|null                 $nonce_valid    Optional nonce validity flag for unit testing.
	 * @return bool True when preference was updated, false when rejected or not applicable.
	 */
	public function handle_locale_switch_submission(
		?array $post_data = null,
		?int $user_id = null,
		?bool $has_capability = null,
		?bool $nonce_valid = null
	): bool {
		$payload = $post_data ?? $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified explicitly below.

		if ( ! isset( $payload['localepilot_action'] ) || self::FORM_ACTION !== (string) $payload['localepilot_action'] ) {
			return false;
		}

		if ( ! self::verify_admin_capability_and_nonce( $payload, AdminLocaleManager::NONCE_NAME, AdminLocaleManager::NONCE_ACTION, $has_capability, $nonce_valid ) ) {
			return false;
		}

		$target_user_id = $user_id ?? ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
		if ( $target_user_id <= 0 ) {
			return false;
		}

		$requested_mode = isset( $payload[ self::PARAM_MODE ] ) && is_string( $payload[ self::PARAM_MODE ] )
			? trim( $payload[ self::PARAM_MODE ] )
			: '';

		return AdminLocaleManager::set_user_override_mode( $target_user_id, $requested_mode );
	}

	/**
	 * Handle POST submission for saving translation provider settings and API credentials.
	 *
	 * Enforces capability + nonce checks and stores secrets locally via `ProviderSettingsManager`.
	 *
	 * @param array<string, mixed>|null $post_data      Optional POST payload for unit testing.
	 * @param bool|null                 $has_capability Optional capability flag for unit testing.
	 * @param bool|null                 $nonce_valid    Optional nonce validity flag for unit testing.
	 * @return bool True when provider settings were updated, false when rejected.
	 */
	public function handle_provider_settings_submission(
		?array $post_data = null,
		?bool $has_capability = null,
		?bool $nonce_valid = null
	): bool {
		$payload = $post_data ?? $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified explicitly below.

		if ( ! isset( $payload['localepilot_action'] ) || self::FORM_ACTION_SAVE_PROVIDERS !== (string) $payload['localepilot_action'] ) {
			return false;
		}

		if ( ! self::verify_admin_capability_and_nonce( $payload, ProviderSettingsManager::NONCE_NAME, ProviderSettingsManager::NONCE_ACTION, $has_capability, $nonce_valid ) ) {
			return false;
		}

		return ProviderSettingsManager::update_settings( $payload );
	}

	/**
	 * Handle admin POST action to test connection for the selected (or active) translation provider.
	 *
	 * @param array<string, mixed>|null $post_data      Optional POST payload for unit testing.
	 * @param bool|null                 $has_capability Optional capability flag for unit testing.
	 * @param bool|null                 $nonce_valid    Optional nonce validity flag for unit testing.
	 * @param HttpClientInterface|null  $http_client    Optional HTTP client override for testing.
	 * @return TranslationResult|null Probe result, or null when capability/nonce/action is invalid.
	 */
	public function handle_provider_connection_test(
		?array $post_data = null,
		?bool $has_capability = null,
		?bool $nonce_valid = null,
		?HttpClientInterface $http_client = null
	): ?TranslationResult {
		$payload = $post_data ?? $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified explicitly below.

		if ( ! isset( $payload['localepilot_action'] ) || self::FORM_ACTION_TEST_CONNECTION !== (string) $payload['localepilot_action'] ) {
			return null;
		}

		if ( ! self::verify_admin_capability_and_nonce( $payload, ProviderSettingsManager::NONCE_NAME, ProviderSettingsManager::NONCE_ACTION, $has_capability, $nonce_valid ) ) {
			return null;
		}

		$raw_settings = ProviderSettingsManager::get_raw_settings();
		$provider_id  = isset( $payload['provider_id'] ) && is_string( $payload['provider_id'] ) && '' !== trim( $payload['provider_id'] )
			? strtolower( trim( $payload['provider_id'] ) )
			: (string) $raw_settings['active_provider'];

		$registry = ProviderRegistry::create_default( $raw_settings, $http_client, true );
		$provider = $registry->get( $provider_id );
		if ( null === $provider ) {
			return TranslationResult::failure(
				$provider_id,
				TranslationResult::ERROR_PROVIDER_FAILURE,
				'Unknown translation provider: ' . $provider_id,
				false
			);
		}

		return $provider->validate_credentials();
	}

	/**
	 * Handle admin POST submission of a single product or UI string translation task.
	 *
	 * Strictly rejects customer/order/user-profile PII fields, deduplicates via
	 * `ProviderRegistry` + `TranslationRepository`, and persists with `pending_review` status.
	 *
	 * @param array<string, mixed>|null  $post_data      Optional POST payload.
	 * @param bool|null                  $has_capability Optional capability override.
	 * @param bool|null                  $nonce_valid    Optional nonce override.
	 * @param HttpClientInterface|null   $http_client    Optional HTTP client override.
	 * @param TranslationRepository|null $repository     Optional persistent repository override.
	 * @return TranslationResult|null Translation result, or null when capability/nonce/action is invalid.
	 */
	public function handle_manual_translation_submission(
		?array $post_data = null,
		?bool $has_capability = null,
		?bool $nonce_valid = null,
		?HttpClientInterface $http_client = null,
		?TranslationRepository $repository = null
	): ?TranslationResult {
		$payload = $post_data ?? $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified explicitly below.

		if ( ! isset( $payload['localepilot_action'] ) || self::FORM_ACTION_SUBMIT_TRANSLATION !== (string) $payload['localepilot_action'] ) {
			return null;
		}

		if ( ! self::verify_admin_capability_and_nonce( $payload, ProviderSettingsManager::NONCE_NAME, ProviderSettingsManager::NONCE_ACTION, $has_capability, $nonce_valid ) ) {
			return null;
		}

		$raw_settings  = ProviderSettingsManager::get_raw_settings();
		$provider_id   = isset( $payload['provider_id'] ) && is_string( $payload['provider_id'] ) && '' !== trim( $payload['provider_id'] )
			? strtolower( trim( $payload['provider_id'] ) )
			: (string) $raw_settings['active_provider'];
		$content_type  = isset( $payload['content_type'] ) && is_string( $payload['content_type'] )
			? strtolower( trim( $payload['content_type'] ) )
			: 'product_title';
		$object_id     = isset( $payload['object_id'] ) ? max( 0, (int) $payload['object_id'] ) : 0;
		$field_key     = isset( $payload['field_key'] ) && is_string( $payload['field_key'] ) && '' !== trim( $payload['field_key'] )
			? trim( $payload['field_key'] )
			: $content_type;
		$source_locale = isset( $payload['source_locale'] ) && is_string( $payload['source_locale'] ) && '' !== trim( $payload['source_locale'] )
			? trim( $payload['source_locale'] )
			: 'en_US';
		$target_locale = isset( $payload['target_locale'] ) && is_string( $payload['target_locale'] )
			? trim( $payload['target_locale'] )
			: '';
		$source_text   = isset( $payload['source_text'] ) && is_string( $payload['source_text'] )
			? $payload['source_text']
			: '';

		$glossary = array();
		if ( isset( $payload['glossary'] ) && is_array( $payload['glossary'] ) ) {
			foreach ( $payload['glossary'] as $k => $v ) {
				if ( is_string( $k ) && is_string( $v ) && '' !== trim( $k ) ) {
					$glossary[ trim( $k ) ] = trim( $v );
				}
			}
		}

		$request = new TranslationRequest(
			$source_text,
			$source_locale,
			$target_locale,
			$content_type,
			$glossary
		);

		// Reject any non-whitelisted or prohibited PII field before invoking provider.
		if ( $request->is_prohibited_pii_context() || ! TranslationRepository::is_allowed_content_type( $content_type ) ) {
			return TranslationResult::failure(
				$provider_id,
				TranslationResult::ERROR_PRIVACY_BLOCKED,
				'Only whitelisted product, taxonomy, and UI text fields may be submitted for translation.',
				false,
				0,
				$request->get_source_hash(),
				$target_locale
			);
		}

		$repo     = $repository ?? new TranslationRepository();
		$registry = ProviderRegistry::create_default( $raw_settings, $http_client, true, $repo );

		return $registry->translate_with_dedup( $provider_id, $request, $object_id, $field_key );
	}

	/**
	 * Handle admin POST action to transition a stored translation's review status
	 * between `pending_review` and `approved`.
	 *
	 * @param array<string, mixed>|null  $post_data      Optional POST payload.
	 * @param bool|null                  $has_capability Optional capability override.
	 * @param bool|null                  $nonce_valid    Optional nonce override.
	 * @param TranslationRepository|null $repository     Optional repository override.
	 * @return bool
	 */
	public function handle_review_status_submission(
		?array $post_data = null,
		?bool $has_capability = null,
		?bool $nonce_valid = null,
		?TranslationRepository $repository = null
	): bool {
		$payload = $post_data ?? $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified explicitly below.

		if ( ! isset( $payload['localepilot_action'] ) || self::FORM_ACTION_UPDATE_REVIEW_STATUS !== (string) $payload['localepilot_action'] ) {
			return false;
		}

		if ( ! self::verify_admin_capability_and_nonce( $payload, ProviderSettingsManager::NONCE_NAME, ProviderSettingsManager::NONCE_ACTION, $has_capability, $nonce_valid ) ) {
			return false;
		}

		$translation_id = isset( $payload['translation_id'] ) ? (int) $payload['translation_id'] : 0;
		$review_status  = isset( $payload['review_status'] ) && is_string( $payload['review_status'] )
			? trim( $payload['review_status'] )
			: '';

		$repo = $repository ?? new TranslationRepository();
		return $repo->update_review_status( $translation_id, $review_status );
	}

	/**
	 * Handle admin POST action to enqueue an asynchronous translation job via Action Scheduler.
	 *
	 * Enforces capability + nonce checks, rejects customer/order/PII fields, deduplicates,
	 * and protects existing `approved` translations.
	 *
	 * @param array<string, mixed>|null    $post_data      Optional POST payload.
	 * @param bool|null                    $has_capability Optional capability override.
	 * @param bool|null                    $nonce_valid    Optional nonce override.
	 * @param TranslationQueueManager|null $queue_manager  Optional queue manager override.
	 * @return array<string, mixed>|null Enqueue result array, or null when capability/nonce/action is invalid.
	 */
	public function handle_enqueue_translation_submission(
		?array $post_data = null,
		?bool $has_capability = null,
		?bool $nonce_valid = null,
		?TranslationQueueManager $queue_manager = null
	): ?array {
		$payload = $post_data ?? $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified explicitly below.

		if ( ! isset( $payload['localepilot_action'] ) || self::FORM_ACTION_ENQUEUE_TRANSLATION !== (string) $payload['localepilot_action'] ) {
			return null;
		}

		if ( ! self::verify_admin_capability_and_nonce( $payload, ProviderSettingsManager::NONCE_NAME, ProviderSettingsManager::NONCE_ACTION, $has_capability, $nonce_valid ) ) {
			return null;
		}

		$raw_settings = ProviderSettingsManager::get_raw_settings();
		$provider_id  = isset( $payload['provider_id'] ) && is_string( $payload['provider_id'] ) && '' !== trim( $payload['provider_id'] )
			? strtolower( trim( $payload['provider_id'] ) )
			: (string) $raw_settings['active_provider'];

		$queue = $queue_manager ?? new TranslationQueueManager( new TranslationRepository(), null, true );

		return $queue->enqueue_job(
			array(
				'provider_id'   => $provider_id,
				'content_type'  => $payload['content_type'] ?? 'product_title',
				'object_id'     => $payload['object_id'] ?? 0,
				'field_key'     => $payload['field_key'] ?? '',
				'source_locale' => $payload['source_locale'] ?? 'en_US',
				'target_locale' => $payload['target_locale'] ?? '',
				'source_text'   => $payload['source_text'] ?? '',
				'glossary'      => isset( $payload['glossary'] ) && is_array( $payload['glossary'] ) ? $payload['glossary'] : array(),
			)
		);
	}

	/**
	 * Handle admin POST action to cancel a `pending` or `retry` translation job in the queue.
	 *
	 * @param array<string, mixed>|null    $post_data      Optional POST payload.
	 * @param bool|null                    $has_capability Optional capability override.
	 * @param bool|null                    $nonce_valid    Optional nonce override.
	 * @param TranslationQueueManager|null $queue_manager  Optional queue manager override.
	 * @return bool
	 */
	public function handle_cancel_job_submission(
		?array $post_data = null,
		?bool $has_capability = null,
		?bool $nonce_valid = null,
		?TranslationQueueManager $queue_manager = null
	): bool {
		$payload = $post_data ?? $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified explicitly below.

		if ( ! isset( $payload['localepilot_action'] ) || self::FORM_ACTION_CANCEL_JOB !== (string) $payload['localepilot_action'] ) {
			return false;
		}

		if ( ! self::verify_admin_capability_and_nonce( $payload, ProviderSettingsManager::NONCE_NAME, ProviderSettingsManager::NONCE_ACTION, $has_capability, $nonce_valid ) ) {
			return false;
		}

		$job_id = isset( $payload['job_id'] ) && is_string( $payload['job_id'] ) ? trim( $payload['job_id'] ) : '';
		$queue  = $queue_manager ?? new TranslationQueueManager( new TranslationRepository(), null, true );

		return $queue->cancel_job( $job_id );
	}

	/**
	 * Handle admin POST action to run the same-URL Cache Health Check.
	 *
	 * @param array<string, mixed>|null $post_data      Optional POST payload.
	 * @param bool|null                 $has_capability Optional capability override.
	 * @param bool|null                 $nonce_valid    Optional nonce override.
	 * @return array<string, mixed>|null Health check report, or null when capability/nonce/action is invalid.
	 */
	public function handle_cache_health_check_submission(
		?array $post_data = null,
		?bool $has_capability = null,
		?bool $nonce_valid = null
	): ?array {
		$payload = $post_data ?? $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified explicitly below.

		if ( ! isset( $payload['localepilot_action'] ) || self::FORM_ACTION_RUN_CACHE_HEALTH_CHECK !== (string) $payload['localepilot_action'] ) {
			return null;
		}

		if ( ! self::verify_admin_capability_and_nonce( $payload, ProviderSettingsManager::NONCE_NAME, ProviderSettingsManager::NONCE_ACTION, $has_capability, $nonce_valid ) ) {
			return null;
		}

		$url      = isset( $payload['canonical_url'] ) && is_string( $payload['canonical_url'] ) && '' !== trim( $payload['canonical_url'] )
			? trim( $payload['canonical_url'] )
			: '/product/sample-item/';
		$locale_a = isset( $payload['locale_a'] ) && is_string( $payload['locale_a'] ) && '' !== trim( $payload['locale_a'] )
			? trim( $payload['locale_a'] )
			: 'en_US';
		$locale_b = isset( $payload['locale_b'] ) && is_string( $payload['locale_b'] ) && '' !== trim( $payload['locale_b'] )
			? trim( $payload['locale_b'] )
			: 'fr_FR';

		return CacheHealthChecker::run_health_check( $url, $locale_a, $locale_b );
	}

	/**
	 * Build the HTML markup for the LocalePilot admin page in the resolved admin locale.
	 *
	 * Never outputs raw API keys or secrets into HTML attributes or text nodes.
	 *
	 * @param int|null    $user_id             Optional user ID override.
	 * @param string|null $user_profile_locale Optional user profile locale override.
	 * @param string|null $site_locale         Optional site default locale override.
	 * @param bool        $saved_notice        Whether to include the saved confirmation notice.
	 * @return string Escaped HTML string.
	 */
	public function get_page_html(
		?int $user_id = null,
		?string $user_profile_locale = null,
		?string $site_locale = null,
		bool $saved_notice = false
	): string {
		$effective_locale = AdminLocaleManager::resolve_admin_locale( $user_id, $user_profile_locale, $site_locale );
		$override_mode    = AdminLocaleManager::get_user_override_mode( $user_id );
		$catalog          = AdminTranslations::get_catalog( $effective_locale );
		$matrix           = Compatibility::get_feature_matrix();
		$masked_providers = ProviderSettingsManager::get_masked_view_data();

		$locale_nonce_field   = '';
		$provider_nonce_field = '';
		if ( function_exists( 'wp_nonce_field' ) ) {
			$locale_nonce_field   = (string) wp_nonce_field( AdminLocaleManager::NONCE_ACTION, AdminLocaleManager::NONCE_NAME, true, false );
			$provider_nonce_field = (string) wp_nonce_field( ProviderSettingsManager::NONCE_ACTION, ProviderSettingsManager::NONCE_NAME, true, false );
		}

		$html  = '<div class="wrap lp-admin-wrap" data-lp-admin-locale="' . self::escape_attr( $effective_locale ) . '">';
		$html .= '<h1>' . self::escape_html( $catalog['page_title'] ) . '</h1>';
		$html .= '<p class="description">' . self::escape_html( $catalog['header_subtitle'] ) . '</p>';

		if ( $saved_notice ) {
			$html .= '<div class="notice notice-success is-dismissible"><p>' . self::escape_html( $catalog['notice_saved'] ) . '</p></div>';
		}

		// Per-admin user language switcher form.
		$html .= '<form method="post" class="lp-admin-locale-form">';
		$html .= '<input type="hidden" name="localepilot_action" value="' . self::escape_attr( self::FORM_ACTION ) . '" />';
		$html .= $locale_nonce_field;
		$html .= '<label for="lp-admin-ui-mode"><strong>' . self::escape_html( $catalog['switcher_label'] ) . '</strong></label> ';
		$html .= '<select id="lp-admin-ui-mode" name="' . self::escape_attr( self::PARAM_MODE ) . '">';

		$options = array(
			AdminLocaleManager::MODE_AUTO    => $catalog['mode_auto'],
			AdminLocaleManager::LOCALE_ZH_CN => $catalog['mode_zh_cn'],
			AdminLocaleManager::LOCALE_EN_US => $catalog['mode_en_us'],
		);

		foreach ( $options as $mode_key => $label ) {
			$selected = ( $override_mode === $mode_key ) ? ' selected="selected"' : '';
			$html    .= '<option value="' . self::escape_attr( $mode_key ) . '"' . $selected . '>' . self::escape_html( $label ) . '</option>';
		}

		$html .= '</select> ';
		$html .= '<button type="submit" class="button button-primary">' . self::escape_html( $catalog['save_button'] ) . '</button>';
		$html .= '</form>';

		// Admin locale & storefront isolation status table.
		$html .= '<h2>' . self::escape_html( $catalog['section_locale_status'] ) . '</h2>';
		$html .= '<table class="widefat striped lp-admin-status-table"><tbody>';
		$html .= '<tr><th>' . self::escape_html( $catalog['label_effective_admin_locale'] ) . '</th><td><code>' . self::escape_html( $effective_locale ) . '</code></td></tr>';
		$html .= '<tr><th>' . self::escape_html( $catalog['label_override_mode'] ) . '</th><td><code>' . self::escape_html( $override_mode ) . '</code></td></tr>';
		$html .= '<tr><th>' . self::escape_html( $catalog['label_storefront_isolation'] ) . '</th><td>' . self::escape_html( $catalog['value_storefront_isolated'] ) . '</td></tr>';
		$html .= '</tbody></table>';

		// Translation provider settings form (secrets masked, password inputs always empty).
		$active_prov = (string) $masked_providers['active_provider'];
		$html       .= '<h2>' . self::escape_html( $catalog['section_providers'] ) . '</h2>';
		$html       .= '<form method="post" class="lp-provider-settings-form" data-lp-active-provider="' . self::escape_attr( $active_prov ) . '">';
		$html       .= '<input type="hidden" name="localepilot_action" value="' . self::escape_attr( self::FORM_ACTION_SAVE_PROVIDERS ) . '" />';
		$html       .= $provider_nonce_field;
		$html       .= '<table class="widefat striped lp-provider-table"><tbody>';

		$html .= '<tr><th>' . self::escape_html( $catalog['label_active_provider'] ) . '</th><td>';
		$html .= '<select name="active_provider">';
		foreach ( ProviderRegistry::SUPPORTED_PROVIDER_IDS as $pid ) {
			$sel   = ( $active_prov === $pid ) ? ' selected="selected"' : '';
			$html .= '<option value="' . self::escape_attr( $pid ) . '"' . $sel . '>' . self::escape_html( $pid ) . '</option>';
		}
		$html .= '</select></td></tr>';

		// Gemini row.
		$gem_status = $masked_providers['gemini']['configured']
			? $catalog['status_configured'] . ' (' . $masked_providers['gemini']['masked_key'] . ')'
			: $catalog['status_not_configured'];
		$html      .= '<tr><th>Google Gemini</th><td>';
		$html      .= '<span class="lp-cred-status" data-lp-provider="gemini">' . self::escape_html( $gem_status ) . '</span> ';
		$html      .= '<input type="password" name="gemini_api_key" value="" autocomplete="off" placeholder="' . self::escape_attr( $catalog['placeholder_secret_keep'] ) . '" /> ';
		$html      .= '<input type="text" name="gemini_model" value="' . self::escape_attr( (string) $masked_providers['gemini']['model'] ) . '" />';
		$html      .= '</td></tr>';

		// DeepL row.
		$dl_status = $masked_providers['deepl']['configured']
			? $catalog['status_configured'] . ' (' . $masked_providers['deepl']['masked_key'] . ')'
			: $catalog['status_not_configured'];
		$html     .= '<tr><th>DeepL</th><td>';
		$html     .= '<span class="lp-cred-status" data-lp-provider="deepl">' . self::escape_html( $dl_status ) . '</span> ';
		$html     .= '<input type="password" name="deepl_api_key" value="" autocomplete="off" placeholder="' . self::escape_attr( $catalog['placeholder_secret_keep'] ) . '" />';
		$html     .= '</td></tr>';

		// Google Cloud row.
		$gc_status = $masked_providers['google_cloud']['configured']
			? $catalog['status_configured'] . ' (' . $masked_providers['google_cloud']['masked_key'] . ')'
			: $catalog['status_not_configured'];
		$html     .= '<tr><th>Google Cloud Translation</th><td>';
		$html     .= '<span class="lp-cred-status" data-lp-provider="google_cloud">' . self::escape_html( $gc_status ) . '</span> ';
		$html     .= '<input type="password" name="google_cloud_api_key" value="" autocomplete="off" placeholder="' . self::escape_attr( $catalog['placeholder_secret_keep'] ) . '" />';
		$html     .= '</td></tr>';

		// Baidu row.
		$bd_status = $masked_providers['baidu']['configured']
			? $catalog['status_configured'] . ' (' . $masked_providers['baidu']['masked_app_id'] . ' / ' . $masked_providers['baidu']['masked_app_secret'] . ')'
			: $catalog['status_not_configured'];
		$html     .= '<tr><th>Baidu Translate</th><td>';
		$html     .= '<span class="lp-cred-status" data-lp-provider="baidu">' . self::escape_html( $bd_status ) . '</span> ';
		$html     .= '<input type="password" name="baidu_app_id" value="" autocomplete="off" placeholder="' . self::escape_attr( $catalog['placeholder_secret_keep'] ) . '" /> ';
		$html     .= '<input type="password" name="baidu_app_secret" value="" autocomplete="off" placeholder="' . self::escape_attr( $catalog['placeholder_secret_keep'] ) . '" />';
		$html     .= '</td></tr>';

		$html .= '</tbody></table>';
		$html .= '<p><button type="submit" class="button button-primary">' . self::escape_html( $catalog['button_save_providers'] ) . '</button></p>';
		$html .= '</form>';

		// Compatibility status table.
		$html .= '<h2>' . self::escape_html( $catalog['section_compatibility'] ) . '</h2>';
		$html .= '<table class="widefat striped lp-admin-compat-table"><tbody>';
		$html .= '<tr><th>' . self::escape_html( $catalog['label_hpos_status'] ) . '</th><td>' . self::escape_html( $matrix['custom_order_tables'] ? $catalog['value_hpos_compatible'] : 'false' ) . '</td></tr>';
		$html .= '<tr><th>' . self::escape_html( $catalog['label_blocks_status'] ) . '</th><td>' . self::escape_html( $matrix['cart_checkout_blocks'] ? $catalog['value_blocks_mvp'] : 'false' ) . '</td></tr>';
		$html .= '</tbody></table>';

		// Cache Health Check & Uncertified Cache Matrix section.
		$cache_report = CacheHealthChecker::get_last_report();
		$html        .= '<h2>' . self::escape_html( $catalog['section_cache_health'] ) . '</h2>';
		$html        .= '<div class="lp-cache-health-panel" data-lp-cache-health-status="' . self::escape_attr( (string) $cache_report['status'] ) . '" data-lp-cookie-name="' . self::escape_attr( (string) $cache_report['cookie_name'] ) . '">';
		$html        .= '<table class="widefat striped lp-cache-health-table"><tbody>';
		$html        .= '<tr><th>Cookie</th><td><code>' . self::escape_html( (string) $cache_report['cookie_name'] ) . '</code></td></tr>';
		$html        .= '<tr><th>Cache-Control (' . self::escape_html( (string) $cache_report['locale_b'] ) . ')</th><td><code>' . self::escape_html( (string) ( $cache_report['sample_headers_locale_b']['Cache-Control'] ?? '' ) ) . '</code></td></tr>';
		$html        .= '<tr><th>Vary</th><td><code>' . self::escape_html( (string) ( $cache_report['sample_headers_locale_b']['Vary'] ?? '' ) ) . '</code></td></tr>';
		$html        .= '<tr><th>DONOTCACHEPAGE</th><td><code>' . self::escape_html( $cache_report['donotcachepage_active'] ? 'true' : 'enforced_on_storefront' ) . '</code></td></tr>';
		$html        .= '<tr><th>WP Rocket Hook (rocket_cache_dynamic_cookies)</th><td><code>' . self::escape_html( $cache_report['wp_rocket_cookie_registered'] ? 'registered' : 'not_registered' ) . '</code></td></tr>';

		foreach ( $cache_report['integrations'] as $cache_id => $cache_info ) {
			$html .= '<tr data-lp-cache-integration="' . self::escape_attr( (string) $cache_id ) . '">';
			$html .= '<th>' . self::escape_html( (string) $cache_info['label'] ) . '</th>';
			$html .= '<td><span class="lp-cert-badge" data-lp-cert-status="' . self::escape_attr( (string) $cache_info['certification_status'] ) . '">' . self::escape_html( $catalog['status_uncertified'] ) . '</span> — ' . self::escape_html( (string) $cache_info['fallback_behavior'] ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table></div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Output the LocalePilot admin page HTML.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! self::current_user_can_manage() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside get_page_html().
		echo $this->get_page_html();
	}

	/**
	 * Verify admin capability and nonce (Fail-Closed).
	 *
	 * @param array<string, mixed> $payload        Request payload.
	 * @param string               $nonce_name     Nonce field name.
	 * @param string               $nonce_action   Nonce action name.
	 * @param bool|null            $has_capability Optional capability override for testing.
	 * @param bool|null            $nonce_valid    Optional nonce override for testing.
	 * @return bool
	 */
	private static function verify_admin_capability_and_nonce(
		array $payload,
		string $nonce_name,
		string $nonce_action,
		?bool $has_capability,
		?bool $nonce_valid
	): bool {
		$can_manage = $has_capability ?? self::current_user_can_manage();
		if ( ! $can_manage ) {
			return false;
		}

		$is_nonce_ok = $nonce_valid;
		if ( null === $is_nonce_ok ) {
			$raw_nonce   = isset( $payload[ $nonce_name ] ) && is_string( $payload[ $nonce_name ] )
				? $payload[ $nonce_name ]
				: '';
			$is_nonce_ok = '' !== $raw_nonce
				&& function_exists( 'wp_verify_nonce' )
				&& false !== wp_verify_nonce( $raw_nonce, $nonce_action );
		}

		return $is_nonce_ok;
	}

	/**
	 * Escape HTML text safely using WordPress `esc_html` or native `htmlspecialchars`.
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
	 * Escape HTML attribute safely using WordPress `esc_attr` or native `htmlspecialchars`.
	 *
	 * @param string $text Raw attribute value.
	 * @return string
	 */
	private static function escape_attr( string $text ): string {
		if ( function_exists( 'esc_attr' ) ) {
			return esc_attr( $text );
		}

		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
