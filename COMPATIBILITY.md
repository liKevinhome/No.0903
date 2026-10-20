# LocalePilot 兼容性矩阵、通用化边界与发布阻断测试规范

**文档版本：** v0.1（Stage 1 骨架阶段基线）  
**适用范围：** LocalePilot WordPress / WooCommerce 同 URL 多语言扩展的规格定义、阶段开发边界与发布验收门禁。  
**关联文档：** [PRODUCT-PLAN.md](PRODUCT-PLAN.md)、[README.md](README.md)

> [!IMPORTANT]
> **诚实兼容性声明原则**：本文档严格区分“架构目标支持”“需单独实测认证”“未认证安全回退”与“明确不支持”。在未完成真实 Staging 环境端到端测试并留存验收记录前，**任何第三方主题、页面构建器、插件或缓存/CDN 组合均不得标记为“已支持/已认证”**。

---

## 1. 通用化架构红线（Merchant-Agnostic Rules）

为确保插件可作为标准化商业扩展分发给任意第三方 WooCommerce 商家（包括自有渠道分发与按 Woo Marketplace Extension 规范准备），后续所有阶段代码必须遵守以下通用化约束：

1. **严禁单站或私有环境硬编码**：
   - 严禁在 PHP/JS/CSS 代码、数据库迁移、默认配置或单元测试中硬编码任何特定商户域名（例如演示原型中的 `modaviora.com` 等）、特定品牌词、特定服务器路径、固定商品/分类/属性 ID、固定 SKU 或特定主题私有的 DOM 选择器。
   - 源语言、启用目标语言、识别优先级、语言切换器挂载位置（短代码、菜单项、小工具、浮动条或标准 Hook）、术语表与翻译供应商 API 凭证，必须全部由商家通过标准后台设置自主配置。
2. **客户服务器零构建工具依赖**：
   - 商家仅通过标准 WordPress 插件 ZIP 包（后台上传或解压至 `wp-content/plugins/localepilot/`）安装与运行。
   - 运行时仅使用插件内置的 [`LocalePilot\Autoloader`](includes/Autoloader.php) 及 WordPress / WooCommerce 原生 API（`$wpdb`、`wp_remote_request()`、Action Scheduler、`WC_Order` CRUD、WP Object Cache），**不要求客户服务器安装 Composer、Node/npm、SSH/CLI，也不依赖 `vendor/` 目录**。
3. **非侵入式上下文隔离**：
   - 多语言文本替换仅在**前台访客渲染（含前台同源 `wc-ajax`）与订单通知邮件**上下文中生效。
   - 在 WordPress 后台管理界面（`is_admin()` 且非前台 AJAX）、WP-CLI、REST API 写入接口、ERP/库存同步以及支付网关 Webhook/IPN 回调中，必须保持商店原始语言数据不被拦截或改写。

---

## 2. 首发运行环境与核心特性兼容矩阵

| 维度 / 特性 | 目标版本 / 声明策略 | 代码声明位置 | 当前实测状态 | 说明 |
|---|---|---|---|---|
| **PHP** | `>= 7.4`（目标覆盖 7.4 – 8.3） | `localepilot.php`、`Environment::MIN_PHP_VERSION` | **已在 WSL 实测 `PHP 8.3.6`**（`php -l` 40/40、PHPCS `3.13.6` 38/38 0 Error/0 Warning、PHPUnit `9.6.38` 33 tests/470 assertions 通过）；**PHP 7.4 – 8.2 未在本机验证（Uncertified，待 CI 矩阵或目标 PHP 实例执行）** | 仅依赖 WordPress 标准 PHP 环境（`json`、`mbstring`） |
| **WordPress** | `>= 6.2` | `localepilot.php`、`Environment::MIN_WP_VERSION` | **已在 WSL 隔离实例实测官方最新稳定版 `WordPress 7.1.3`（Latest）与上一主要版本 `WordPress 7.0.7`（L-1）**（均通过官方 `wordpress.org` SHA-1 校验）；**更早主版本（如 `6.2–6.x`）未在本机验证（Uncertified，待外部环境执行）** | 复用核心 `switch_to_locale()` 与 Options/Hook API |
| **WooCommerce** | `>= 8.0`（声明测试上限 `11.2`） | `localepilot.php`、`Environment::MIN_WC_VERSION` | **已在 WSL 隔离实例实测官方最新稳定版 `WooCommerce 11.2.1`（Latest）与上一主要版本 `WooCommerce 11.1.2`（L-1）全部 4 组交叉矩阵（`7.1.3+11.2.1`、`7.0.7+11.1.2`、`7.1.3+11.1.2`、`7.0.7+11.2.1`）**；**更早主版本（如 `8.0–11.0.x`）未在本机验证（Uncertified，待外部环境执行）** | 依赖 WooCommerce 核心 CRUD 与内置 Action Scheduler |
| **HPOS (`custom_order_tables`)** | **声明兼容 (`true`)** | `Compatibility::get_feature_matrix()` | **已在 `WP 7.1.3 + WC 11.2.1 + MariaDB 10.11.14` 隔离实例验证 `FeaturesUtil` 注册通过、HPOS 表 `wptest_wc_orders_meta` 写入 `_localepilot_order_locale`（`wp_postmeta` 0 写入）及订单邮件 `WC_Email_Customer_Processing_Order` 快照语言渲染与还原通过** | 订单语言 `_localepilot_order_locale` 仅走 `WC_Order` CRUD |
| **后台管理员界面双语 (`zh_CN` / `en_US`)** | **默认跟随当前管理员语言（站点默认时回退站点语言）+ 按管理员账号手动覆盖** | `AdminLocaleManager`、`AdminPage`、`AdminTranslations` | **已通过真实 PHPUnit `9.6.38` 单元测试及 `WP 7.1.3 + WC 11.2.1` 隔离集成测试双管理员实测验证** | 后台语言偏好仅存于管理员个人 User Meta (`_localepilot_admin_ui_locale`)，与前台店铺语言严格隔离 |
| **翻译供应商配置、`$wpdb/dbDelta` 持久层与 4 个适配器 (`Gemini` / `DeepL` / `Google Cloud` / `Baidu`)** | **后台掩码配置与连接测试、`{prefix}localepilot_translations` 持久化哈希去重与审校流转、响应体积上限 (`1MB` 默认 / `2MB` 硬上限)、PII 与前台零调用拦截** | `ProviderSettingsManager`、`TranslationRepository`、`includes/Translation/*` | **已通过真实 PHPUnit `9.6.38` HTTP Mock 单元测试与 `WP 7.1.3 + WC 11.2.1 + MariaDB 10.11.14` 隔离实例实测验证**（注：仅做本地 HTTP Mock 验证，**未发起真实外部付费 API 联网调用，真实联网调用仍为 Uncertified**） | 仅使用 `wp_remote_request()` 与 `$wpdb`/`dbDelta`，无外部 SDK 依赖 |
| **同 URL 前台语言识别、已审校译文读取与缓存隔离 (`StorefrontLocaleResolver` / `StorefrontTranslator` / `CacheIsolationGuard`)** | **同 URL 零路径改写、优先级 `手动选择 > Accept-Language > 可信代理 IP 国家提示 > 默认语言`、仅渲染 `approved` 本地译文、查询参数保留、未认证缓存安全绕过 (`DONOTCACHEPAGE`)** | `includes/Storefront/*` | **已通过真实 PHPUnit `9.6.38` 单元测试与 `WP 7.1.3 + WC 11.2.1` 隔离实例真实 `WC_Product_Simple` 同 URL 多语言渲染验证** | 仅覆盖显式支持的商品标题/描述/分类/属性名与已注册 UI 文本，不宣称任意主题/第三方插件字符串自动翻译；IP 国家头 (`HTTP_CF_IPCOUNTRY` / `HTTP_X_COUNTRY_CODE`) 仅在 `trust_proxy_geoip=true` 时提供语言提示/回退，绝不改变配送地址、币种、价格、税费或结账状态 |
| **Classic Cart & Checkout + Order Emails (`CartCheckoutCoordinator` / `OrderLocaleCoordinator`)** | **支持经典购物车、`wc-ajax` 迷你购物车刷新、优惠券提示、配送费率文字 (`WC_Shipping_Rate`)、支付网关标题/说明、结账字段与校验报错、下单成功页、HPOS 订单语言快照与订单邮件切换** | `includes/Storefront/CartCheckoutCoordinator.php`、`includes/Storefront/OrderLocaleCoordinator.php` | **已通过真实 PHPUnit `9.6.38` 单元测试与 `WP 7.1.3 + WC 11.2.1 + HPOS` 隔离实例实测验证**（真实 `WC_Cart`、`woocommerce_mini_cart()`、`WC_Coupon`、`WC_Shipping_Rate`、`bacs`/`cod` 网关、`wc_create_order()` 与 `WC_Email_Customer_Processing_Order`，切换语言前后小计/折扣/运费/税额/总计 `0` 偏差，前台外部翻译 API 调用 `0` 次） | 仅针对 WooCommerce 核心内置网关（`bacs`/`cod`/`cheque`）完成实测；第三方支付网关插件及跨域托管支付 iframe 内部 DOM 标记为 `Uncertified` |
| **Cart & Checkout Blocks / Store API (`cart_checkout_blocks`)** | **显式声明兼容 (`true`)，支持 `/wc/store/v1/*` 购物车/商品响应与 Blocks 结账订单语言快照 (`woocommerce_store_api_checkout_update_order_from_request`)** | `Compatibility::get_feature_matrix()`、`StorefrontTranslator`、`OrderLocaleCoordinator` | **已声明 `true` 并在 `WP 7.1.3 + WC 11.2.1 + HPOS` 隔离实例通过 `FeaturesUtil`、`rest_do_request('/wc/store/v1/cart')` 与 Store API 订单创建 Hook 实测验证** | 覆盖 Store API 返回的商品/属性/运费/网关文本与订单语言快照；真实浏览器 React Checkout Blocks 交互及第三方 Blocks 自定义扩展未在本机执行（标记为 `Uncertified`） |
| **Action Scheduler 后台异步翻译队列与订阅降级 (`TranslationQueueManager` / `SubscriptionStateManager`)** | **复用 WooCommerce 内置 Action Scheduler（零 Composer 运行时依赖），仅允许后台管理员显式提交白名单商品/分类/UI 文本（Capability + Nonce + PII 拦截），支持去重、状态流转 (`pending`/`running`/`succeeded`/`failed`/`retry`/`cancelled`)、有限指数退避 (`30s -> 60s -> 120s`)、供应商限流冷却、取消任务、保护已有 `approved` 译文不被失败/重试/重复任务覆盖，以及订阅过期渐进降级** | `includes/Translation/TranslationQueueManager.php`、`includes/Admin/SubscriptionStateManager.php` | **已通过真实 PHPUnit `9.6.38` 单元测试与 `WP 7.1.3 + WC 11.2.1 + HPOS + Action Scheduler` 隔离实例实测验证** | 前台访客请求严禁入队或执行翻译任务；订阅状态为 `expired` 时停止新增机器翻译任务，已保存 `approved` 译文继续正常渲染 |
| **缓存健康自检与未认证缓存矩阵 (`CacheHealthChecker`)** | **后台一键自检 `lp_lang`、`Cache-Control`、`Vary: Cookie, Accept-Language`、`DONOTCACHEPAGE`、`rocket_cache_dynamic_cookies` Hook 及同 URL 四次交替语言探测，并将未部署/未实测第三方缓存插件显式标记为 `uncertified`** | `includes/Storefront/CacheHealthChecker.php`、`includes/Admin/AdminPage.php` | **已通过真实 PHPUnit `9.6.38` 单元测试与 `WP 7.1.3 + WC 11.2.1` 隔离实例实测验证** | WP Rocket、LiteSpeed Cache、Cloudflare APO/Edge 等未在本机真实部署，在自检矩阵中强制标记为 `uncertified` (`shared_cache_allowed = false`) |

---

## 3. 四级兼容边界定义

### 3.1 核心支持范围（Core Target Scope — 标准 Hook 覆盖目标，待开发与实测验收）

以下范围通过 WordPress 与 WooCommerce 标准数据层/输出层 Filter 实现，不依赖特定主题模板覆写：

- **商品与目录数据**：商品标题、长描述、短描述、商品分类名称/描述、商品标签、全局属性标签与词条（Taxonomy Terms）、自定义商品属性名/值、变体选项显示名称、变体描述。
- **经典购买链路**：商品归档/搜索列表、经典购物车（Classic Cart）、经典结账（Classic Checkout）、`wc-ajax` 片段更新、配送方式标签、支付网关标题与说明、WooCommerce 标准前台提示与表单报错信息。
- **订单语言与事务邮件**：下单时通过 `WC_Order` CRUD 记录订单语言元数据 `_localepilot_order_locale`，并在发送订单确认、处理中、已完成、已退款、客户备注等标准 WooCommerce 邮件时切换至订单语言渲染。

### 3.2 需认证范围（Requires Certification — 必须逐项实测通过后才可标为支持）

以下组合受第三方实现机制影响，**在未完成独立 Staging 实测并归档验收记录前，全部处于“未认证（Uncertified）”状态，禁止对外宣传为“已支持”**：

| 类别 | 候选对象示例 | 当前认证状态 | 准入条件 |
|---|---|---|---|
| **主流 WooCommerce 主题** | Storefront、Astra、Flatsome、Kadence、WoodMart、GeneratePress | **未认证（待实测）** | 验证商品页、变体切换、迷你购物车、经典结账无 DOM/JS 冲突且不串语言 |
| **支持动态 Cookie 的页面缓存** | WP Rocket（Dynamic/Mandatory Cookie）、LiteSpeed Cache | **未认证（待实测）** | 必须通过同 URL 双语言 Cache Health Check 连续请求验证缓存变体隔离 |
| **第三方多币种 / 税率插件** | WooPayments Multi-Currency、WOOCS、YayCurrency、Aelia | **未认证（待实测）** | 验证切换 `lp_lang` 时币种、汇率、价格、税额与结算金额 100% 不受影响 |
| **翻译供应商 API 适配器** | Gemini、DeepL、Google Cloud Translation、百度翻译 | **未认证（待开发与实测）** | 逐个接口验证鉴权、HTML/占位符/价格保护、超时重试与错误码处理 |

### 3.3 未认证范围（Uncertified — 默认执行安全降级）

- **未经验证的 CDN / 边缘缓存 / 主机层静态 HTML 缓存**（如 Cloudflare APO、Cloudflare Cache Everything、未配置 `lp_lang` 变体的 Nginx FastCGI Cache / Varnish）：默认不启用多语言 HTML 缓存共享，强制采取绕过公共 HTML 缓存或提示商家修改缓存规则的安全回退策略。
- **页面构建器私有存储结构**（如 Elementor、Divi、WPBakery、Bricks 的非标准 JSON/Meta 字段）：MVP 阶段不承诺自动解析翻译，列为 Phase 2 扩展兼容包。
- **任意第三方插件硬编码文案或私有数据表**：未通过标准 gettext 或 WooCommerce 核心 Filter 输出的字符串，需经后续扫描/注册机制适配，不承诺开箱自动翻译。

### 3.4 明确不支持范围（Unsupported / Out of Scope）

1. **同 URL 多语言独立 SEO 收录**：
   - 插件保持完整 URL 不变，不生成 `/fr/`、`?lang=fr` 等独立语言 URL，也不输出指向不同语言 URL 的 `hreflang` 标记。明确不支持将“同 URL 多语言自然搜索收录/排名”作为功能或卖点（详见 [PRODUCT-PLAN.md §1](PRODUCT-PLAN.md)）。
2. **第三方 Cart / Checkout Blocks 扩展私有前端脚本**：
   - 核心已声明 `cart_checkout_blocks => true` 并支持 WooCommerce 原生 Store API (`/wc/store/v1/*`) 商品、运费费率、支付网关标题/说明与订单语言快照；但若第三方 Blocks 扩展在浏览器 React 组件中直接写死非 Store API 文本且未走标准过滤器，插件不承诺自动替换。
3. **第三方托管支付网关 iframe 内部界面**：
   - 如 PayPal 按钮弹窗、Stripe Payment Element 跨域 iframe、Klarna 托管组件等内部文案由对应支付服务商控制，插件无法直接修改 iframe 内部 DOM（仅在网关标准参数支持时传入 locale hint，并在后台 UI 明确提示商家）。
4. **客户与订单隐私数据（PII）翻译**：
   - 严禁将客户姓名、邮箱、电话、账单/收货地址、支付信息、订单私有备注或评论作者资料发送给任何外部翻译 API。
5. **基于语言的币种、价格、税率或配送国家联动变更**：
   - 语言偏好 `lp_lang` 仅决定显示文本语言，不支持也不允许改变商品金额、币种、税率、账单国家或配送规则。

---

## 4. 渐进降级规则（Progressive Degradation Rules）

系统遵循 **“前台购物故障开放（Fail-Open）、后台与缓存安全故障闭合（Fail-Closed）”** 的渐进降级原则：

| 故障或边界场景 | 系统降级行为 | 对店铺与访客的影响 |
|---|---|---|
| **字段无译文 / 译文处于“待审校”状态** | 直接返回商店默认源语言字段值 | 页面正常渲染源语言内容，无空白、无报错 |
| **翻译 API 密钥无效 / 余额不足 / 超时 / 5xx** | 仅在后台异步 Action Scheduler 队列记录失败状态与重试计划；前台绝不发起实时 API 请求 | 前台已保存译文正常显示，页面加载速度与结账不受任何外部网络影响 |
| **缓存自检（Cache Health Check）失败或检测到未认证 HTML 缓存** | 自动对多语言前台响应下发防串缓存头 / 绕过该层 HTML 缓存，并在插件后台显示明确告警与配置指引 | 避免不同语言访客看到错误语言页面；待缓存隔离验证通过后再恢复缓存加速 |
| **访客 Cookie 被禁用且无匹配 `Accept-Language` / 可信 GeoIP** | 回退至商家配置的店铺默认语言 | 店铺正常浏览与下单 |
| **订阅授权到期 / 授权服务暂时不可达** | 停止新增机器翻译任务与云端更新；**保留本地已存储的全部译文继续在前台和邮件中正常渲染** | 不删除商家数据，不中断现有多语言展示、购物车与结账 |
| **运行环境不满足最低要求（PHP < 7.4 / WP < 6.2 / WC 未激活或 < 8.0）** | `Plugin::init()` 立即中止挂载业务逻辑，仅向有 `activate_plugins` 权限的管理员显示转义后的错误提示 | 不产生致命错误（Fatal Error），不破坏 WordPress 后台与前台可用性 |
| **插件停用或卸载** | 默认保留本地译文表与订单语言元数据（除非商家未来主动执行数据清除工具） | 商店无缝恢复源语言展示，历史订单数据完整保留 |

---

## 5. Marketplace 与正式发布阻断测试（Release Blocker Tests）

在提交 Woo Marketplace 审核或向付费商家发布任何正式版本前，以下 **6 项阻断测试（Release Blockers）** 必须在真实 Staging 环境全部通过并留存验收记录；任一未通过即阻断发布：

1. **RB-01：零 Composer 自包含安装包测试**
   - **步骤**：按 [.distignore](.distignore) 生成不含 `vendor/`、`composer.json`、`tests/` 的纯净插件 ZIP 包，在无 Composer 的干净 WordPress（`>= 6.2`）+ WooCommerce（`>= 8.0`）环境中通过后台上传安装、激活、停用与删除。
   - **阻断标准**：全程无 PHP Fatal/Warning/Notice，内置 [`LocalePilot\Autoloader`](includes/Autoloader.php) 正常加载全部运行时类。
2. **RB-02：同 URL 双语言渲染与缓存隔离阻断测试**
   - **步骤**：对同一商品完整 URL（如 `/product/sample-item/`）交替及并发发送携带 `lp_lang=en` 与 `lp_lang=fr` Cookie 的请求，并在开启/关闭缓存插件条件下运行 Cache Health Check。
   - **阻断标准**：返回的 `html lang`、商品标题、描述、属性与变体文案严格匹配当前 Cookie 语言，连续重复请求 **0 次串语言**；若缓存层不支持 Cookie 变体，必须自动绕过 HTML 缓存并给出后台告警。
3. **RB-03：购物车、经典结账与金额/币种零篡改阻断测试**
   - **步骤**：在非默认语言（`lp_lang`）下完成变体选择、加入购物车、`wc-ajax` 刷新、应用优惠券、切换配送方式、触发结账字段校验错误并最终提交订单。
   - **阻断标准**：前台界面与报错提示保持目标语言；商品单价、小计、折扣、运费、税额、总计金额与结算币种代码与源语言状态下 **100% 一致（0 数值偏差）**。
4. **RB-04：HPOS 纯净模式与订单语言邮件阻断测试**
   - **步骤**：在 WooCommerce 开启 HPOS（High-Performance Order Storage）且**关闭传统 `wp_posts`/`wp_postmeta` 兼容同步**的环境中，以非默认语言下单，随后在后台触发订单确认、发货、退款邮件。
   - **阻断标准**：订单语言元数据 `_localepilot_order_locale` 通过 `WC_Order` CRUD 正确持久化，无任何直接访问 `wp_postmeta` 的慢查询或错误，且订单邮件按订单固化语言渲染。
5. **RB-05：供应商故障与订阅过期降级阻断测试**
   - **步骤**：模拟翻译供应商 API 超时/401/429/500 错误，以及将站点订阅状态置为“已过期（Expired）”。
   - **阻断标准**：前台页面响应时间不受外部 API 影响，已发布本地译文继续正常展示，客户仍可正常完成加购与结账，本地译文数据 0 丢失。
6. **RB-06：安全、隐私与编码规范静态门禁测试**
   - **步骤**：执行 `composer lint`（PHPCS：`WordPress-Extra`、`WordPress-Docs`、`PHPCompatibilityWP`）与 `composer test`（PHPUnit），并检查前台响应与 API 请求日志。
   - **阻断标准**：PHPCS 0 Error、PHPUnit 100% 通过；前台 HTML/JS 0 处泄露翻译供应商 API Key；发往翻译供应商的请求中 0 处包含客户姓名、邮箱、地址、电话或支付信息。

---

## 6. Woo Marketplace 官方提交要求 / QIT 测试矩阵与当前未执行项声明

根据 Woo Marketplace 官方提交规范与 Quality Insights Toolkit (QIT) 标准，以下为官方托管测试矩阵要求及本仓库当前的真实执行边界（**严禁将仅有 `.github/workflows/ci.yml` 配置文件但未实际跑通的矩阵宣称为“已通过”**）：

| QIT / Marketplace 检查项 | 官方准入要求 | 当前本机实测状态 | 未完成 / 待外部环境执行项（Uncertified） |
|---|---|---|---|
| **QIT Activation / Woo E2E / Woo API 测试** | 在 WP Latest & L-1、WC Latest & L-1、PHP 7.4–8.3 矩阵下零 PHP Fatal/Warning/Notice 激活并跑通核心购物链路 | **已在本机 WSL (`PHP 8.3.6` + `MariaDB 10.11.14`) 完成 WP Latest (`7.1.3`) / L-1 (`7.0.7`) × WC Latest (`11.2.1`) / L-1 (`11.1.2`) 全部 4 组交叉矩阵实测**（自包含 ZIP 安装、激活、HPOS、经典加购/结账、Store API `/wc/store/v1/cart`、订单邮件、Action Scheduler 队列与缓存自检全部 `exit code 0`） | 官方 QIT 云端 CLI (`qit run:activation` / `qit run:woo-e2e` / `qit run:woo-api`)、GitHub Actions 远端工作流运行、`PHP 7.4–8.2` 解释器运行时、更早版本 (`WP 6.2–6.x` / `WC 8.0–11.0.x`) **尚未执行（Uncertified）** |
| **QIT Security / PHPCompatibility / Malware / Validation** | 零高危漏洞、PHP 7.4–8.3 语法兼容、无混淆/后门、ZIP 包大小与头部元数据合规 | **已在本机通过 `php -l` (40/40)、`PHPCS 3.13.6` (`WordPress-Extra` + `WordPress-Docs` + `PHPCompatibilityWP` 7.4–8.3 规则集，38/38 0 Error/0 Warning) 与纯净 ZIP 构建校验** | 官方 QIT 托管扫描器 (`qit run:security` / `qit run:malware` / `qit run:validation`) **尚未连接 Partner 账号执行** |
| **第三方缓存 / 支付网关 / 主题真实插件环境** | 若宣称兼容特定缓存、网关或主题，必须在该插件真实激活的 Staging 环境完成同 URL 隔离与下单测试 | **仅在内置 `CacheHealthChecker` 中注册了 `rocket_cache_dynamic_cookies` Hook 并完成探针自检，核心网关仅实测 `bacs`/`cod`** | WP Rocket、LiteSpeed Cache、Cloudflare APO/Edge、Stripe/PayPal/WooPayments、Astra/Flatsome 等**均未在本机安装实测，代码中显式标为 `uncertified`** |
| **真实外部付费翻译 API 联网调用** | 4 个 BYOK 适配器（Gemini / DeepL / Google Cloud / Baidu）需在真实沙箱或生产 Key 下验证配额与错误码 | **仅通过本地 HTTP Mock 完成 4 个适配器请求签名、正常解析、429/500/超大响应拦截验证** | **0 次真实外部付费 API 请求（按当前阶段红线禁止发起真实联网调用）** |
| **商业上架元数据准备** | 正式品牌域名 `Plugin URI`、根目录 `changelog.txt`、商家手册与隐私披露 | 已具备完整隐私边界实现（PII 拦截、BYOK 本地掩码存储、零遥测）与技术文档 | `localepilot.php` 中的 `https://example.com/localepilot` 占位 URI 及 Marketplace 专用 `changelog.txt` 需在确定正式品牌域名后替换补齐 |
