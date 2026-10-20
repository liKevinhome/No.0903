# LocalePilot Woo Marketplace 提交准备与发布阻断评估报告（MARKETPLACE_READINESS）

**生成日期：** 2026-10-20  
**插件工作名：** `LocalePilot`（正式品牌名：`TBD`）  
**当前插件版本：** `0.1.0`  
**关联文档：** [PRODUCT-PLAN.md](PRODUCT-PLAN.md)、[COMPATIBILITY.md](COMPATIBILITY.md)、[README.md](README.md)

> [!IMPORTANT]
> **当前是否可直接向 Woo Marketplace 提交审核？**  
> **结论：`不可直接提交（NO — Blocked by External & Branding Prerequisites）`。**  
> 本地核心代码、零 Composer 运行时打包、静态规范门禁（PHPCS/PHPUnit）以及 **WordPress (Latest `7.1.3` / L-1 `7.0.7`) × WooCommerce (Latest `11.2.1` / L-1 `11.1.2`) 四格交叉矩阵**均已在本机 WSL 隔离环境 `100%` 实测通过；但因**正式品牌/域名元数据尚未定稿（`TBD`）**、**根目录 `changelog.txt` 尚未创建**、**官方 QIT 云端托管测试尚未连接 Partner 账号执行**、以及**第三方缓存/支付网关/主题 Staging 实测与真实付费翻译 API 联调尚未执行**，当前处于**本地技术就绪、待补齐外部与商业阻断项**状态。

---

## 1. 本地已验证的 PHP / WordPress / WooCommerce 矩阵与精确命令证据

### 1.1 静态质量门禁与真实 PHPUnit 单元测试（本机 `PHP 8.3.6`）

| 检查项 | 精确执行命令 | 退出码 | 实测结果与证据 |
|---|---|---|---|
| **PHP 语法检查 (`php -l`)** | `wsl -d Ubuntu-24.04 -e bash -lc 'cd "/mnt/e/Codex/.codex/跨境/same-url-multilingual-plugin" && count=0; for f in $(find localepilot.php uninstall.php includes tests bin -name "*.php" \| sort); do php -l "$f" \|\| exit 1; count=$((count+1)); done; echo "PHP_LINT_TOTAL=$count"'` | `0` | **`PHP_LINT_TOTAL=40`**（`40 / 40` 文件 `No syntax errors detected`） |
| **Composer 配置严格校验** | `wsl -d Ubuntu-24.04 -e bash -lc 'cd "/mnt/e/Codex/.codex/跨境/same-url-multilingual-plugin" && COMPOSER_ALLOW_SUPERUSER=1 php .tools/composer.phar validate --strict'` | `0` | `./composer.json is valid` |
| **PHPCS 编码与兼容规范 (`composer lint`)** | `wsl -d Ubuntu-24.04 -e bash -lc 'cd "/mnt/e/Codex/.codex/跨境/same-url-multilingual-plugin" && COMPOSER_ALLOW_SUPERUSER=1 php .tools/composer.phar lint'` | `0` | `PHP_CodeSniffer 3.13.6`（`WordPress-Extra` + `WordPress-Docs` + `PHPCompatibilityWP` `testVersion 7.4-8.3`）：**`38 / 38 (100%)`**，**`0 Errors, 0 Warnings`** |
| **真实 PHPUnit 单元测试 (`composer test`)** | `wsl -d Ubuntu-24.04 -e bash -lc 'cd "/mnt/e/Codex/.codex/跨境/same-url-multilingual-plugin" && COMPOSER_ALLOW_SUPERUSER=1 php .tools/composer.phar test'` | `0` | `PHPUnit 9.6.38`：**`OK (33 tests, 470 assertions, 0 failures, 0 errors)`** |

### 1.2 WordPress (Latest / L-1) × WooCommerce (Latest / L-1) 四格隔离集成矩阵

版本数字均通过官方 `https://api.wordpress.org/core/version-check/1.7/`（含官方 SHA-1 校验）与 `https://api.wordpress.org/plugins/info/1.0/woocommerce.json` 实时解析，在 WSL `PHP 8.3.6` + 隔离 `MariaDB 10.11.14` (`127.0.0.1:13307`) 中实测：

| 矩阵格 | 实际解析版本 (`WP_VERSION` / `WC_VERSION`) | 精确执行命令 | 隔离站点目录与数据库 | 退出码 | 原始最终标志 |
|---|---|---|---|---|---|
| **latest × latest** | `WP 7.1.3` / `WC 11.2.1` | `php bin/run-wp-wc-integration.php --wp-version=latest --wc-version=latest` | `.tools/wp-test-site`<br>`localepilot_isolated_wp_test` | `0` | `SUCCESS: Isolated WordPress + WooCommerce + HPOS integration verification passed.` |
| **latest × L-1** | `WP 7.1.3` / `WC 11.1.2` | `php bin/run-wp-wc-integration.php --wp-version=latest --wc-version=previous-major` | `.tools/wp-test-site-wp7_1_3-wc11_1_2`<br>`lp_iso_wp7_1_3_wc11_1_2` | `0` | `SUCCESS: Isolated WordPress + WooCommerce + HPOS integration verification passed.` |
| **L-1 × latest** | `WP 7.0.7` / `WC 11.2.1` | `php bin/run-wp-wc-integration.php --wp-version=previous-major --wc-version=latest` | `.tools/wp-test-site-wp7_0_7-wc11_2_1`<br>`lp_iso_wp7_0_7_wc11_2_1` | `0` | `SUCCESS: Isolated WordPress + WooCommerce + HPOS integration verification passed.` |
| **L-1 × L-1** | `WP 7.0.7` / `WC 11.1.2` | `php bin/run-wp-wc-integration.php --wp-version=previous-major --wc-version=previous-major` | `.tools/wp-test-site-wp7_0_7-wc11_1_2`<br>`lp_iso_wp7_0_7_wc11_1_2` | `0` | `SUCCESS: Isolated WordPress + WooCommerce + HPOS integration verification passed.` |

#### 四格集成测试共同通过的 23 项原始验证标志：
- 启动与特性注册：`LOCALEPILOT_BOOTED=true`、`HPOS_FEATURESUTIL_REGISTERED=true`、`BLOCKS_FEATURESUTIL_REGISTERED=true`
- 后台双语与前台隔离：`ADMIN_BILINGUAL_AND_OVERRIDE_VERIFIED=true`、`STOREFRONT_WPLANG_ISOLATED=true`
- 供应商设置、掩码与 `$wpdb` 持久层：`PROVIDER_SETTINGS_MASKED_NO_PLAINTEXT_ECHO=true`、`PROVIDER_CONNECTION_TEST_VERIFIED=true`、`WPDB_PERSISTENCE_DEDUP_AND_REVIEW_VERIFIED=true`、`TRANSLATION_PROVIDERS_REGISTERED=gemini,deepl,google_cloud,baidu`、`TRANSLATION_STOREFRONT_GUARD_VERIFIED=true`
- 同 URL 前台渲染与缓存守卫：`SAME_URL_MULTILINGUAL_STOREFRONT_VERIFIED=true`、`STOREFRONT_APPROVED_ONLY_AND_PENDING_FALLBACK=true`、`STOREFRONT_ZERO_EXTERNAL_API_CALLS=true`、`CACHE_ISOLATION_GUARD_AND_DONOTCACHEPAGE=true`
- 购物车、结账、Blocks Store API、HPOS 订单语言与邮件：`WC_CART_AND_COUPON_MULTILINGUAL_ZERO_AMOUNT_DIFF=true`、`WC_AJAX_MINI_CART_AND_REAL_COUPON_NOTICE_VERIFIED=true`、`WC_SHIPPING_PAYMENT_CHECKOUT_FIELDS_VERIFIED=true`、`WC_STORE_API_BLOCKS_CART_ENDPOINT_VERIFIED=true`、`HPOS_ORDER_LOCALE_SNAPSHOT_CLASSIC_AND_BLOCKS_VERIFIED=true`、`ORDER_EMAIL_SNAPSHOT_LOCALE_SWITCH_AND_RESTORE_VERIFIED=true`、`WC_EMAIL_CUSTOMER_PROCESSING_ORDER_HTML_VERIFIED=true`
- Action Scheduler 队列、订阅降级与缓存健康自检：`ACTION_SCHEDULER_RUNTIME_LOADED=true`、`ACTION_SCHEDULER_QUEUE_DEDUP_CANCEL_AND_APPROVED_PROTECTION_VERIFIED=true`、`SUBSCRIPTION_EXPIRED_PROGRESSIVE_DEGRADATION_VERIFIED=true`、`CACHE_HEALTH_CHECK_AND_UNCERTIFIED_MATRIX_VERIFIED=true`
- 停用与重激活：`PLUGIN_DEACTIVATED=true`、`PLUGIN_REACTIVATED=true`

### 1.3 纯净发行 ZIP 包与零 Composer Runtime 证据

- **构建命令**：`pwsh -NoProfile -ExecutionPolicy Bypass -File ./bin/build-dist-zip.ps1`（退出码 `0`）
- **生成产物**：[`dist/localepilot.zip`](dist/localepilot.zip)
- **条目数**：`ENTRY_COUNT: 31`（仅包含 `localepilot/localepilot.php`、`localepilot/uninstall.php` 与 `localepilot/includes/**/*.php` 共 31 个运行时 PHP 文件）。
- **零开发依赖与从包安装核验**：
  - [`.distignore`](.distignore) 与 [`bin/build-dist-zip.ps1`](bin/build-dist-zip.ps1) 强制排除并校验归档中不含 `vendor/`、`composer.json`、`composer.lock`、`composer.phar`、`wp-cli.phar`、`.tools/`、`tests/`、`phpcs.xml.dist`、`phpunit.xml.dist`、`docker-compose.yml`、`bin/`、`.github/` 及项目根目录 `.md` 文档。
  - 上述四格 WP/WC 集成测试全部通过 `unzip` 直接将 `dist/localepilot.zip` 解压至 `wp-content/plugins/localepilot` 安装运行，并逐格通过断言：`Verified installed plugin directory contains ZERO Composer/vendor/test files.`

---

## 2. 插件头部元数据、架构声明与打包核验表（实值与证据）

| 检查项 | 当前代码实值 | 代码位置与证据 | Marketplace 就绪状态 |
|---|---|---|---|
| **Plugin Name** | `LocalePilot` | [`localepilot.php:3`](localepilot.php#L3) | **待确认 (`TBD`)**：当前为开发工作名，待确认正式品牌名 |
| **Plugin URI** | `https://example.com/localepilot` | [`localepilot.php:4`](localepilot.php#L4) | **阻断 (`TBD`)**：占位 URI，提交前必须替换为真实产品落地页 URL |
| **Description** | `Same-URL multilingual storefront extension for WooCommerce using pre-translated local content.` | [`localepilot.php:5`](localepilot.php#L5) | 已就绪（可随正式品牌文案微调） |
| **Version** | `0.1.0`（常量 `LOCALEPILOT_VERSION = '0.1.0'`） | [`localepilot.php:6,25`](localepilot.php#L6) | **待确认 (`TBD`)**：正式首发若定为 `1.0.0` 需同步修改 |
| **Requires at least (WP)** | `6.2`（常量 `Environment::MIN_WP_VERSION = '6.2'`） | [`localepilot.php:7`](localepilot.php#L7)、[`Environment.php:27`](includes/Environment.php#L27) | 已就绪（已在 `WP 7.1.3` & `7.0.7` 实测） |
| **Requires PHP** | `7.4`（常量 `Environment::MIN_PHP_VERSION = '7.4.0'`） | [`localepilot.php:8`](localepilot.php#L8)、[`Environment.php:22`](includes/Environment.php#L22) | 头部与静态检查已就绪（`PHP 8.3.6` 已实测，`7.4–8.2` 运行时待外部 CI/QIT 执行） |
| **Author / Author URI** | `Author: LocalePilot Contributors`（未设 `Author URI`） | [`localepilot.php:9`](localepilot.php#L9) | **阻断 (`TBD`)**：需替换为真实公司/开发者名称与官网 URI |
| **License / License URI** | `GPL-3.0-or-later` / `https://www.gnu.org/licenses/gpl-3.0.html` | [`localepilot.php:10-11`](localepilot.php#L10-L11)、[`composer.json:5`](composer.json#L5) | 已就绪（100% GPL，无代码混淆或加密锁） |
| **Text Domain / Domain Path** | `localepilot` / `/languages` | [`localepilot.php:12-13`](localepilot.php#L12-L13) | 已就绪 |
| **WC requires at least / WC tested up to** | `8.0` / `11.2`（常量 `Environment::MIN_WC_VERSION = '8.0.0'`） | [`localepilot.php:14-15`](localepilot.php#L14-L15)、[`Environment.php:32`](includes/Environment.php#L32) | 已就绪（已在 `WC 11.2.1` & `11.1.2` 实测） |
| **Woo Header (`Woo:`)** | 未设置 | [`localepilot.php`](localepilot.php) | **阻断 (`TBD`)**：在 Woo Marketplace 后台创建商品分配 `product_id:hash` 后注入 |
| **HPOS (`custom_order_tables`) 声明** | `true`（通过 `FeaturesUtil::declare_compatibility` 注册） | [`Compatibility.php:41-45,70-82`](includes/Compatibility.php#L41-L82) | 已就绪（四格矩阵均验证 `HPOS_FEATURESUTIL_REGISTERED=true` 且 `wp_postmeta` 0 写入） |
| **Cart & Checkout Blocks (`cart_checkout_blocks`) 声明** | `true`（通过 `FeaturesUtil::declare_compatibility` 注册） | [`Compatibility.php:41-45,70-82`](includes/Compatibility.php#L41-L82) | 已就绪（四格矩阵均验证 `BLOCKS_FEATURESUTIL_REGISTERED=true` 与 `/wc/store/v1/cart`） |
| **根目录 `changelog.txt`** | 未创建 | 仓库根目录 | **阻断 (`TBD`)**：Woo Marketplace 打包验证要求提供标准格式 `changelog.txt` |

---

## 3. Woo Marketplace / QIT 官方必需测试类别与准备材料（引用本地只读研究清单）

根据本地只读研究子代理对 `developer.woocommerce.com/docs/marketplace/` 与 `qit.woo.com` 的核查清单，提交 Woo Marketplace 必须满足以下官方门禁与材料要求：

1. **QIT (Quality Insights Toolkit) 6 类托管测试**：
   - **`Activation Test`**：在全新 WP + WC 环境中安装激活插件、访问后台菜单、创建商品/订单、执行访客结账并停用，要求 **0 Fatal Error / 0 PHP Warning & Notice**。
   - **`Security Test`**：运行 PHPCS (`WordPress.Security` / `WordPress.DB`)、SemGrep、依赖漏洞扫描与硬编码密钥检测。
   - **`PHPCompatibility Test`**：扫描目标 PHP 版本范围（`7.4 – 8.3+`）内的语法兼容性与弃用函数。
   - **`Malware Test`**：扫描混淆代码、`eval`/`shell_exec`、异常 Base64 与未授权外部后门回调。
   - **`Woo E2E / Woo API Tests`**：基于 Playwright 的核心购物链路 E2E 测试及 WooCommerce REST / Store API 回归测试。
   - **`Validation / Packaging Test`**：校验 ZIP 结构、主文件头部标签（含 `WC requires at least`、`WC tested up to`、`Woo` header）与 `changelog.txt`。
2. **隐私与外部服务披露要求（GDPR / External API Disclosure）**：
   - 必须在插件文档与商品页明确披露外部翻译 API 端点（`Gemini`、`DeepL`、`Google Cloud Translation`、`Baidu Translate`）、触发时机（仅后台管理员连接测试与 Action Scheduler 异步队列，前台访客请求 **0 次调用**）及传输数据范围（仅白名单商品/分类/UI 文本，**严禁并已拦截客户姓名、邮箱、电话、地址、支付信息等订单 PII**）。
3. **商业模式与诚实边界披露**：
   - 明确披露 **BYOK（商家自带翻译供应商 API Key 并直接向供应商付费）** 模式（或最终敲定的定价方案 `TBD`）。
   - 明确披露 **同 URL 多语言模式不生成独立语言 URL（如 `/fr/`）且不输出 `hreflang`，不支持多语言自然搜索 SEO 收录**。
   - 明确披露 **订阅过期后保留商家本地全部已保存译文继续在前台和邮件中正常展示，绝不删除数据或阻断结账**。

---

## 4. 已完成兼容范围 vs. 未认证（Uncertified）边界汇总

| 领域 | 已在本地完成并实测验证（Verified Locally） | 未认证 / 未实测边界（Uncertified — 代码与文档中显式声明） |
|---|---|---|
| **运行环境矩阵** | `PHP 8.3.6` + `MariaDB 10.11.14` 下的 `WP 7.1.3 / 7.0.7` × `WC 11.2.1 / 11.1.2` 四格完整集成测试 | `PHP 7.4 / 8.0 / 8.1 / 8.2` 运行时、更早 `WP 6.2–6.x` / `WC 8.0–11.0.x`、GitHub Actions 远端流水线（`.github/workflows/ci.yml` 仅保留配置，未在远端运行） |
| **前台同 URL 与缓存** | `lp_lang` Cookie > `Accept-Language` > 可信代理 IP 国家提示 > 默认语言；仅渲染 `approved` 译文；`CacheIsolationGuard` 下发 `DONOTCACHEPAGE=true`、`Cache-Control: private, no-store`、`Vary: Cookie, Accept-Language`；`CacheHealthChecker` 四次交替探测 | `WP Rocket`、`LiteSpeed Cache`、`Cloudflare APO / Edge Cache` 及未知主机层静态缓存均未在本机安装实测，在 `CacheHealthChecker` 中强制标记为 **`uncertified` (`shared_cache_allowed = false`)** |
| **购物车 / 结账 / 订单邮件** | Classic Cart/Checkout、`wc-ajax` 迷你购物车、优惠券提示、运费标签、内置网关（`bacs`/`cod`/`cheque`）、Store API `/wc/store/v1/cart`、HPOS `_localepilot_order_locale` 快照、订单邮件语言切换与金额 `0` 偏差 | 第三方支付网关插件（Stripe/PayPal/WooPayments 等跨域托管 iframe 内部 DOM）、第三方多币种插件（WOOCS/YayCurrency 等）、真实浏览器 Playwright React Checkout Blocks 交互及第三方 Blocks 私有前端组件（标记为 **`Uncertified`**） |
| **后台异步翻译队列与供应商** | WooCommerce 内置 Action Scheduler 异步队列（去重、状态流转、`30s->60s->120s` 指数退避、限流冷却、取消任务、保护已有 `approved` 译文不被覆盖）、4 个供应商适配器本地 HTTP Mock 测试 | **真实外部付费翻译 API 联网调用为 0 次（`Uncertified`）** |
| **主题与页面构建器** | 标准 WordPress / WooCommerce 数据层 Filter 注入（不强行覆写主题模板） | Storefront、Astra、Flatsome、WoodMart 等主题真实前端 DOM 及 Elementor/Divi/Bricks 页面构建器私有结构（标记为 **`Uncertified`**） |

---

## 5. 发布阻断清单（Release Blockers before Marketplace Submission）

以下阻断项全部解决前，**不得将安装包上传至 Woo Marketplace 或向生产客户发布**：

1. **[Blocker-1] 正式品牌、域名与商业元数据未定（`TBD`）**：
   - `localepilot.php` 中的 `Plugin Name`、`Plugin URI` (`https://example.com/localepilot`)、`Author` (`LocalePilot Contributors`)、`Author URI`、正式首发版本号（如 `1.0.0`）与 `Woo:` 头部 ID 仍为开发期值或待定。
2. **[Blocker-2] 商业支持与法律隐私链接未定（`TBD`）**：
   - 缺少对外公开的商家安装文档 URL、客服支持入口 URL、隐私政策与服务条款 URL，以及最终商业定价定案（见 `PRODUCT-PLAN.md §5, §12`）。
3. **[Blocker-3] 根目录 `changelog.txt` 尚未生成**：
   - 需在确认正式首发版本号、发布日期与品牌名称后生成符合 Woo 规范的 `changelog.txt` 并纳入打包白名单。
4. **[Blocker-4] 官方 QIT 云端托管测试尚未执行（`Unverified`）**：
   - 需使用 Woo Marketplace Partner 账号凭证对最终 ZIP 包运行 `qit run:activation`、`qit run:security`、`qit run:phpcompatibility`、`qit run:malware`、`qit run:woo-e2e`、`qit run:woo-api`、`qit run:validation`。
5. **[Blocker-5] 多版本 PHP (`7.4–8.2`) 与真实第三方 Staging 环境未执行（`Unverified`）**：
   - 需在外部 CI 或 QIT 环境中完成 `PHP 7.4–8.2` 运行时验证，并在 Staging 站点完成目标缓存插件（如 WP Rocket）、目标主题（如 Storefront/Astra）及 4 个翻译供应商真实沙箱 API Key 的联调验收。

---

## 6. 需要您一次性提供的定稿信息清单（Checklist for User Input）

当您准备替换开发期占位符并生成最终上架包时，请一次性提供以下信息：

1. **正式插件品牌名与 Slug**：是否保留 `LocalePilot` / `localepilot`，或替换为新的正式英文/中文品牌名？
2. **正式域名与链接**：
   - `Plugin URI`（产品主页 URL）：`____________________`
   - `Author`（公司或开发者署名）：`____________________`
   - `Author URI`（作者/公司官网 URL）：`____________________`
   - 文档中心 / 缓存配置指南 URL：`____________________`
   - 客户支持工单 / 联系 URL：`____________________`
   - 隐私政策（Privacy Policy）与服务条款（Terms of Service）URL：`____________________`
3. **版本与 Marketplace 标识**：
   - 首发正式版本号（例如 `1.0.0` 或保持 `0.1.0`）：`____________________`
   - Woo Marketplace `Woo:` Header 值（格式如 `12345:abcdef...`，若暂未在 Woo 后台创建商品可填 `暂不注入`）：`____________________`
4. **首发商业与产品定案（对应 `PRODUCT-PLAN.md §12`）**：
   - 最终选择的收费模式与年费标价（例如：单站点年费 + BYOK，或其它方案）：`____________________`
   - 首次访问 IP 国家语言处理默认策略（仅展示语言建议提示条 vs. 允许商家配置静默切换）：`____________________`
