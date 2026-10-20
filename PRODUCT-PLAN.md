# LocalePilot 同 URL 多语言商店插件

**方案版本：** v0.2（市场核查后评审稿）  
**目标：** 面向第三方 WordPress/WooCommerce 商家销售的订阅产品；保持商品页面完整 URL 不变，根据访客语言偏好显示预先翻译并保存的内容。  
**工作名：** LocalePilot，正式品牌名待定。

## 1. 产品结论

**收费方案暂不定价。** 竞品调研显示有多种成熟做法，不能仅凭“简单”就认定单一年费适合本产品。首选待验证假设是“每个生产站点一份年度插件授权 + 商家自带翻译 API 密钥”，不设置插件侧词数档位；是否采用、年费定多少，需经目标商家访谈和付费试点验证。

- WordPress 插件负责语言偏好、译文存储和 WooCommerce 页面渲染。
- 商家选择 DeepL、Google、百度或 Gemini，并在插件后台配置自己的 API 凭证；翻译 API 费用直接由供应商向商家收取。
- 若采用上述假设，订阅服务只管理年度授权、站点绑定、插件更新和支持；翻译供应商直接向商家计费，避免插件承担不可预测的翻译成本。
- 前台浏览只读取站点本地译文，不在访客请求中实时调用翻译 API。
- 目标用户是以广告、社交媒体、邮件和私域流量获客的商店。所有语言使用同一 URL 的方案不适合把多语言自然搜索排名作为主要卖点。

同 URL 的主要代价是缓存必须按语言隔离，搜索引擎也无法可靠地将同一 URL 的不同正文作为独立语言版本收录。Google 对地区自适应页面明确提示抓取和索引风险，并建议使用独立语言 URL 与 `hreflang`。这一限制需要直接写进销售页和安装向导，不能宣传“同 URL 多语言 SEO”。[Google：地区自适应页面](https://developers.google.com/search/docs/specialty/international/locale-adaptive-pages)

## 2. 客户和核心场景

### 首发客户

- WooCommerce 商品目录已经有一定规模，想降低广告落地页的语言阻力。
- 暂不需要每种语言独立网址或单独经营国家站。
- 愿意接受商家自行审校机器翻译，并使用已认证的主题/缓存组合。

### 核心用户故事

1. 商家安装插件、连接订阅、设置源语言和目标语言。
2. 商家批量翻译商品、分类、属性和店铺界面文案，修改产品术语后再次审校。
3. 访客手动选语言后，当前 URL 不变化，购物车、结账和邮件延续该语言。
4. 语言判断失败、缓存不兼容或订阅到期时，店铺仍可用，系统给商家明确提示。

## 3. 功能范围

### MVP 包含

- 同 URL 语言上下文与可见的语言切换器。
- 浏览器 `Accept-Language` 优先；IP 国家仅作初次建议/回退；商家手动选择优先并保存在 Cookie。
- 商品标题、短/长描述、分类、标签、属性名/值、变体显示文本的预翻译、编辑、审校、锁定和发布。
- WordPress/WooCommerce 核心字符串、商品列表、搜索、经典购物车/结账、运费和支付方式标签、错误提示。
- 订单创建时记录语言；订单确认、发货、退款等邮件按订单语言渲染。
- 管理员可查看翻译队列、失败原因、译文状态、API 连接、订阅授权和缓存诊断。
- 翻译供应商采用适配器设计，可接入 Gemini、DeepL、Google Cloud Translation 和百度翻译；每个接口需单独认证和测试。
- 前台不直接调用翻译 API，不向浏览器下发任何供应商密钥。

### 后续版本

- WooCommerce Cart/Checkout Blocks 和 Store API 的完整覆盖。
- 页面构建器、第三方支付、加购/捆绑、订阅商品、优惠/配送插件兼容包。
- 多人审校流程、团队角色、更多术语表、批量导入导出。
- 若未来用户接受语言 URL，再单独规划 SEO URL 模式；不把它伪装成同 URL 功能的一部分。

### 明确边界与通用化规格

- **面向任意第三方商家的通用化标准（严禁单站硬编码）**：插件作为可分发的标准化商业产品，严禁在代码、默认配置、数据库迁移或测试用例中硬编码任何特定店铺域名（如 `modaviora.com` 等）、特定品牌词、特定主题 CSS 选择器、特定分类/商品 ID/SKU 或特定主机路径。源语言、目标语言列表、语言切换器挂载方式（短代码、菜单项、小工具、浮动栏或 Hook）、术语表及翻译 API 凭证均须由商家在后台自由配置。
- **客户服务器零构建工具依赖**：商家仅需通过标准 WordPress 插件 ZIP 包上传或解压即可安装运行，运行时使用插件内置的 PSR-4 自动加载器与 WordPress/WooCommerce 原生 API（`$wpdb`、`wp_remote_request()`、Action Scheduler、`WC_Order` CRUD、WP Object Cache），**不要求客户服务器安装 Composer、Node/npm、SSH/CLI 或非标准 PHP 扩展**。
- **第三方支付与托管组件边界**：第三方支付服务托管的 iframe 内容由该支付服务商控制，插件仅在标准网关过滤器支持时传递语言参数，不能保证翻译托管 iframe 内部元素。
- **主题与第三方插件边界**：核心内容与商品数据通过 WordPress/WooCommerce 标准数据层与渲染 Filter 注入，不强行覆盖主题模板文件；硬编码在主题或第三方插件中的任意非标准文字需要扫描/注册，不能承诺所有任意第三方插件无需适配就能自动翻译。
- **隐私与数据安全边界**：订单、客户姓名、邮箱、地址、支付信息和评论作者资料不发送给任何翻译供应商。
- **多币种、税率与物流解耦兼容**：语言仅控制界面与商品文本显示，绝不修改商品金额、币种、汇率、账单国家、税率或配送国家，这些仍走 WooCommerce 及第三方多币种/税务插件的独立规则。

## 4. 架构

```text
访客浏览器
  ├─ lp_lang Cookie：手动选择优先，覆盖整个站点和同源 AJAX
  └─ 仍访问原 URL：/product/example/ 不变
             │
             ▼
商家 WordPress 插件
  ├─ Locale Resolver：Cookie > Accept-Language > IP 建议 > 默认语言
  ├─ Woo/WordPress Hooks：读取本地译文并渲染
  ├─ 本地译文表 + Object Cache：前台不依赖云端可用性
  ├─ Action Scheduler：后台异步批量翻译/重试/限速
  ├─ 订单语言 Meta：邮件按订单创建时语言发送
  └─ Cache Health Check：检测不同语言是否错误共用 HTML 缓存
             │ 后台 HTTPS 请求；凭证只在商家服务器端
             ├──────── Gemini API（供应商向商家计费）
             ├──────── DeepL API（供应商向商家计费）
             ├──────── Google Cloud Translation（供应商向商家计费）
             └──────── 百度翻译 API（供应商向商家计费）

LocalePilot License Service
  ├─ 年度订阅状态和生产站授权
  ├─ 插件更新签名与版本下载
  └─ 续费、迁站、解绑和支持入口
```

### Locale Resolver 顺序

1. 访客手动选择的 `lp_lang` Cookie。
2. 浏览器 `Accept-Language` 中商家已启用的语言。
3. 可信的 Cloudflare `CF-IPCountry` 或已配置 GeoIP 信息，作为语言建议/回退。
4. 商家默认语言。

首次检测推荐显示提示条，不强迫跳转。国家与语言不一一对应，且 IP 会受 VPN、漫游和代理影响。只有确认请求经过可信代理后才信任其 IP 国家头。

### 本地翻译记录

自定义表建议使用字段：`object_type`、`object_id`、`field_key`、`locale`、`source_hash`、`translated_value`、`review_status`、`engine_version`、`updated_at`。

- 唯一计量键为“源文本哈希 × 目标语言”；源文没变不重复调用、不重复收费。
- 翻译前保护 HTML/短代码/链接/占位符/价格/单位/SKU；只发送可翻译文本段。
- 翻译供应商返回结果先入“待审校”，商家发布后才替换前台内容。
- 使用 WooCommerce 已包含的 Action Scheduler 处理异步任务，不在商品保存请求中等待供应商响应。[Action Scheduler 用法](https://actionscheduler.org/usage/)
- 通用 WordPress/WooCommerce 文案可通过已安装语言包和 `switch_to_locale()` 复用；商品正文、属性等内容仍需插件自己的字段译文映射。[WordPress `switch_to_locale()`](https://developer.wordpress.org/reference/functions/switch_to_locale/)

### 通用化与兼容性架构规范

1. **WooCommerce HPOS（`custom_order_tables`）强制兼容**：
   - 订单语言记录（如 `_localepilot_order_locale`）及所有订单相关元数据必须统一通过 `WC_Order::get_meta()`、`WC_Order::update_meta_data()` 与 `WC_Order::save()` 读写，严禁直接拼接 `wp_posts` 或 `wp_postmeta` SQL。
2. **结账架构显式声明（Classic vs. Blocks）**：
   - 第 1 阶段骨架初期曾先声明 `cart_checkout_blocks => false`，并在完成第 5 阶段 Store API (`/wc/store/v1/*`) 与 Cart/Checkout Blocks 订单语言快照适配及隔离实测后，在 [`Compatibility::get_feature_matrix()`](includes/Compatibility.php) 中正式切换为 `cart_checkout_blocks => true`（第三方 Blocks 私有 React 硬编码文本与跨域支付 iframe 边界见 [COMPATIBILITY.md](COMPATIBILITY.md)）。
3. **标准 Hook 注入与主题无关性**：
   - 优先通过 WooCommerce CRUD Filter（`woocommerce_product_get_name`、`woocommerce_product_get_description`、`woocommerce_product_get_short_description`、`woocommerce_product_variation_get_*`、`get_term`、`woocommerce_attribute_label` 等）在数据读取层注入已发布的本地译文，不依赖特定主题模板改写；具体第三方主题与缓存/插件组合（如 Storefront、Astra、Flatsome、WP Rocket 等）全部列为“需认证候选”，在未完成真实 Staging 实测前不得标为已支持（完整分级矩阵与渐进降级规则见 [COMPATIBILITY.md](COMPATIBILITY.md)）。
   - 前台语言切换器与提示条采用独立 CSS 命名空间（`lp-*`），不依赖特定主题 DOM 结构或前端框架。
4. **前台故障开放（Fail-Open）与后台故障闭合（Fail-Closed）**：
   - 前台浏览、加购、结账、订单邮件遇到译文缺失、数据库异常、API 超时或订阅过期时，必须无感回退至商店默认语言原文，绝不抛出致命错误或阻断购买。
   - 后台管理操作、设置保存、翻译队列触发与授权绑定必须严格校验 WordPress Capability（如 `manage_woocommerce` / `manage_options`）与 Nonce，未通过校验一律拒绝执行。

## 5. 订阅与变现

### 已核实的竞品公开定价（2026-10-20）

| 产品 | 公开价格与计量 | 对本产品的启示 |
|---|---|---|
| Weglot | Starter $170/年，10,000 个总唯一译词、1 种目标语言；Business $320/年，50,000 词、3 种语言。其计价还与翻译请求/页面访问额度有关。 | SaaS 可以按词数、语言数和访问/请求额度组合计价，但解释和预测成本更复杂。 |
| TranslatePress | Personal €99/年、1 个站点、50,000 AI 译词；Business €199/年、3 个站点、200,000 词。另售词包；Google/DeepL API key 可由客户提供并自行承担 API 费用。 | 同时存在“授权 + 内含额度”和 BYOK；客户自付 API 并不妨碍授权费模式。 |
| WPML | CMS €99/年，含 3 个生产站点、WooCommerce 支持和 22,500 AI 译词；超出后可按量购买。 | 授权档位可以与额度/多站点权益捆绑，但模型和计费规则更复杂。 |
| Polylang | Pro 起价 €99/年；WooCommerce 插件单独起价 €99/年；DeepL 翻译要求客户自带 API key。 | 年度软件授权 + BYOK 有直接先例；但把 WooCommerce 加价拆售会增加购买复杂度。 |

价格来自各厂商官方公开页，属于标价对比，不等于成交价、市场份额或客户支付意愿。竞品功能范围、品牌成熟度、SEO 路由和服务成本不同，不能简单按价格一比一照搬。[Weglot](https://www.weglot.com/pricing)、[TranslatePress](https://translatepress.com/pricing/)、[WPML](https://app.wpml.org/purchase)、[Polylang](https://polylang.pro/pricing/)

### 收费候选与当前判断

1. **候选 A：单一年度站点授权 + BYOK（首选试点假设）**。一份订阅对应一个生产站点，包含插件更新和标准支持；测试/预发布站不额外计费；不按语言数或词数分档，翻译供应商账单由客户直接支付。优点是收费直观、插件方没有随译量增长的 API 成本；缺点是客户须自行开通供应商账号、配置密钥并承担账单波动。
2. **候选 B：年度授权 + 包含翻译额度 + 自愿加购额度**。对初次使用更省心，能集中售卖；但需要供应商网关、预付/退款、额度账本、滥用控制、毛利和汇率管理，明显提高首版复杂度。
3. **候选 C：分档订阅（站点/语言/译量）**。竞品已有此模式，但当前没有证据证明本产品客户想要分档；也会违背“收费不要太复杂”的要求，暂不作为首发方案。

**结论：** 首发先测试候选 A，不宣称它已被市场验证，也暂不写死价格。先访谈 5–10 家目标商家并邀请 2–3 家真实试点，测试“愿意付多少钱、BYOK 是否可接受、是否更愿意买含额度方案”。再根据实际接入成本、支持工单、商家转化和续费意愿定价。若访谈显示商家普遍不愿管理 API key，再用候选 B 做对照试卖。

### 订阅状态规则

- 续费正常：翻译、编辑、同步、授权更新和支持均可用；翻译调用依商家 API 账号状态及余额。
- 付款失败/宽限期：保留已保存译文并继续显示，提醒商家更新账单。
- 订阅到期：停止需有效授权的新增翻译/编辑和插件更新支持；**不删除本地译文，不破坏已启用语言、购物车或结账**。具体过期权益仍需写入服务条款并接受法律审阅。
- 续费后恢复任务。商家可导出/删除译文和站点数据。

### 站点许可

- 插件绑定 SaaS `site_id`，使用短期签名权益令牌；不在每次前台请求中回调 SaaS。
- 区分 production 与 staging；迁站、解绑和授权转移需在控制台可见且可自助完成。
- 订阅/授权服务端化，不能依赖混淆本地 PHP 代码来防复制。
- 账单供应商待核对企业主体、收款地区、税务和退款能力后选择，核心业务只依赖 Billing Adapter。

## 6. WordPress 许可与开源复用

WordPress.org 插件目录要求目录内代码使用 GPL 或兼容许可证，允许 SaaS，但不允许 trialware。商业模式应明确区分 GPL 插件代码与订阅云服务、翻译额度、更新和支持。[WordPress.org 插件规则](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)

已检查 Polylang GitHub 项目：GPL-3.0，含开发测试目录；可作为 WordPress 多语言兼容测试对象，但它并未提供已验证的同 URL Cookie 模式，不建议直接 fork 后强拆 URL 路由。[Polylang GitHub](https://github.com/polylang/polylang)

## 7. 缓存策略与风险

### 缓存规则

- 同一 URL 返回不同语言，必须区分 `lp_lang` 缓存变体，不能让一个语言缓存泄漏给其他访客。
- WP Rocket 有动态 Cookie 缓存过滤器，可为语言 Cookie 建立不同缓存文件；它的官方文档指出，动态 Cookie 缓存与 Cloudflare APO/Cache Everything 等前置缓存层并不直接兼容。[WP Rocket 动态 Cookie 缓存](https://docs.wp-rocket.me/article/1313-create-different-cache-files-with-dynamic-and-mandatory-cookies)、[WP Rocket + APO 说明](https://docs.wp-rocket.me/article/1444-using-cloudflare-apo-with-wp-rocket)
- MVP 对未验证 CDN/缓存组合默认安全绕过 HTML 缓存，并给出可读诊断；验证后再为兼容组合启用语言缓存。
- Worker/边缘缓存列为后续适配。Cloudflare 部分 `fetch()` 自定义 Cache Key 功能有套餐约束，不能不核实用户套餐就承诺可用。[Cloudflare Workers Cache Key 文档](https://developers.cloudflare.com/workers/examples/cache-using-fetch/)
- 购物车、结账、我的账户与个性化响应不进公共 HTML 缓存。

### Cache Health Check

用同一商品 URL 分别模拟英语/法语 Cookie，检查返回的 `html lang`、商品标题和翻译标记是否不同；再重复请求确认缓存隔离。失败时关闭该缓存层 HTML 缓存或提示商家按已验证步骤配置，不能只看普通 HTTP 200。

## 8. UI 信息架构与已制作界面

插件后台：

1. **总览**：订阅状态、译文条目数（仅运营统计，不作收费额度）、启用语言、待审翻译、缓存/结账待检查项。
2. **语言与识别**：默认语言、启用语言、浏览器/IP 优先级、地区映射、提示条和 Cookie 保存期。
3. **翻译中心**：商品和 UI 字符串、状态、修改审校、术语锁定、队列/错误/用量。
4. **WooCommerce 覆盖**：商品/变体/分类/搜索/购物车/结账/邮件兼容矩阵。
5. **缓存诊断**：WP Rocket/CDN 检测、同 URL 双语言对照、安全回退与导出报告。
6. **订阅与站点**：年度授权、站点绑定/迁移/解绑、续费和账单；若采用 BYOK，清楚显示供应商账号由商家直接付费。

原型中的 `modaviora.com`、语言、用量和套餐只作为演示数据，不能当作现有站点配置或价格承诺。

## 9. 开发阶段

### Phase 0：产品验证

- 访谈 5–10 家 WooCommerce 商家，确认“完全同 URL”是硬需求还是只是不希望换域名。
- 选 2–3 家有意愿的 pilot，确认语言、主题、缓存和结账类型。
- 验证商家能接受同 URL 模式的 SEO 限制。

### Phase 1：付费 MVP

- 插件授权连接、年度订阅/站点绑定和更新服务；若试点验证 BYOK，再接入首批供应商适配器。首版不建设翻译额度账本或代收 API 费用。
- 插件授权连接、语言 Cookie、商品与分类翻译、术语表、审校和本地存储。
- Woo 商品页、经典购物车/结账、订单语言记录和邮件。
- WP Rocket 动态 Cookie 集成、缓存自检、安全回退和兼容矩阵。
- 错误/超时/供应商余额不足/订阅到期均不能影响已经保存的译文和结账。

### Phase 2：扩大兼容

- Woo Blocks/Store API、主流页面构建器、支付/优惠/配送扩展。
- 多人审批、批量导出、更多边缘缓存适配、站点管理和数据分析。

### 后续开发边界与阶段约束

- **不跨阶段抢跑**：第 1 阶段仅建立零 Composer 运行时依赖的插件入口、自动加载、环境与 HPOS/Blocks 兼容性声明及测试配置；未进入对应阶段前，不提前堆砌后台多语言 UI、翻译供应商调用、访客语言解析或正式授权计费代码。
- **不引入特定站点耦合**：后续所有阶段的代码提交不得包含任何特定商户域名、私有服务器配置、固定分类/商品 ID 或特定主题补丁；演示数据仅限文档说明或隔离的测试夹具（Fixture）。
- **不破坏第三方插件生态**：后续实现多语言过滤时，只作用于前台展示与订单邮件上下文，在后台编辑页（`is_admin()` 且非前台 `wc-ajax`）、REST 写入接口、库存同步、支付回调（Webhook/IPN）与多币种结算计算中保持原始数据不被篡改。
- **不假定市场审批结果**：代码与文档按 Woo Marketplace Extension 标准建设，但在未完成真实试点与合规审核前，不预设已获批准或绑定单一分发渠道。

## 10. 发布验收标准

- 同一个完整 URL，英语和法语 Cookie 得到对应商品内容，反复请求不串语言。
- 手动语言偏好优先于浏览器和 IP，清 Cookie 后才重新判断。
- `wc-ajax`、Store API、变体、优惠、运费和结账错误提示保持当前语言；金额、币种、配送国家不受翻译影响。
- 下单时固化语言，订单邮件正确；第三方托管支付 iframe 的限制在 UI 中明确告知。
- 源文本不变时复用译文、不重复调用；任务重试和并发任务幂等。
- 订阅到期后既有译文继续显示、结账继续工作；不删除站点数据。
- 对每个正式支持的 WordPress/WooCommerce/PHP/缓存组合，留有真实 staging 验收记录；完整首发兼容矩阵、核心支持/需认证/未认证/不支持边界、渐进降级规则及 Marketplace 发布阻断测试清单见 [COMPATIBILITY.md](COMPATIBILITY.md)。

## 11. 主要风险

| 风险 | 控制方式 |
|---|---|
| 不同语言共用同一缓存 | 语言 Cookie 缓存隔离、双语言自检、未认证时绕过 HTML 缓存 |
| SEO 预期不符 | 销售页明确无每语言独立 URL 收录；需要 SEO 的客户选择另一产品模式 |
| 电商术语误译 | 供应商适配器、术语表、数字/单位保护、人工审校、敏感属性变更检测 |
| WordPress 插件碎片化 | 限定并公开认证矩阵，不承诺所有插件自动兼容 |
| 供应商/网络不可用 | 前台只读本地译文，翻译队列失败重试，不让访客等外部 API 响应 |
| 订阅终止影响店铺 | 本地译文保留，过期只停新翻译与云端权益 |
| API 成本和滥用 | BYOK 模式下供应商直接计费；仍需源哈希去重、后台限速、失败重试和密钥保护；若改为代收 API 费用再建设额度/账本 |
| 隐私与跨境传输 | 不上传客户/订单数据，缩减传输文本，保留期限和删除流程透明 |

## 12. 首发前需要定案

- 首发语言和默认语言。
- 商家访谈/试点结果：BYOK 接受度、目标年费、对内含额度的偏好；在验证前不定最终售价。
- 云服务的收款主体、地区、税务和支付供应商。
- IP 首次访问只显示提示，还是商家可选择静默切换。
- MVP 首发支持 Classic Checkout 还是同时要求 Blocks 结账。
- WordPress.org GPL 基础版 + SaaS 服务，还是只从自有渠道分发；需做许可和商业条款复核。

## 13. 方案复核记录

- Gemini 4 Argon Medium：只读 Plan/Sandbox 复核。
- 外部模型使用范围：仅 Gemini；没有调用其他模型。
- 独立核验修正：主流 WPML/TranslatePress 路由不是可一键关掉的同 URL 模式；动态 Cookie 缓存与前置 Cloudflare HTML 缓存需要单独验证；不把取消订阅作为关闭商家现有译文的理由。
