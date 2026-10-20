<?php
/**
 * Unit tests for Stage 2 Admin UI bilingual locale resolution, per-admin
 * override, Fail-Closed form handling, and storefront locale independence.
 *
 * @package LocalePilot\Tests\Unit
 */

declare(strict_types=1);

namespace LocalePilot\Tests\Unit;

use LocalePilot\Admin\AdminLocaleManager;
use LocalePilot\Admin\AdminPage;
use LocalePilot\Admin\AdminTranslations;
use LocalePilot\Compatibility;
use PHPUnit\Framework\TestCase;

/**
 * Verifies AdminLocaleManager, AdminTranslations, and AdminPage behavior.
 */
final class AdminLocaleTest extends TestCase {

	/**
	 * Reset in-memory user meta before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		AdminLocaleManager::reset_memory_state();
	}

	/**
	 * Ensure locale normalization maps Chinese and English variants accurately.
	 *
	 * @return void
	 */
	public function test_locale_normalization(): void {
		$this->assertSame( 'zh_CN', AdminLocaleManager::normalize_locale( 'zh_CN' ) );
		$this->assertSame( 'zh_CN', AdminLocaleManager::normalize_locale( 'zh-CN' ) );
		$this->assertSame( 'zh_CN', AdminLocaleManager::normalize_locale( 'zh_Hans' ) );
		$this->assertSame( 'zh_CN', AdminLocaleManager::normalize_locale( 'zh' ) );

		$this->assertSame( 'en_US', AdminLocaleManager::normalize_locale( 'en_US' ) );
		$this->assertSame( 'en_US', AdminLocaleManager::normalize_locale( 'en_GB' ) );
		$this->assertSame( 'en_US', AdminLocaleManager::normalize_locale( 'en-AU' ) );
		$this->assertSame( 'en_US', AdminLocaleManager::normalize_locale( 'en' ) );

		$this->assertNull( AdminLocaleManager::normalize_locale( '' ) );
		$this->assertNull( AdminLocaleManager::normalize_locale( 'fr_FR' ) );
		$this->assertNull( AdminLocaleManager::normalize_locale( 'de_DE' ) );
	}

	/**
	 * Ensure admin UI language follows current admin user's profile language by default,
	 * and falls back to site language when user profile is set to "Site Default" (empty).
	 *
	 * @return void
	 */
	public function test_default_follows_admin_user_and_falls_back_to_site_locale(): void {
		$admin_id = 10;

		// Admin user profile explicitly set to Simplified Chinese while site is English.
		$this->assertSame(
			'zh_CN',
			AdminLocaleManager::resolve_admin_locale( $admin_id, 'zh_CN', 'en_US' )
		);

		// Admin user profile explicitly set to English while site is Simplified Chinese.
		$this->assertSame(
			'en_US',
			AdminLocaleManager::resolve_admin_locale( $admin_id, 'en_GB', 'zh_CN' )
		);

		// Admin user profile set to "Site Default" (empty string): falls back to site locale `zh_CN`.
		AdminLocaleManager::set_test_user_profile_locale( $admin_id, '' );
		$this->assertSame(
			'zh_CN',
			AdminLocaleManager::resolve_admin_locale( $admin_id, null, 'zh_CN' )
		);

		// Admin user profile set to "Site Default" (empty string): falls back to site locale `en_US`.
		$this->assertSame(
			'en_US',
			AdminLocaleManager::resolve_admin_locale( $admin_id, null, 'en_US' )
		);

		// Unsupported site locale falls back safely to `en_US`.
		$this->assertSame(
			'en_US',
			AdminLocaleManager::resolve_admin_locale( $admin_id, '', 'fr_FR' )
		);
	}

	/**
	 * Ensure per-admin account manual override works independently per user and can revert to auto.
	 *
	 * @return void
	 */
	public function test_per_admin_user_manual_override_is_isolated_per_account(): void {
		$admin_one = 101;
		$admin_two = 202;

		// Both users have WP profile set to Site Default, and site is en_US.
		AdminLocaleManager::set_test_user_profile_locale( $admin_one, '' );
		AdminLocaleManager::set_test_user_profile_locale( $admin_two, '' );

		// Admin 101 manually overrides to zh_CN.
		$this->assertTrue( AdminLocaleManager::set_user_override_mode( $admin_one, 'zh_CN' ) );
		$this->assertSame( 'zh_CN', AdminLocaleManager::get_user_override_mode( $admin_one ) );
		$this->assertSame( 'auto', AdminLocaleManager::get_user_override_mode( $admin_two ) );

		$this->assertSame( 'zh_CN', AdminLocaleManager::resolve_admin_locale( $admin_one, '', 'en_US' ) );
		$this->assertSame( 'en_US', AdminLocaleManager::resolve_admin_locale( $admin_two, '', 'en_US' ) );

		// Admin 202 manually overrides to en_US while site is zh_CN.
		$this->assertTrue( AdminLocaleManager::set_user_override_mode( $admin_two, 'en_US' ) );
		$this->assertSame( 'en_US', AdminLocaleManager::resolve_admin_locale( $admin_two, 'zh_CN', 'zh_CN' ) );

		// Admin 101 switches back to auto: now follows site locale again.
		$this->assertTrue( AdminLocaleManager::set_user_override_mode( $admin_one, 'auto' ) );
		$this->assertSame( 'auto', AdminLocaleManager::get_user_override_mode( $admin_one ) );
		$this->assertSame( 'en_US', AdminLocaleManager::resolve_admin_locale( $admin_one, '', 'en_US' ) );

		// Invalid modes or invalid user IDs are rejected.
		$this->assertFalse( AdminLocaleManager::set_user_override_mode( $admin_one, 'fr_FR' ) );
		$this->assertFalse( AdminLocaleManager::set_user_override_mode( 0, 'zh_CN' ) );
	}

	/**
	 * Ensure AdminPage form submission enforces Fail-Closed capability and nonce checks.
	 *
	 * @return void
	 */
	public function test_admin_page_form_submission_enforces_fail_closed_security(): void {
		$page     = new AdminPage();
		$admin_id = 303;

		$valid_post = array(
			'localepilot_action'           => AdminPage::FORM_ACTION,
			AdminPage::PARAM_MODE          => 'zh_CN',
			AdminLocaleManager::NONCE_NAME => 'valid_nonce_token',
		);

		// Missing capability -> rejected.
		$this->assertFalse(
			$page->handle_locale_switch_submission( $valid_post, $admin_id, false, true )
		);
		$this->assertSame( 'auto', AdminLocaleManager::get_user_override_mode( $admin_id ) );

		// Invalid nonce -> rejected.
		$this->assertFalse(
			$page->handle_locale_switch_submission( $valid_post, $admin_id, true, false )
		);
		$this->assertSame( 'auto', AdminLocaleManager::get_user_override_mode( $admin_id ) );

		// Valid capability and valid nonce -> accepted and persisted for that admin user.
		$this->assertTrue(
			$page->handle_locale_switch_submission( $valid_post, $admin_id, true, true )
		);
		$this->assertSame( 'zh_CN', AdminLocaleManager::get_user_override_mode( $admin_id ) );
	}

	/**
	 * Ensure bilingual UI renders Simplified Chinese and English accurately,
	 * and storefront context remains strictly independent.
	 *
	 * @return void
	 */
	public function test_bilingual_admin_ui_rendering_and_storefront_independence(): void {
		$page     = new AdminPage();
		$admin_id = 404;

		// Render in Simplified Chinese.
		AdminLocaleManager::set_user_override_mode( $admin_id, 'zh_CN' );
		$zh_html = $page->get_page_html( $admin_id, 'en_US', 'en_US', true );
		$this->assertStringContainsString( 'data-lp-admin-locale="zh_CN"', $zh_html );
		$this->assertStringContainsString( AdminTranslations::translate( 'page_title', 'zh_CN' ), $zh_html );
		$this->assertStringContainsString( AdminTranslations::translate( 'notice_saved', 'zh_CN' ), $zh_html );

		// Render in English.
		AdminLocaleManager::set_user_override_mode( $admin_id, 'en_US' );
		$en_html = $page->get_page_html( $admin_id, 'zh_CN', 'zh_CN', true );
		$this->assertStringContainsString( 'data-lp-admin-locale="en_US"', $en_html );
		$this->assertStringContainsString( AdminTranslations::translate( 'page_title', 'en_US' ), $en_html );
		$this->assertStringContainsString( AdminTranslations::translate( 'notice_saved', 'en_US' ), $en_html );

		// Storefront and wc-ajax requests are excluded from admin UI context.
		$this->assertFalse( AdminPage::is_admin_ui_context( false, array() ) );
		$this->assertFalse( AdminPage::is_admin_ui_context( true, array( 'wc-ajax' => 'get_refreshed_fragments' ) ) );
		$this->assertTrue( AdminPage::is_admin_ui_context( true, array() ) );

		// Visitor storefront cookie `lp_lang` is untouched.
		$this->assertArrayNotHasKey( Compatibility::COOKIE_NAME, $_COOKIE );
	}
}
