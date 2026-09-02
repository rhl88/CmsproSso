# CmsPro 统一登录扩展指南

> 版本：1.2.1 | 更新日期：2026-09-02
> 适用应用：CmsproSso v1.5.4+

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
| `isEmailLoginEnabled()` | - | bool | 是否启用邮箱验证码登录（开关开启且系统已配置 SMTP 才返回 true） |
| `getSmsProvider()` | - | string | 获取当前短信服务商（aliyun/tencent/volcengine） |

**ShareService**（静态方法）

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `frontUrl(string $path)` | 前台路径 | string | 生成前台页面 URL（path/domain 双模式）。path 模式下若当前请求位于绑定的子域名（如论坛 `bbs.example.com`），自动返回主域名下的绝对 URL（基于 `config('app.url')`），避免跳转到子域名下不存在的 `/sso` 路径

**SmsService**

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `sendVerificationCode(string $phone, string $code)` | 手机号、验证码 | array | 按当前服务商发送验证码短信，返回 `['success'=>bool,'message'=>string]` |
| `isConfigured()` | - | bool | 当前服务商所需凭证是否已完整配置 |

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

**LoginLog 字段**

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| user_id | bigint | 用户ID，关联 users 表 |
| platform | string(20) | 登录方式：password/wechat/phone/qq（预留） |
| ip | string(45) | 登录IP |
| user_agent | string(500) | 浏览器UA |
| status | tinyint | 0-失败 1-成功 |
| message | string(255) | 登录结果说明 |
| create_time / update_time | timestamp | 时间戳 |

## 七、注意事项

1. **数据隔离**：本应用复用系统 `users`、`user_third_party` 表，不新建用户表。微信登录通过 `user_third_party`（platform=wechat + open_id）关联用户。
2. **钩子归属**：本应用直接使用 `Auth::guard('web')` 登录，不触发系统 `user.*` 钩子。扩展登录流程应通过本应用 `Hooks.php` 预留扩展点。
3. **配置缓存**：修改配置后需执行 `php artisan route:clear && php artisan config:clear`（涉及访问模式时）。
4. **依赖声明**：本应用无应用依赖（微信能力独立实现），仅依赖 `endroid/qr-code` composer 包。
5. **微信登录未配置**：调用 `WechatOauthService` 前应先通过 `SettingService::isWechatConfigured()` 检查，未配置时返回友好提示。
6. **短信未配置**：调用 `SmsService::sendVerificationCode()` 前应先通过 `SmsService::isConfigured()` 检查，未配置时返回友好提示。
7. **开放重定向防护**：登录跳转地址通过 `isSafeRedirect()` 校验，仅允许站内相对路径或同域绝对地址。
8. **短信凭证安全**：短信服务商的 SecretKey 等敏感配置以密文存储，仅平台后台可见，应用内部不得打印日志。
9. **绑定与登录 state 隔离**：绑定授权 state 存 session 键 `cmspro_sso_{platform}_bind_oauth_state`，登录 state 存 `cmspro_sso_{platform}_oauth_state`（PC 扫码存 Cache `cmspro_sso_login_state:`）。两者共用回调地址，回调按 state 校验位置区分模式，禁止混用。
10. **解绑保护**：解绑前须统计可登录方式（密码/手机/微信/QQ），解绑后为 0 时拒绝（防账号锁死）。平台随机密码以 `phone_`/`wx_`/`qq_` 前缀存储，不可登录，不计入统计。

## 八、版本与更新日志

| 版本 | 日期 | 更新人 | 说明 |
|------|------|--------|------|
| 1.8.1 | 2026-09-02|开发团队 |修复 path 模式下 `frontUrl()` 跨子域名跳转：当前请求位于绑定的子域名（如论坛 `bbs.example.com`）时，自动返回主域名下的绝对 URL（基于 `config('app.url')`），避免跳转到子域名下不存在的 `/sso` 路径 |
| 1.0.0 | 2026-08-13 | 开发团队 | 初始版本：统一登录应用，支持账号密码 + 微信登录，双模式访问 |
| 1.1.0 | 2026-08-13 | 开发团队 | 新增「覆盖系统登录」开关，支持回车提交表单，移除前台 home_menus 导航 |
| 1.1.1 | 2026-08-13 | 开发团队 | 修复子域名模式：根路径 `/` 跳转登录页；覆盖系统重定向到子域名 |
| 1.2.1 | 2026-08-25 | 开发团队 | 根路径 `/` 调整为首页：已登录停留首页，未登录跳转登录页 |
| 1.3.0 | 2026-08-25 |开发团队 |新增手机验证码登录：`SmsService` 门面 + 阿里云/腾讯云/火山引擎三个发送器，验证码限频/有效期/一次性失效，未注册手机号自动建号 |
| 1.6.0 | 2026-09-02 |开发团队 |新增 QQ 互联登录：`QqOauthService`（授权 URL 构建 / code 换 openid / 用户信息获取）+ `isQqConfigured()` 配置判断；后台新增「功能选项」（QQ扫码登录 / QQ网页登录，默认网页登录），前端按模式分流 |
| 1.7.0 | 2026-09-02 |开发团队 |新增账号绑定中心：`bindings()` 绑定状态查询、`oauthBindUrl()` 绑定授权链接（state 存 session 绑定专用键 `cmspro_sso_{platform}_bind_oauth_state`）、`bindOauthCallback()` 回调绑定落库（openid 冲突检测）、`bindPhone()` 短信验证码绑定、`unbind()` 解绑保护（统计密码/手机/微信/QQ 可登录方式，仅剩一种拒绝解绑；`userCanPasswordLogin()` 通过密码前缀 `phone_`/`wx_`/`qq_` 识别平台随机密码） |
| 1.8.0 | 2026-09-02 |开发团队 |新增邮箱验证码登录：`SettingService::isEmailLoginEnabled()`（开关 + `mail_configured()` 双重判断）、`sendEmailCode()`（复用系统注册验证码缓存键与邮件模板，RateLimiter 每分钟每邮箱 3 次）、`emailLogin()`（验证码一次性失效，未注册邮箱事务内建号）、`createEmailUser()`（用户名取邮箱 @ 前缀，冲突加随机后缀） |
