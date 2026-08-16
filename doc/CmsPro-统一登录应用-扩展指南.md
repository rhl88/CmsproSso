# CmsPro 统一登录扩展指南

> 版本：1.0.0 | 更新日期：2026-08-13
> 适用应用：CmsproSso v1.0.0+

---

## 一、概述

统一登录应用为当前系统提供前台用户统一登录入口，支持账号密码登录与微信登录。本指南说明其他应用如何调用本应用的 Service 层、钩子点、辅助函数等扩展接口，以及如何复用其登录能力。

**定位说明**：本应用与「聚合登录」不同，**仅对当前系统使用，不对外开放**。它可挂载和托管系统登录功能，例如关闭系统默认登录，只允许从本应用登录前台账号。

## 二、前置检查

调用本应用扩展接口前，需确认：

1. 应用已安装并启用（`apps` 表 `cmspro.sso` 记录 status=1）
2. 如需微信登录，后台「微信设置」已配置 AppID/AppSecret

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

### 3.2 公开方法

**SettingService**

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `get(string $key, string $default = '')` | 配置键 | string | 读取配置值 |
| `isWechatConfigured()` | - | bool | 微信是否已配置（AppID+AppSecret 非空） |
| `isWechatOauthProxyEnabled()` | - | bool | 是否启用网页授权外挂 |
| `getWechatOauthUrl()` | - | string | 获取外挂地址 |
| `getDefaultRedirect()` | - | string | 获取登录默认跳转地址 |

**ShareService**（静态方法）

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `frontUrl(string $path)` | 前台路径 | string | 生成前台页面 URL（path/domain 双模式） |

**WechatOauthService**

| 方法 | 参数 | 返回值 | 说明 |
|------|------|--------|------|
| `buildOauthRedirectUrl(string $redirectUri, string $state = '')` | 回调地址、state | array | 生成微信授权跳转链接 |
| `getOpenidByOauthCode(string $code)` | 授权 code | array | code 换 openid/unionid/access_token |
| `getUserInfoByToken(string $accessToken, string $openid)` | token、openid | array | 获取昵称/头像 |

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
| platform | string(20) | 登录方式：password/wechat/qq（预留） |
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
6. **开放重定向防护**：登录跳转地址通过 `isSafeRedirect()` 校验，仅允许站内相对路径或同域绝对地址。

## 八、版本与更新日志

| 版本 | 日期 | 更新人 | 说明 |
|------|------|--------|------|
| 1.0.0 | 2026-08-13 | 开发团队 | 初始版本：统一登录应用，支持账号密码 + 微信登录，双模式访问 |
| 1.1.0 | 2026-08-13 | 开发团队 | 新增「覆盖系统登录」开关，支持回车提交表单，移除前台 home_menus 导航 |
| 1.1.1 | 2026-08-13 | 开发团队 | 修复子域名模式：根路径 `/` 跳转登录页；覆盖系统重定向到子域名 |
