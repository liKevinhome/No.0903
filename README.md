# LocalePilot (Stage 1 Skeleton)

**LocalePilot** 是一个面向 WordPress / WooCommerce 的同 URL 多语言商店扩展项目（开发工作名，正式品牌名待定）。插件在保持商品与店铺完整 URL 不变的前提下，根据访客语言偏好展示预先翻译并存储在本地的内容。

完整的产品定位、边界、商业假设与风险控制请参见 [PRODUCT-PLAN.md](PRODUCT-PLAN.md)；首发兼容矩阵、四级兼容边界（核心支持 / 需认证 / 未认证 / 不支持）、渐进降级规则与 Marketplace 发布阻断测试规范请参见 [COMPATIBILITY.md](COMPATIBILITY.md)。

---

## 1. 当前开发阶段（全阶段核心功能完成：Stage 1 骨架 + 后台双语 UI + 翻译供应商与持久层 + 同 URL 前台与缓存隔离 + WooCommerce 购物车/结账/Blocks/订单邮件兼容 + Action Scheduler 异步翻译队列 + 缓存健康自检与订阅降级）

当前仓库已完成全部预定开发阶段，包含：

- 安全、最小可用的 WordPress / WooCommerce 插件入口（`localepilot.php`、`uninstall.php`）。
- 零外部依赖的内置 PSR-4 运行时自动加载器（`includes/Autoloader.php`）。
- 运行时 PHP / WordPress / WooCommerce 最低版本与依赖检测（`includes/Environment.php`）。
- WooCommerce 特性兼容性显式声明（`includes/Compatibility.php`）。
- 插件核心生命周期容器与安全回退提示（`includes/Plugin.php`）。
- **后台双语（`zh_CN` 简体中文 / `en_US` 英语）语言解析与按管理员账号独立覆盖组件**（`includes/Admin/AdminLocaleManager.php`、`includes/Admin/AdminTranslations.php`、`includes/Admin/AdminPage.php`）：默认跟随当前管理员 WordPress 个人资料语言，当管理员设为“站点默认”时自动回退至站点语言，并支持按管理员账号（User Meta `_localepilot_admin_ui_locale`）手动切换覆盖，且与前台店铺语言完全隔离。
- **翻译供应商抽象、后台凭证设置、本地译文持久层与首批 4 个供应商适配器**（`includes/Admin/ProviderSettingsManager.php`、`includes/Translation/` 与 `includes/Translation/Providers/`）：
  - 统一接口与抽象基类：`TranslationProviderInterface`、`AbstractTranslationProvider`、`TranslationRequest`、`TranslationResult`、`ProviderRegistry`。
  - 原生 WordPress HTTP 封装与响应体积上限：`HttpClientInterface`、`WpHttpClient`（仅调用 `wp_remote_request()`，默认限制响应体积 `1 MB`，可配置区间 `1 KB – 2 MB` 硬上限，超时拦截 `response_too_large`）。
  - 后台供应商配置与掩码保护：`ProviderSettingsManager`（支持选择 `gemini` / `deepl` / `google_cloud` / `baidu`，密钥保存在站点本地 `_localepilot_provider_settings` 且 `autoload=false`；后台 HTML 密码框恒为空值，仅展示 `••••••••` 掩码状态，绝不回传明文或写入日志；支持 `manage_options`/`manage_woocommerce` + Nonce 校验与服务端连接测试）。
  - WordPress `$wpdb` / `dbDelta` 持久化译文仓库：`TranslationRepository`（创建并维护 `{prefix}localepilot_translations` 表，按 `object / source_hash / target_locale / provider` 跨请求持久化去重，支持 `pending_review` 与 `approved` 审校状态流转，严格白名单限制商品/分类/UI 文本并拒绝客户/订单/个人资料 PII 字段）。
  - 结构与数值保护器：`TokenProtector`（翻译前自动将 HTML 标签、WordPress 短代码、URL、`printf`/`{{var}}` 占位符、价格货币数字、重量/尺寸单位、SKU 代码与商户术语表替换为 `[[LP_TOK_N]]`，翻译后精确还原，并负责在错误日志中脱敏 API 密钥）。
  - 首批 4 个 BYOK 适配器：`GeminiProvider`、`DeepLProvider`、`GoogleCloudProvider`、`BaiduProvider`（全部通过 HTTP Mock 单测与隔离 WP/WC 集成测试，未发起真实外部付费 API 请求）。
- **同 URL 前台语言识别、已审校译文读取与缓存隔离守卫**（`includes/Storefront/`）：
  - `StorefrontLocaleResolver`：在保持完整 URL 与查询参数不变（不添加 `/fr/`、`/de/` 路径前缀，不改写域名或固定链接）的前提下，严格按 **`访客手动选择 (lp_set_lang / lp_lang Cookie) > 浏览器 Accept-Language > 可信代理 IP 国家提示/回退 (HTTP_CF_IPCOUNTRY / HTTP_X_COUNTRY_CODE，仅当 trust_proxy_geoip=true 时启用) > 站点默认语言`** 解析已启用 locale；非法或未启用 locale 一律丢弃并安全回退；IP 国家提示绝不修改 WooCommerce 客户配送/账单地址、币种、价格、税率、运费或结账状态。
  - `StorefrontTranslator`：仅在前台上下文（含前台 `wc-ajax`，排除 `wp-admin` 后台编辑界面）读取本地数据库中状态为 `approved` 且 `source_hash` 匹配的译文；当译文处于 `pending_review`、缺失或源文已变更时自动回退至源语言原文；前台渲染过程 **0 次**外部翻译 API 调用；提供 `[localepilot_language_switcher]` 同 URL 语言切换器短代码（完整保留原 URL 查询参数）。
  - `CacheIsolationGuard`：挂载 WP Rocket `rocket_cache_dynamic_cookies` / `rocket_cache_mandatory_cookies` 过滤器注入 `lp_lang`，并对携带 `lp_lang`、非默认语言或未认证缓存层强制设置 `DONOTCACHEPAGE = true`、`Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0` 与 `Vary: Cookie, Accept-Language`，杜绝 A 语言页面缓存泄漏给 B 语言访客。
- **WooCommerce 购物车、结账（Classic & Checkout Blocks / Store API）、HPOS 订单语言快照与订单邮件兼容**（`includes/Storefront/CartCheckoutCoordinator.php`、`includes/Storefront/OrderLocaleCoordinator.php`）：
  - `CartCheckoutCoordinator`：在保持同 URL 语言状态（含前台 `wc-ajax` 刷新与 Store API `/wc/store/v1/*` 请求）的前提下，支持购物车与迷你购物车行项目名称、变体选项名称、优惠券成功/报错提示（含 WC 11.2+ 消息格式）、配送方式费率标签（`WC_Shipping_Rate` 及 `woocommerce_shipping_package_hash` 会话隔离）、支付网关标题与说明、结账表单字段与必填校验报错提示、下单按钮及下单成功（Thank-you）页文案；优先读取本地已审核（`approved`）译文，辅以安全的内置静态 UI 词典，缺译文或 `pending_review` 一律回退原文；**绝不修改商品单价、小计、优惠券折扣金额、运费金额、税额、币种代码、客户收货/账单地址、支付网关可用性/处理逻辑、订单状态或库存**。
  - `OrderLocaleCoordinator`：在 Classic Checkout（`woocommerce_checkout_create_order`）与 Checkout Blocks / Store API（`woocommerce_store_api_checkout_update_order_from_request` / `woocommerce_store_api_checkout_order_processed`）创建订单时，通过 HPOS 兼容的 `WC_Order` CRUD（`update_meta_data` / `get_meta`）将当前访客语言固化为 `_localepilot_order_locale`（直接写入 `wc_orders_meta`，0 处 `wp_postmeta` SQL）；在发送订单通知邮件（`woocommerce_email_before_order_table` / `woocommerce_email_after_order_table`）时自动切换至该订单的快照语言渲染行项目名称，并在邮件表格结束后立即还原运行时语言上下文。
- **Action Scheduler 后台异步翻译任务队列、缓存健康自检与订阅渐进降级**（`includes/Translation/TranslationQueueManager.php`、`includes/Storefront/CacheHealthChecker.php`、`includes/Admin/SubscriptionStateManager.php`）：
  - `TranslationQueueManager`：复用 WooCommerce 内置 Action Scheduler（`as_enqueue_async_action` / `as_schedule_single_action` / `as_unschedule_all_actions`，零 Composer 运行时依赖）调度 `localepilot_process_translation_job`；仅允许具备 `manage_woocommerce` / `manage_options` 权限且通过 Nonce 校验的后台管理员显式提交白名单商品/分类/UI 文本；严格拦截订单/客户 PII 字段，且严禁前台访客请求入队或执行翻译；支持按 `object + source_hash + target_locale + provider` 去重、状态流转（`pending` / `running` / `succeeded` / `failed` / `retry` / `cancelled`）、有限指数退避（`30s -> 60s -> 120s`，最多 3 次）、供应商限流冷却（`429`）与任务取消；**当本地已存在相同 `source_hash` 的 `approved` 译文时，任何重复、重试或失败任务均不得覆盖或降级该已审核译文**。
  - `CacheHealthChecker`：在后台提供一键缓存健康自检面板，验证 `lp_lang` Cookie 清洗、`Cache-Control` 私有防缓存头、`Vary: Cookie, Accept-Language`、`DONOTCACHEPAGE = true`、`rocket_cache_dynamic_cookies` Hook 注册以及同 URL 四次交替语言探针（检测是否发生跨语言串页），并将未在本机实际部署测试的第三方缓存系统（`wp_rocket`、`litespeed_cache`、`cloudflare_apo_edge`、`unrecognized_page_cache`）显式标记为 `uncertified`（`shared_cache_allowed = false`）。
  - `SubscriptionStateManager`：管理站点订阅状态（`active` / `grace_period` / `expired` / `unconnected`）；当状态为 `expired` 时仅暂停新增后台机器翻译任务，已保存的本地 `approved` 译文继续在前台与订单邮件中正常渲染。
- 发布打包排除规则（`.distignore`）、零依赖打包脚本（`bin/build-dist-zip.ps1`）及仅用于开发/CI 的测试与规范配置（`composer.json`、`phpcs.xml.dist`、`phpunit.xml.dist`、`tests/`、`bin/`、`docker-compose.yml`、`.github/workflows/ci.yml`）。

---

## 2. 安装运行方式与 Woo Marketplace Extension 兼容策略

代码结构、文件头声明与许可证按 **Woo Marketplace Extension** 与 WordPress 插件规范方向准备（注：仅作技术与合规准备，**不假定** Woo Marketplace 或其他市场必然批准上架，自有分发渠道同样适用）：

1. **客户服务器零 Composer 依赖（自包含安装与运行）**
   - **安装与运行不依赖 Composer**：商家通过标准 WordPress 插件安装包（后台上传 ZIP 或直接解压至 `wp-content/plugins/localepilot/`）即可完成安装与激活，**客户服务器不需要安装 Composer，也不需要执行 `composer install` 或存在 `vendor/` 目录**。
   - **单一内置运行时加载器**：插件主入口 `localepilot.php` 直接加载并注册内置的 `includes/Autoloader.php`（SPL PSR-4 自动加载），运行时不引用 `vendor/autoload.php`，保证开发环境与客户生产环境的类加载路径完全一致。
   - **原生 API 优先与构建隔离**：插件后续的网络请求、数据库存储与异步队列均基于 WordPress / WooCommerce 原生 API（`wp_remote_request`、`$wpdb`、Action Scheduler 等）实现；仓库中的 `composer.json`、`phpcs.xml.dist`、`phpunit.xml.dist`、`tests/` 仅供开发者在本地或 CI 环境执行代码规范检查与单元测试，并在发布打包时通过 `.distignore` 全部排除。
2. **开源许可证与职责边界**
   - 插件本地 PHP 代码采用 `GPL-3.0-or-later`，不包含代码混淆或 trialware 限制。
   - 停用或卸载插件默认保留商家已保存的本地译文和店铺数据（见 `uninstall.php` 与 `Plugin::deactivate()`），不会破坏既有语言、购物车或结账。
3. **基础运行环境要求**
   - PHP：`>= 7.4`（兼容 PHP 7.4 - 8.3）
   - WordPress：`>= 6.2`
   - WooCommerce：`>= 8.0`（声明 `WC requires at least: 8.0`，`WC tested up to: 11.2`）
4. **WooCommerce 特性兼容矩阵（`includes/Compatibility.php`）**
   - **High-Performance Order Storage (`custom_order_tables` / HPOS)**：声明为 **`true`（兼容）**。后续订单语言记录统一通过 WooCommerce `WC_Order` CRUD API 读写，不直接拼接 `wp_posts` / `wp_postmeta` SQL。
   - **Cart & Checkout Blocks (`cart_checkout_blocks`)**：显式声明为 **`true`（兼容）**。`StorefrontTranslator`、`CartCheckoutCoordinator` 与 `OrderLocaleCoordinator` 同时覆盖 Classic Cart/Checkout（`wc-ajax`）与 WooCommerce Cart/Checkout Blocks 的**服务端 Store API 响应与 Hook 集成**（`rest_do_request('/wc/store/v1/cart')` 及 `woocommerce_store_api_checkout_update_order_from_request` 订单语言快照已在本机四格矩阵实测通过）；**真实浏览器端（如 Playwright React Checkout Blocks 前端交互）E2E 测试、第三方托管支付 iframe 内部跨域 DOM 与第三方 Blocks 私有前端硬编码文案尚未在浏览器端实测（标为 `Uncertified / Unverified`）**，详见 [COMPATIBILITY.md](COMPATIBILITY.md) 与 [MARKETPLACE_READINESS.md](MARKETPLACE_READINESS.md)。
5. **同 URL 模式的核心边界声明**
   - **SEO 边界**：同 URL 多语言页面不提供各语言独立 URL 与 `hreflang` 收录能力，适用于广告、社交、邮件和私域流量落地页，不以多语言自然搜索排名为卖点。
   - **缓存边界**：同 URL 返回不同语言必须按 `lp_lang` 隔离缓存变体；对未经验证的 CDN/HTML 缓存组合默认采取安全绕过策略。
   - **安全与隐私边界**：前台仅读取本地译文，不在访客请求中调用外部翻译 API，也不向浏览器下发任何 API 密钥；订单、客户姓名、地址、邮箱与支付信息绝不发送给翻译供应商。
6. **通用化与后续阶段开发边界（强制约束）**
   - **严禁特定站点硬编码（Merchant-Agnostic）**：代码、默认配置、迁移脚本与测试中严禁硬编码任何特定店铺域名（如 `modaviora.com`）、品牌名、私有服务器路径、固定分类/商品 ID/SKU 或特定主题 CSS 选择器；所有语言、切换器挂载位置、术语表与翻译 API 凭证均通过标准后台配置管理。
   - **标准 Hook 注入与主题/插件生态兼容**：后续多语言内容替换统一通过 WordPress / WooCommerce 标准数据层与渲染 Filter 注入，不强行覆盖主题模板；仅在前台展示与订单邮件上下文中生效，绝不篡改后台编辑页（`is_admin()` 且非前台 `wc-ajax`）、REST 写入接口、库存同步、支付回调（Webhook/IPN）及第三方多币种/税率/运费计算逻辑。
   - **前台故障开放（Fail-Open）与后台故障闭合（Fail-Closed）**：当前台遇到译文缺失、缓存未验证、API 异常或订阅到期时，必须安全回退至商店默认语言原文，绝不阻断商品浏览、加购或结账；后台管理与设置操作则必须严格校验 Capability 与 Nonce。详细分级边界、渐进降级表与 6 项发布阻断测试见 [COMPATIBILITY.md](COMPATIBILITY.md)。

---

## 3. 目录结构

```text
.
├── .distignore                      # 发布打包排除清单（排除 composer/vendor/tests/docker/ci 等开发文件）
├── .github/
│   └── workflows/
│       └── ci.yml                   # GitHub Actions CI（真实 PHPCS、PHPUnit 与 WP/WC/HPOS 容器激活验收）
├── .gitignore                       # Git 忽略规则
├── COMPATIBILITY.md                 # 首发兼容矩阵、四级边界、渐进降级与发布阻断测试规范
├── PRODUCT-PLAN.md                  # 产品与架构方案
├── README.md                        # 开发说明、零 Composer 运行策略与隔离测试文档
├── bin/
│   └── build-dist-zip.ps1           # 零 Composer 依赖的纯净发行 ZIP 打包与条目校验脚本
├── composer.json                    # 仅用于开发容器/CI 的 PHPCS 与 PHPUnit 配置（客户服务器不需要）
├── docker-compose.yml               # 隔离本地开发测试容器配置（唯一项目名、127.0.0.1 回环端口、独立卷）
├── localepilot.php                  # WordPress / WooCommerce 插件主入口（使用内置 Autoloader）
├── phpcs.xml.dist                   # WordPress / WooCommerce 编码规范检查配置
├── phpunit.xml.dist                 # PHPUnit 单元测试配置
├── uninstall.php                    # 安全卸载入口（默认保留本地译文数据）
├── includes/
│   ├── Autoloader.php               # 内置 PSR-4 运行时自动加载器（零 Composer 依赖）
│   ├── Compatibility.php            # WooCommerce HPOS / Blocks 兼容性声明
│   ├── Environment.php              # PHP / WP / WC 版本与依赖检查
│   └── Plugin.php                   # 插件核心引导与生命周期管理
└── tests/
    ├── bootstrap.php                # PHPUnit 引导文件
    └── Unit/
        └── PluginSkeletonTest.php   # 第 1 阶段骨架单元测试（需真实 PHPUnit 执行）
```

---

## 4. 隔离开发测试环境、打包与 CI 方案（仅限开发者/CI 环境）

> 注：以下命令仅面向插件开发者与 CI 流水线；商家生产站点安装与运行插件**不需要**安装 Composer、Docker 或执行任何构建命令。本仓库严禁使用任何伪造的 PHPUnit 模拟器/垫片替代真实 PHPUnit。

### 4.1 构建自包含纯净插件 ZIP 包（不依赖 PHP/Composer）

在 Windows PowerShell 中直接执行：

```powershell
./bin/build-dist-zip.ps1
```

脚本按 [.distignore](.distignore) 过滤全部开发、测试与项目文档文件，生成 `dist/localepilot.zip`，并自动校验归档内仅包含 `localepilot/` 下的 **31 个运行时 PHP 文件**（不含 `vendor/`、`composer.json`、`tests/`、`docker-compose.yml`、`.github/`、`MARKETPLACE_READINESS.md` 等）。

### 4.2 本机 Docker 隔离容器测试（需 Docker Daemon 运行）

[docker-compose.yml](docker-compose.yml) 采用独立项目名 `localepilot-dev-test`、独立卷（`localepilot_dev_test_db_data`、`localepilot_dev_test_wp_data`），且仅绑定本机回环地址 `127.0.0.1:${LOCALEPILOT_WP_PORT:-18088}:80`（若 `18088` 端口冲突，可通过环境变量 `LOCALEPILOT_WP_PORT` 指定其他本地端口）：

```powershell
# 1. 在隔离的 phpqa (composer:2) 容器中执行真实 Composer 校验、PHPCS 与真实 PHPUnit
docker compose -p localepilot-dev-test run --rm phpqa composer validate --strict
docker compose -p localepilot-dev-test run --rm phpqa composer install --no-interaction --prefer-dist
docker compose -p localepilot-dev-test run --rm phpqa composer lint
docker compose -p localepilot-dev-test run --rm phpqa composer test

# 2. 启动隔离的 WordPress + MariaDB 容器并测试自包含 ZIP 激活/HPOS 声明/停用
./bin/build-dist-zip.ps1
docker compose -p localepilot-dev-test up -d db wordpress
docker compose -p localepilot-dev-test run --rm wpcli wp core install `
  --url="http://127.0.0.1:18088" `
  --title="LocalePilot Isolated Test Store" `
  --admin_user="admin" `
  --admin_password="dev_only_password" `
  --admin_email="admin@example.com" `
  --skip-email
docker compose -p localepilot-dev-test run --rm wpcli wp plugin install woocommerce --activate
docker compose -p localepilot-dev-test run --rm wpcli wp option update woocommerce_custom_orders_table_enabled yes
docker compose -p localepilot-dev-test run --rm wpcli wp plugin install /opt/localepilot-dist/localepilot.zip --activate
docker compose -p localepilot-dev-test run --rm wpcli wp plugin deactivate localepilot

# 3. 清理本项目隔离容器（仅停止本项目服务，不触碰外部或共享卷）
docker compose -p localepilot-dev-test down
```

### 4.3 WSL 本地开发测试路线（项目隔离临时 Composer PHAR）

当本机 Docker Daemon 未运行时，可使用本机 WSL（`Ubuntu-24.04`，含 `PHP 8.3.6` CLI 与 `Phar`/`openssl` 扩展）执行语法检查，或在授权后使用**仅存放于项目 `.tools/composer.phar` 的隔离临时 Composer PHAR**（已被 [.gitignore](.gitignore) 与 [.distignore](.distignore) 排除，且由 [bin/build-dist-zip.ps1](bin/build-dist-zip.ps1) 强制拦截，绝不进入生产插件 ZIP）：

```powershell
# 1. 使用明确 /mnt/e/... 路径执行 WSL PHP 语法检查
$base = '/mnt/e/Codex/.codex/跨境/same-url-multilingual-plugin'
foreach ($f in @('localepilot.php','uninstall.php','includes/Autoloader.php','includes/Compatibility.php','includes/Environment.php','includes/Plugin.php','tests/bootstrap.php','tests/Unit/PluginSkeletonTest.php')) {
  wsl -d Ubuntu-24.04 -e php -l "$base/$f"
}

# 2. 若在项目内放置隔离的临时 .tools/composer.phar（仅限开发测试，不安装到客户服务器）
wsl -d Ubuntu-24.04 -e php "$base/.tools/composer.phar" validate --strict --working-dir="$base"
wsl -d Ubuntu-24.04 -e php "$base/.tools/composer.phar" install --no-interaction --prefer-dist --working-dir="$base"
wsl -d Ubuntu-24.04 -e php "$base/.tools/composer.phar" lint --working-dir="$base"
wsl -d Ubuntu-24.04 -e php "$base/.tools/composer.phar" test --working-dir="$base"
```

### 4.4 开发 CI 流水线配置（仅配置定义，尚未在远端 GitHub Actions 触发执行）

仓库包含 [.github/workflows/ci.yml](.github/workflows/ci.yml) 工作流配置文件（**注：当前仅作为 CI 配置定义存在，尚未在远端 GitHub Actions 实际触发运行，严禁将其视为“CI 已运行/已通过”**），其定义的待远端执行矩阵为：
- `PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3` 矩阵 `php -l`、`composer validate --strict`、`composer lint`（PHPCS）与真实 `composer test`（PHPUnit 9.6）。
- `wp_track: [latest, previous-major] × wc_track: [latest, previous-major]` 四格矩阵，调用 `bin/build-dist-zip.ps1` 构建纯净 ZIP 并在容器化 WordPress + WooCommerce (HPOS) 环境中完成安装、激活、HPOS/Blocks 特性核验与停用卸载测试（本机已在 WSL `PHP 8.3.6` 下通过 `bin/run-wp-wc-integration.php` 实测该四格 WP/WC 组合；`PHP 7.4–8.2` 运行时仍为 `Unverified`，详见 [MARKETPLACE_READINESS.md](MARKETPLACE_READINESS.md)）。
