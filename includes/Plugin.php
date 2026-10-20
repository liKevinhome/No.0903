<?php
/**
 * Main plugin bootstrap class.
 *
 * @package LocalePilot
 */

declare(strict_types=1);

namespace LocalePilot;

use LocalePilot\Admin\AdminPage;
use LocalePilot\Storefront\CacheIsolationGuard;
use LocalePilot\Storefront\CartCheckoutCoordinator;
use LocalePilot\Storefront\OrderLocaleCoordinator;
use LocalePilot\Storefront\StorefrontTranslator;
use LocalePilot\Translation\TranslationQueueManager;
use LocalePilot\Translation\TranslationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates plugin lifecycle, environment readiness checks, and isolated
 * admin UI initialization.
 */
final class Plugin {

	/**
	 * Shared singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether the plugin has completed initialization.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Unmet environment requirement codes detected during init.
	 *
	 * @var array<int, string>
	 */
	private array $missing_requirements = array();

	/**
	 * Admin page controller instance.
	 *
	 * @var AdminPage|null
	 */
	private ?AdminPage $admin_page = null;

	/**
	 * Storefront translator instance.
	 *
	 * @var StorefrontTranslator|null
	 */
	private ?StorefrontTranslator $storefront_translator = null;

	/**
	 * Storefront cache isolation guard instance.
	 *
	 * @var CacheIsolationGuard|null
	 */
	private ?CacheIsolationGuard $cache_isolation_guard = null;

	/**
	 * Cart & checkout coordinator instance.
	 *
	 * @var CartCheckoutCoordinator|null
	 */
	private ?CartCheckoutCoordinator $cart_checkout_coordinator = null;

	/**
	 * Order locale snapshot & email coordinator instance.
	 *
	 * @var OrderLocaleCoordinator|null
	 */
	private ?OrderLocaleCoordinator $order_locale_coordinator = null;

	/**
	 * Background translation queue manager instance.
	 *
	 * @var TranslationQueueManager|null
	 */
	private ?TranslationQueueManager $translation_queue_manager = null;

	/**
	 * Retrieve the shared plugin instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Reset the singleton instance (intended for isolated unit tests).
	 *
	 * @return void
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}

	/**
	 * Private constructor to enforce singleton usage.
	 */
	private function __construct() {}

	/**
	 * Initialize plugin on `plugins_loaded`.
	 *
	 * @return bool True when environment requirements are satisfied and plugin booted.
	 */
	public function init(): bool {
		if ( function_exists( 'did_action' ) && ! did_action( 'init' ) && function_exists( 'add_action' ) ) {
			add_action( 'init', array( $this, 'load_textdomain' ) );
		} else {
			$this->load_textdomain();
		}

		$this->missing_requirements = Environment::get_missing_requirements();
		if ( ! empty( $this->missing_requirements ) ) {
			$this->booted = false;

			if ( function_exists( 'add_action' ) ) {
				add_action( 'admin_notices', array( $this, 'render_missing_requirements_notice' ) );
			}

			return false;
		}

		$this->booted     = true;
		$this->admin_page = new AdminPage();
		$this->admin_page->register_hooks();

		$this->storefront_translator = new StorefrontTranslator();
		$this->storefront_translator->register_hooks();

		$this->cache_isolation_guard = new CacheIsolationGuard();
		$this->cache_isolation_guard->register_hooks();

		$this->cart_checkout_coordinator = new CartCheckoutCoordinator( $this->storefront_translator );
		$this->cart_checkout_coordinator->register_hooks();

		$this->order_locale_coordinator = new OrderLocaleCoordinator( $this->storefront_translator );
		$this->order_locale_coordinator->register_hooks();

		$this->translation_queue_manager = new TranslationQueueManager();
		$this->translation_queue_manager->register_hooks();

		if ( function_exists( 'do_action' ) ) {
			/**
			 * Fires after LocalePilot has verified environment requirements and booted.
			 *
			 * @param self $plugin The booted plugin instance.
			 */
			do_action( 'localepilot_loaded', $this );
		}

		return true;
	}

	/**
	 * Return the admin page controller instance when booted.
	 *
	 * @return AdminPage|null
	 */
	public function get_admin_page(): ?AdminPage {
		return $this->admin_page;
	}

	/**
	 * Return the storefront translator instance when booted.
	 *
	 * @return StorefrontTranslator|null
	 */
	public function get_storefront_translator(): ?StorefrontTranslator {
		return $this->storefront_translator;
	}

	/**
	 * Return the storefront cache isolation guard instance when booted.
	 *
	 * @return CacheIsolationGuard|null
	 */
	public function get_cache_isolation_guard(): ?CacheIsolationGuard {
		return $this->cache_isolation_guard;
	}

	/**
	 * Return the cart & checkout coordinator instance when booted.
	 *
	 * @return CartCheckoutCoordinator|null
	 */
	public function get_cart_checkout_coordinator(): ?CartCheckoutCoordinator {
		return $this->cart_checkout_coordinator;
	}

	/**
	 * Return the order locale coordinator instance when booted.
	 *
	 * @return OrderLocaleCoordinator|null
	 */
	public function get_order_locale_coordinator(): ?OrderLocaleCoordinator {
		return $this->order_locale_coordinator;
	}

	/**
	 * Return the background translation queue manager instance when booted.
	 *
	 * @return TranslationQueueManager|null
	 */
	public function get_translation_queue_manager(): ?TranslationQueueManager {
		return $this->translation_queue_manager;
	}

	/**
	 * Load plugin translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		if ( function_exists( 'load_plugin_textdomain' ) ) {
			load_plugin_textdomain(
				'localepilot',
				false,
				dirname( LOCALEPILOT_BASENAME ) . '/languages'
			);
		}
	}

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! Environment::is_php_compatible() && function_exists( 'wp_die' ) && function_exists( 'esc_html__' ) ) {
			wp_die(
				esc_html__( 'LocalePilot requires PHP 7.4 or higher.', 'localepilot' ),
				esc_html__( 'Plugin Activation Error', 'localepilot' ),
				array( 'back_link' => true )
			);
		}

		$repo = new TranslationRepository();
		$repo->ensure_table();
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * Preserves existing merchant translations and store configuration.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Intentionally preserves local translations and store state per PRODUCT-PLAN.md.
	}

	/**
	 * Render an admin notice when required dependencies are missing or incompatible.
	 *
	 * @return void
	 */
	public function render_missing_requirements_notice(): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$message = function_exists( 'esc_html__' )
			? esc_html__( 'LocalePilot requires WordPress 6.2+, WooCommerce 8.0+, and PHP 7.4+ to operate.', 'localepilot' )
			: 'LocalePilot requires WordPress 6.2+, WooCommerce 8.0+, and PHP 7.4+ to operate.';

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			$message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above when esc_html__ is available.
		);
	}

	/**
	 * Whether the plugin booted with all environment requirements met.
	 *
	 * @return bool
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Return the list of missing environment requirement codes.
	 *
	 * @return array<int, string>
	 */
	public function get_missing_requirements(): array {
		return $this->missing_requirements;
	}

	/**
	 * Return the plugin version string.
	 *
	 * @return string
	 */
	public function get_version(): string {
		return defined( 'LOCALEPILOT_VERSION' ) ? (string) LOCALEPILOT_VERSION : '0.1.0';
	}
}
