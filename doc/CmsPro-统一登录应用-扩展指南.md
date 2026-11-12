# CmsPro 统一登录扩展指南

> 版本：2.0.9 | 更新日期：2026-09-04
> 适用应用：CmsproSso v2.0.9+

---

## 一、概述

统一登录应用为当前系统提供前台用户统一登录入口，支持账号密码登录与微信登录。本指南说明其他应用如何调用本应用的 Service 层、钩子点、辅助函数等扩展接口，以及如何复用其登录能力。

**定位说明**：本应用与「聚合登录」不同，**仅对当前系统使用，不对外开放**。它可挂载和托管系统登录功能，例如关闭系统默认登录，只允许从本应用登录前台账号。

## 二、前置检查

调用本应用扩展接口前，需确认：

1. 应用已安装并启用（`apps` 表 `cmspro.sso` 记录 status=1）
2. 如需微信登录，后台「微信设置」已配置 AppID/AppSecret
3. 如需 QQ 登录，后台「QQ登录设置」已配置 AppID/AppKey 并启用（功能选项支持「QQ扫码登录」「QQ网页登录」两种模式）

```php
// 检查应用是否启用
$app = \App\Models\AppModel::where('app_id', 'cmspro.sso')->first();
$enabled = $app && $app->status->value === 1;
```

## 三、Service 层调用

### 3.1 服务类

| 服务类 | 命名空间 | 职责 |
|--------|---------|------|
| `SettingService` | `App\Apps\CmsproSso\Services\SettingService` | 读取应用配置 |
| `ShareService` | `App\Apps\CmsproSso\Services\ShareService` | 生成前台页面 URL（双模式兼容） |
| `WechatOauthService` | `App\Apps\CmsproSso\Services\WechatOauthService` | 微信网页授权（OAuth2）能力 |
| `SmsService` | `App\Apps\CmsproSso\Services\SmsService` | 短信发送门面（按服务商调度发送器） |
| `OAuthService` | `App\Apps\CmsproSso\Services\OAuthService` | 快捷模式服务端 OAuth2 授权码流程（授权码/令牌签发与校验） |
| `RelayClientService` | `App\Apps\CmsproSso\Services\RelayClientService` | 快捷模式客户端中转（与服务端通信） |

### 3.2 公开方法

**SettingService**

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `get(string $key, string $default = '')` | 配置键 | string | 读取配置值 |
| `isWechatConfigured()` | - | bool | 微信是否已配置（AppID+AppSecret 非空） |
| `isWechatOauthProxyEnabled()` | - | bool | 是否启用网页授权外挂 |
| `getWechatOauthUrl()` | - | string | 获取外挂地址 |
| `getDefaultRedirect()` | - | string | 获取登录默认跳转地址 |
| `isPhoneLoginEnabled()` | - | bool | 是否启用手机验证码登录 |
| `isEmailLoginEnabled()` | - | bool | 是否启用邮箱验证码登录（开关开启且所选发信方式可用；「SMTP邮箱」接管系统邮件时无需系统自身配置 SMTP） |
| `getEmailMailer()` | - | string | 当前发信方式（`system` 系统邮件配置 / `smtpmail` SMTP邮箱应用）；SMTP邮箱 接管系统邮件时强制返回 `smtpmail` |
| `isSmtpmailAppReady()` | - | bool | 「SMTP邮箱」应用（`cmspro.smtpmail`）是否已安装并启用 |
| `isSmtpmailTakeoverActive()` | - | bool | 「SMTP邮箱」应用是否已启用且开启「接管系统邮件」 |
| `getSmsChannel()` | - | string | 当前短信通道（`builtin` 内置短信服务 / `niuren` 短信发送应用） |
| `isSmssendAppReady()` | - | bool | 「短信发送」应用（`niuren.smssend`）是否已安装并启用 |
| `getSmssendTemplateAlias()` | - | string | 短信发送应用通道使用的模板标识（alias） |
| `getSmsProvider()` | - | string | 获取当前短信服务商（aliyun/tencent/volcengine），仅内置短信服务通道有效 |

**ShareService**（静态方法）

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `frontUrl(string $path)` | 前台路径 | string | 生成前台页面 URL（path/domain 双模式）。path 模式下若当前请求位于绑定的子域名（如论坛 `bbs.example.com`），自动返回主域名下的绝对 URL（基于 `config('app.url')`），避免跳转到子域名下不存在的 `/sso` 路径

**SmsService**

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `sendVerificationCode(string $phone, string $code)` | 手机号、验证码 | array | 发送验证码短信，返回 `['success'=>bool,'message'=>string]`；先按 `SettingService::getSmsChannel()` 分流——内置通道按 `sms_provider` 调度对应发送器，短信发送应用通道交由 `Services/Sms/NiurenSmsSender.php` 按模板标识调用该应用 `SendService::sendByAlias()` |
| `isConfigured()` | - | bool | 按当前通道校验配置完整性：内置通道校验所选服务商凭证/签名/模板，短信发送应用通道校验该应用已启用且模板标识非空 |

**WechatOauthService**

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `buildOauthRedirectUrl(string $redirectUri, string $state = '')` | 回调地址、state | array | 生成微信授权跳转链接 |
| `getOpenidByOauthCode(string $code)` | 授权 code | array | code 换 openid/unionid/access_token |
| `getUserInfoByToken(string $accessToken, string $openid)` | token、openid | array |计算取昵称/头像 |

**QqOauthService**

| 方法 | 参数 |返回值 |说明 |
|------|------|--------|------|
| `buildOauthRedirectUrl(string $redirectUri, string $state = '')` | 回调地址、state | array |生成 QQ 互联授权跳转链接 |
| `getOpenidByOauthCode(string $code)` | 授权 code | array |code 换 access_token/openid |
| `getUserInfoByToken(string $accessToken, string $openid)` | token、openid | array |获取昵称/头像 |
| `redirectUri()` | - | string |计算取 QQ 授权回调地址（`ShareService::frontUrl('/auth/qq/callback')`） |

**OAuthService**（快捷模式服务端）

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `isServerEnabled()` / `isClientEnabled()` | - | bool | 站点角色判断 |
| `relayPlatforms()` | - | array | 可中转社交平台列表（已启用且凭证已配置），元素 `['platform'=>..., 'name'=>...]` |
| `isPlatformAvailable(string $platform)` | 平台标识 | bool | 平台是否可中转 |
| `findEnabledClient(string $clientId)` | 客户端ID | ?Client | 查找启用状态的客户端 |
| `isRedirectUriAllowed(Client $client, string $redirectUri)` | 客户端、回调地址 | bool | 回调地址是否在白名单内（精确匹配） |
| `issueAuthCode(Client $client, string $dataToken, string $platform, string $redirectUri)` | 客户端、社交授权数据暂存键、平台、回调地址 | string | 签发一次性授权码（5 分钟有效，user_id 为 null 纯透传） |
| `exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri)` | 凭证与授权信息 | array | 授权码换访问令牌，返回 `['success','message','access_token','token_type','expires_in']` |
| `resolveToken(string $token)` | 令牌明文 | ?array | 解析有效令牌，返回 `['data_token','client_id','platform']`（纯透传：不关联服务端用户，data_token 为社交授权数据暂存键） |
| `revokeToken(string $token)` | 令牌明文 | bool | 吊销访问令牌 |
| `userinfoData(string $dataToken)` | 社交授权数据暂存键 | array | 凭 data_token 透传社交平台真实数据（openid/unionid/nickname/avatar/access_token/refresh_token/platform/raw_data），暂存数据一次性取回 |

**RelayClientService**（快捷模式客户端）

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `isConfigured()` | - | bool | 客户端中转条件是否齐备（模式+服务端地址+凭证） |
| `authorizeUrl(string $platform, string $state)` | 平台（可空）、state | string | 服务端 authorize 授权跳转地址 |
| `platforms()` | - | array | 拉取服务端可中转平台列表（通信失败返回空数组） |
| `exchangeCode(string $code)` | 授权码 | array | 服务器间 code 换 access_token |
| `userinfo(string $accessToken)` | 令牌明文 | ?array | 获取社交授权信息（透传社交平台真实 openid 等数据，失败返回 null） |
| `callbackUrl()` | - | string | 本站中转回调地址 |

### 3.3 调用示例

```php
// 通过 app() 容器调用
$settingService = app(\App\Apps\CmsproSso\Services\SettingService::class);
$wechatEnabled = $settingService->get('wechat_enabled', '0');

// 生成前台登录页 URL（自动适配 path/domain 模式）
$loginUrl = \App\Apps\CmsproSso\Services\ShareService::frontUrl('/login');

// 复用微信授权能力
$wechatService = app(\App\Apps\CmsproSso\Services\WechatOauthService::class);
$result = $wechatService->buildOauthRedirectUrl($callbackUrl, $state);

// 复用短信能力（需先开启手机验证码登录并配置短信服务商凭证）
$smsService = app(\App\Apps\CmsproSso\Services\SmsService::class);
if ($smsService->isConfigured()) {
    $result = $smsService->sendVerificationCode('13800138000', '123456');
}

// 快捷模式：判断站点角色
$settingService->isRelayServer(); // 服务端（提供中转）
$settingService->isRelayClient(); // 客户端（委托中转）

// 快捷模式服务端：校验令牌并透传社交授权数据（供服务端自身的其他逻辑复用）
$oauthService = app(\App\Apps\CmsproSso\Services\OAuthService::class);
$context = $oauthService->resolveToken($plainToken);
if ($context) {
    $dataToken = $context['data_token'];   // 社交授权数据暂存键
    $platform = $context['platform'];      // 发起登录的平台
    $data = $oauthService->userinfoData($dataToken); // 社交平台真实数据（一次性取回）
}
```

## 四、钩子扩展点

### 4.1 自定义钩子点

当前版本 `Hooks.php` 预留了钩子注册入口，但尚未注册具体钩子点。后续版本可在此注册自定义 Action/Filter 钩子，供其他应用扩展登录流程（如登录成功后同步用户信息、注册后初始化业务数据等）。

### 4.2 监听系统钩子

本应用复用系统 `user.*` 系列钩子（由系统 `UserAuthController` 触发）。其他应用可通过系统钩子扩展登录流程：

| 钩子点 | 类型 | 参数 | 说明 |
|--------|------|------|------|
| `user.before_login` | Filter | $credentials | 登录前拦截 |
| `user.after_login` | Action | $user | 登录后触发 |
| `user.before_register` | Filter | $data | 注册前拦截 |
| `user.after_register` | Action | $user | 注册后触发 |

> 注意：本应用通过 `Auth::guard('web')` 直接登录，不经过系统 `UserAuthController`，因此**不触发** `user.*` 系列钩子。如需在统一登录流程中扩展，应通过本应用 `Hooks.php` 预留的扩展点实现。

## 五、辅助函数

本应用未注册全局辅助函数。前台 URL 生成通过 `ShareService::frontUrl()` 静态方法实现。

## 六、模型引用

| 模型 | 表名 | 说明 |
|------|------|------|
| `App\Apps\CmsproSso\Models\LoginLog` | `app_cmspro_sso_login_logs` | 登录日志 |
| `App\Apps\CmsproSso\Models\Client` | `app_cmspro_sso_clients` | 快捷模式客户端 |
| `App\Apps\CmsproSso\Models\AuthCode` | `app_cmspro_sso_auth_codes` | 快捷模式授权码（一次性，5 分钟） |
| `App\Apps\CmsproSso\Models\AccessToken` | `app_cmspro_sso_access_tokens` | 快捷模式访问令牌（1 小时，库存 SHA256 哈希） |

**Client 字段**

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| client_id | string(32) | 客户端ID（sso_+16 位随机 hex，唯一） |
| secret_hash | string(255) | 密钥哈希（password_hash，明文仅创建/重置时展示一次） |
| name | string(100) | 客户端站点名称 |
| redirect_uris | text | 回调地址白名单（每行一个完整 URL，精确匹配） |
| status | tinyint | 0-禁用 1-启用 |
| remark | string(255) | 备注 |
| create_time / update_time | timestamp | 时间戳 |

## 七、注意事项

1. **数据隔离**：本应用复用系统 `users`、`user_third_party` 表，不新建用户表。微信登录通过 `user_third_party`（platform=wechat + open_id）关联用户。
2. **钩子归属**：本应用直接使用 `Auth::guard('web')` 登录，不触发系统 `user.*` 钩子。扩展登录流程应通过本应用 `Hooks.php` 预留扩展点。
3. **配置缓存**：修改配置后需执行 `php artisan route:clear && php artisan config:clear`（涉及访问模式时）。
4. **依赖声明**：composer 依赖仅 `endroid/qr-code`；应用级为**可选依赖**——「验证码」（人机验证码）、「SMTP邮箱」（邮箱发信方式之一）、「短信发送」（短信通道之一），均通过 `AppModel` + `AppStatus::ENABLED` 判断安装/启用状态，并以 `function_exists()` / `class_exists()` 守卫实际调用，缺失时降级为友好提示或置灰引导，不影响其余登录方式。
5. **微信登录未配置**：调用 `WechatOauthService` 前应先通过 `SettingService::isWechatConfigured()` 检查，未配置时返回友好提示。
6. **短信未配置**：调用 `SmsService::sendVerificationCode()` 前应先通过 `SmsService::isConfigured()` 检查（按通道分流校验，详见 3.2），未配置时返回友好提示；走短信发送应用通道时本应用不保存任何服务商凭证，发送结果可在该应用「发送记录」中按 `caller_app=cmspro.sso` 核对。
7. **开放重定向防护**：登录跳转地址通过 `isSafeRedirect()` 校验，仅允许站内相对路径或同域绝对地址。
8. **短信凭证安全**：短信服务商的 SecretKey 等敏感配置以密文存储，仅平台后台可见，应用内部不得打印日志。
9. **绑定与登录 state 隔离**：绑定授权 state 存 session 键 `cmspro_sso_{platform}_bind_oauth_state`（PC 扫码绑定存 Cache `cmspro_sso_bind_state:`，含目标用户 id，回调据此落库），登录 state 存 `cmspro_sso_{platform}_oauth_state`（PC 扫码存 Cache `cmspro_sso_login_state:`）；绑定结果轮询走 Cache `cmspro_sso_bind_result:`。三者共用回调地址，回调按 state 校验位置区分模式，禁止混用。
10. **解绑保护**：解绑前须统计可登录方式（密码/手机/微信/QQ），解绑后为 0 时拒绝（防账号锁死）。平台随机密码以 `phone_`/`wx_`/`qq_` 前缀存储，不可登录，不计入统计。
11. **快捷模式令牌安全**：访问令牌明文仅签发时返回一次，库存 SHA256 哈希不可逆推；授权码一次性使用且 5 分钟有效；回调地址白名单精确匹配，禁止使用前缀/通配匹配（防开放重定向）。
12. **快捷模式身份锚点**：服务端为纯跳板（不建号、不绑定、不保存任何用户数据），客户端以「社交平台真实 openid」为 open_id（platform=relay）建立本地映射，同一社交账号无论经哪个服务端中转、中转哪个社交平台均对应同一本地账号；社交授权数据（openid/unionid/昵称/头像/令牌/raw_data）经 Cache 暂存凭 data_token 一次性透传，服务端不落库。
13. **快捷模式扩展平台**：中转透传链路平台无关——新增可中转社交平台时，在 `OAuthService::PLATFORMS` 增加定义并为该平台实现配置判断（启用开关 + 凭证完整性）与社交回调（`/auth/{platform}/callback`），服务端开放 API 与客户端登录页入口自动生效，服务端与客户端中转逻辑均无需改动。
14. **框架账号页注入**：前台账号页以 Tab 形式组织（默认「个人信息」），「账号绑定」Tab 通过 View composer（`ServiceProvider::registerUserAccountComposer()`）读取系统框架账号页视图 `user.account.index` 后经其扩展位注入，不覆盖框架路由与视图——Tab 标题区 `@stack('user_account_tab_titles')`（`ul.layui-tab-title` 内，注入 `<li>账号绑定</li>`）、Tab 内容区 `@stack('user_account_tab_contents')`（`.layui-tab-content` 内，注入 `.layui-tab-item` 面板）、脚本区 `@stack('user_account_scripts')`（layui.js 加载之后）；标题与内容必须成对注入且顺序一致，否则 Tab 标题与面板错位。
15. **绑定中心内嵌模式**：共用组件 `Views/Home/partials/bind-center.blade.php` 默认以弹层呈现（宿主页面放 `.sso-bind-center-open` 入口元素触发）；若宿主页面存在 `.sso-bind-center-inline` 容器（框架账号页「账号绑定」Tab 面板即为此形态），组件会把 `#sso-bind-center-modal` 移入该容器直接展示并自动加载绑定列表，此时无需入口元素。二级交互（手机验证码绑定、扫码绑定）两种模式下均为弹层。绑定中心组件依赖 layui/jQuery，必须注入脚本区，不可置于 layui.js 之前，否则报 `layui is not defined`。

## 八、版本与更新日志

| 版本 | 日期 | 更新人 | 说明 |
|------|------|--------|------|
| 2.8.0 | 2026-11-12 |开发团队 |邮箱验证码登录新增「发信方式」（`email_mailer`：system/smtpmail）：`SettingService` 新增 `getEmailMailer()` / `isSmtpmailAppReady()` / `isSmtpmailTakeoverActive()`，`isEmailLoginEnabled()` 判定优先级改为「接管系统邮件 > 所选发信方式可用性 > 系统 SMTP 配置」，「SMTP邮箱」应用（`cmspro.smtpmail`）未安装/未启用时设置页选项置灰并显示引导卡片。手机验证码登录新增「短信通道」（`sms_channel`：builtin/niuren）：`SettingService` 新增 `getSmsChannel()` / `isSmssendAppReady()` / `getSmssendTemplateAlias()`，新增 `Services/Sms/NiurenSmsSender.php` 按模板标识调用「短信发送」应用（`niuren.smssend`）`SendService::sendByAlias()`（发送记录 `caller_app=cmspro.sso`），`SmsService::sendVerificationCode()` / `isConfigured()` 改为先按通道分流再按服务商调度，**公开方法签名不变，既有调用方无需改动**；设置页短信模板由单行文本改为 layui `lay-search` 可搜索下拉（配置项 `sms_niuren_template_alias`，新增 `SettingController::smssendTemplateOptions()`），保留空选项与「（当前不可用）」选项防止已存值被静默清空。注意：跨应用命名路由 `niuren.smssend.admin.templates.index` 仅在该应用启用时注册，设置页「模板管理」链接使用硬编码路径 `/admin/niuren/smssend/templates` 以避免 `route()` 抛 `RouteNotFoundException` |
| 2.7.0 | 2026-10-20 |开发团队 |前台账号页「账号绑定」改为 Tab 展示：框架账号页视图 `resources/views/user/account/index.blade.php` 改用 `layui-tab layui-tab-card` 并引入 layui `element` 模块，默认选中「个人信息」，扩展位由 `user_account_content` 拆分为 `user_account_tab_titles`（Tab 标题区）与 `user_account_tab_contents`（Tab 内容区），`user_account_scripts` 保持不变；应用侧 `Views/User/partials/bind-card.blade.php` 更名为 `bind-tab.blade.php`（输出 `.layui-tab-item` 面板 + `.sso-bind-center-inline` 内嵌容器），`ServiceProvider::registerUserAccountComposer()` 改为推送 Tab 标题 + Tab 面板 + 脚本三处；共用绑定中心组件 `Views/Home/partials/bind-center.blade.php` 新增内嵌模式（存在 `.sso-bind-center-inline` 容器时移入 DOM 直接展示并自动加载列表，前台首页 `.sso-bind-center-open` 弹窗入口行为不变）；`SsoAccountBindEntryTest` 断言同步更新 |
| 2.6.4 | 2026-10-20 |开发团队 |前台账号页「账号绑定」注入方式改为「读取框架视图 + View composer 注入扩展位」：移除此前覆盖系统同名路由 `user.account`（`/user/account`）渲染应用整页视图的做法，删除应用侧 `Views/User/account.blade.php` 与 `Controllers/User/AccountController.php`；新增 `ServiceProvider::registerUserAccountComposer()` 经框架账号页扩展位注入账号绑定卡片（`Views/User/partials/bind-card.blade.php`）与共用绑定中心组件（`Views/Home/partials/bind-center.blade.php`），路由交还框架，框架账号页后续更新可正常透传 |
| 1.8.1 | 2026-09-02|开发团队 |修复 path 模式下 `frontUrl()` 跨子域名跳转：当前请求位于绑定的子域名（如论坛 `bbs.example.com`）时，自动返回主域名下的绝对 URL（基于 `config('app.url')`），避免跳转到子域名下不存在的 `/sso` 路径 |
| 1.0.0 | 2026-08-13 | 开发团队 | 初始版本：统一登录应用，支持账号密码 + 微信登录，双模式访问 |
| 1.1.0 | 2026-08-13 | 开发团队 | 新增「覆盖系统登录」开关，支持回车提交表单，移除前台 home_menus 导航 |
| 1.1.1 | 2026-08-13 | 开发团队 | 修复子域名模式：根路径 `/` 跳转登录页；覆盖系统重定向到子域名 |
| 1.2.1 | 2026-08-25 | 开发团队 | 根路径 `/` 调整为首页：已登录停留首页，未登录跳转登录页 |
| 1.3.0 | 2026-08-25 |开发团队 |新增手机验证码登录：`SmsService` 门面 + 阿里云/腾讯云/火山引擎三个发送器，验证码限频/有效期/一次性失效，未注册手机号自动建号 |
| 1.6.0 | 2026-09-02 |开发团队 |新增 QQ 互联登录：`QqOauthService`（授权 URL 构建 / code 换 openid / 用户信息获取）+ `isQqConfigured()` 配置判断；后台新增「功能选项」（QQ扫码登录 / QQ网页登录，默认网页登录），前端按模式分流 |
| 1.7.0 | 2026-09-02 |开发团队 |新增账号绑定中心：`bindings()` 绑定状态查询、`oauthBindUrl()` 绑定授权链接（state 存 session 绑定专用键 `cmspro_sso_{platform}_bind_oauth_state`）、`bindOauthCallback()` 回调绑定落库（openid 冲突检测）、`bindPhone()` 短信验证码绑定、`unbind()` 解绑保护（统计密码/手机/微信/QQ 可登录方式，仅剩一种拒绝解绑；`userCanPasswordLogin()` 通过密码前缀 `phone_`/`wx_`/`qq_` 识别平台随机密码） |
| 1.8.0 | 2026-09-02 |开发团队 |新增邮箱验证码登录：`SettingService::isEmailLoginEnabled()`（开关 + `mail_configured()` 双重判断）、`sendEmailCode()`（复用系统注册验证码缓存键与邮件模板，RateLimiter 每分钟每邮箱 3 次）、`emailLogin()`（验证码一次性失效，未注册邮箱事务内建号）、`createEmailUser()`（用户名取邮箱 @ 前缀，冲突加随机后缀） |
| 1.9.0 | 2026-09-02 |开发团队 |绑定中心微信/QQ 支持 PC 扫码绑定：`oauthBindUrl()` 支持 `device=pc`（state 存 Cache 绑定键 `cmspro_sso_bind_state:` 含目标用户 id）、`bindPcOauthCallback()` 跨设备回调落库（绑定目标取自 Cache，不依赖回调请求自身登录态）、`oauthBindStatus()` 轮询绑定结果（`cmspro_sso_bind_result:`，成功 `bound`/失败 `error`） |
| 1.10.0 | 2026-09-03 |开发团队 |新增快捷模式（社交登录中转）：`OAuthService`（服务端授权码流程：签发/换取/解析/吊销）+ `RelayClientService`（客户端与服务端通信）+ `isRelayServer()/isRelayClient()` 角色判断；开放 API `/api/cmspro/sso/oauth`（platforms/token/userinfo/revoke）；客户端以「sso+服务端用户id」为身份锚点建号登录 |
| 2.0.9 | 2026-09-04 |开发团队 |服务端重构为纯跳板透传（不建号、不绑定、不落任何用户数据）：`OAuthService` 去 User 依赖——`issueAuthCode` 改收 dataToken（user_id 为 null）、`resolveToken` 返回 data_token 上下文、`userinfoData(string $dataToken)` 凭暂存键透传社交平台真实数据；`RelayClientService::userinfo` 期望透传结构（openid/unionid/nickname/avatar/令牌/platform/raw_data）；客户端 relayCallback/relayLoginStatus 改以社交真实 openid 为身份锚点建号；`OAuthController@authorize` 已登录不再静默签发；迁移 auth_codes/access_tokens 表 user_id 改 nullable、access_tokens 新增 data_token；透传链路平台无关，新增第三方登录仅需服务端接入社交回调 |
