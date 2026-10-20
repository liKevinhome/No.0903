<?php
/**
 * Bilingual (Simplified Chinese `zh_CN` & English `en_US`) dictionary for the
 * LocalePilot admin interface.
 *
 * @package LocalePilot\Admin
 */

declare(strict_types=1);

namespace LocalePilot\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies localized strings for the LocalePilot admin UI without mutating
 * global WordPress or storefront locales.
 */
final class AdminTranslations {

	/**
	 * Return the localized string catalog for a given admin UI locale.
	 *
	 * @param string $locale Admin UI locale (`zh_CN` or `en_US`).
	 * @return array<string, string>
	 */
	public static function get_catalog( string $locale ): array {
		$normalized = AdminLocaleManager::normalize_locale( $locale ) ?? AdminLocaleManager::LOCALE_EN_US;

		if ( AdminLocaleManager::LOCALE_ZH_CN === $normalized ) {
			return array(
				'page_title'                   => 'LocalePilot 同 URL 多语言商店设置',
				'menu_title'                   => 'LocalePilot',
				'header_subtitle'              => '同 URL 多语言 WooCommerce 扩展（后台界面语言与前台店铺语言相互独立）',
				'switcher_label'               => '后台界面语言（仅当前管理员账号）：',
				'mode_auto'                    => '跟随当前管理员 / 站点默认语言',
				'mode_zh_cn'                   => '简体中文 (zh_CN)',
				'mode_en_us'                   => 'English (en_US)',
				'save_button'                  => '切换语言',
				'notice_saved'                 => '已更新当前管理员账号的后台界面语言偏好。前台店铺语言不受影响。',
				'section_locale_status'        => '当前后台语言状态与隔离边界',
				'label_effective_admin_locale' => '当前生效后台语言',
				'label_override_mode'          => '当前管理员覆盖模式',
				'label_storefront_isolation'   => '前台店铺语言隔离状态',
				'value_storefront_isolated'    => '已隔离（后台语言切换不修改 WPLANG 站点语言或访客 lp_lang Cookie）',
				'section_compatibility'        => '运行环境与 WooCommerce 兼容状态',
				'label_hpos_status'            => 'WooCommerce HPOS (custom_order_tables)',
				'value_hpos_compatible'        => '已声明兼容 (true)',
				'label_blocks_status'          => 'Cart & Checkout Blocks (cart_checkout_blocks)',
				'value_blocks_mvp'             => '已声明兼容 (true，覆盖 Classic Cart/Checkout 与 Store API/Checkout Blocks)',
				'section_providers'            => '翻译供应商设置（BYOK 服务端调用，密钥不回显至浏览器）',
				'label_active_provider'        => '当前默认翻译供应商',
				'status_configured'            => '已配置',
				'status_not_configured'        => '未配置',
				'placeholder_secret_keep'      => '留空则保持已保存密钥不变',
				'button_save_providers'        => '保存供应商配置',
				'button_test_connection'       => '测试供应商连接',
				'section_manual_translate'     => '后台按需提交商品/界面文本翻译（禁止客户/订单/隐私字段）',
				'button_submit_translation'    => '提交翻译并持久化',
				'status_pending_review'        => '待审校 (pending_review)',
				'status_approved'              => '已审校通过 (approved)',
				'section_queue'                => 'Action Scheduler 后台异步翻译队列（指数退避重试、限流保护、不覆盖 approved 译文）',
				'section_cache_health'         => '同 URL 缓存健康自检（Cache Health Check）与第三方缓存认证状态',
				'button_run_cache_check'       => '执行缓存健康自检',
				'status_uncertified'           => 'Uncertified（未实机认证，默认绕过共享 HTML 缓存）',
			);
		}

		return array(
			'page_title'                   => 'LocalePilot Same-URL Multilingual Store Settings',
			'menu_title'                   => 'LocalePilot',
			'header_subtitle'              => 'Same-URL Multilingual WooCommerce Extension (Admin UI language is independent from storefront language)',
			'switcher_label'               => 'Admin UI Language (current admin user only):',
			'mode_auto'                    => 'Follow Admin User / Site Default Language',
			'mode_zh_cn'                   => '简体中文 (zh_CN)',
			'mode_en_us'                   => 'English (en_US)',
			'save_button'                  => 'Apply Language',
			'notice_saved'                 => 'Updated admin UI language preference for your account. Storefront visitor language remains unaffected.',
			'section_locale_status'        => 'Current Admin Language Status & Isolation Boundary',
			'label_effective_admin_locale' => 'Effective Admin UI Locale',
			'label_override_mode'          => 'Current Admin Override Mode',
			'label_storefront_isolation'   => 'Storefront Language Isolation',
			'value_storefront_isolated'    => 'Isolated (Admin UI language never modifies site WPLANG or visitor lp_lang cookie)',
			'section_compatibility'        => 'Runtime Environment & WooCommerce Compatibility',
			'label_hpos_status'            => 'WooCommerce HPOS (custom_order_tables)',
			'value_hpos_compatible'        => 'Declared Compatible (true)',
			'label_blocks_status'          => 'Cart & Checkout Blocks (cart_checkout_blocks)',
			'value_blocks_mvp'             => 'Declared Compatible (true; covers Classic Cart/Checkout & Store API/Checkout Blocks)',
			'section_providers'            => 'Translation Provider Settings (Server-side BYOK; secrets are never echoed to browser)',
			'label_active_provider'        => 'Active Translation Provider',
			'status_configured'            => 'Configured',
			'status_not_configured'        => 'Not configured',
			'placeholder_secret_keep'      => 'Leave blank to keep existing saved secret',
			'button_save_providers'        => 'Save Provider Settings',
			'button_test_connection'       => 'Test Provider Connection',
			'section_manual_translate'     => 'Submit Product / UI Text Translation (Customer & Order PII Prohibited)',
			'button_submit_translation'    => 'Translate & Save Locally',
			'status_pending_review'        => 'Pending Review (pending_review)',
			'status_approved'              => 'Approved (approved)',
			'section_queue'                => 'Action Scheduler Background Translation Queue (Bounded Exponential Backoff, Rate-Limit Guard, Protects Approved Translations)',
			'section_cache_health'         => 'Same-URL Cache Health Check & Third-Party Cache Certification Status',
			'button_run_cache_check'       => 'Run Cache Health Check',
			'status_uncertified'           => 'Uncertified (Not verified on live cache deployment; shared HTML cache bypassed by default)',
		);
	}

	/**
	 * Translate a single admin UI key for the given locale.
	 *
	 * @param string $key    Translation key.
	 * @param string $locale Target admin UI locale (`zh_CN` or `en_US`).
	 * @return string
	 */
	public static function translate( string $key, string $locale ): string {
		$catalog = self::get_catalog( $locale );
		return $catalog[ $key ] ?? $key;
	}
}
