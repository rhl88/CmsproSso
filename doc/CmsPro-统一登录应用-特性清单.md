# 统一登录 · 特性清单

> 版本：1.1.0 | 更新日期：2026-08-25
> 应用版本：1.3.0 | 应用 ID：`cmspro.sso`

> 本文档系统整理 CmsPro v5 应用开发框架的全部应用特性，并标注本应用中的对应实现位置，作为应用开发的官方参考。

---

## 一、应用体系架构

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 1 | 目录隔离 | 应用代码统一存放在 `app/Apps/CmsproSso/` 下 | `app/Apps/CmsproSso/` |
| 2 | 命名空间隔离 | 命名空间为 `App\Apps\CmsproSso` | `App\Apps\CmsproSso\*` |
| 3 | 数据库隔离 | 应用表统一以 `app_cmspro_sso_` 为前缀 | `app_cmspro_sso_login_logs` |
| 4 | 视图隔离 | 视图命名空间为 `cmspro.sso::` | `cmspro.sso::Admin.*` / `cmspro.sso::Home.*` |
| 5 | 配置隔离 | 配置项 code 以 `app_cmspro_sso_` 为前缀 | `Config/sso.php` / `config_items` |
| 6 | manifest.json | 应用元数据唯一声明源 | `manifest.json` |

## 二、三终端全覆盖

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 7 | 后台管理端（admin） | 中间件 `['web','auth:admin']`，提供登录设置页 | `Routes/admin.php` / `Controllers/Admin/SettingController.php` |
| 8 | 前端展示端（home） | 中间件 `['web']`，无认证，提供登录/注册/微信回调等页面 | `Routes/home.php` / `Controllers/Home/AuthController.php` |
| 9 | 前端 API（home-api） | 中间件 `['web','throttle:60,1']`，提供登录/注册/微信登录等接口 | `Routes/home-api.php` / `Controllers/HomeApi/AuthController.php` |

> 说明：本应用为统一登录应用，不涉及用户中心端（user）终端。

## 三、路由系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 10 | 后台路由前缀 | `admin/cmspro/sso`，`auth:admin` 保护 | `ServiceProvider::registerRoutes()` |
| 11 | 前台双模式路由 | 根据 `access_mode` 动态注册 path（`Route::prefix`）或 domain（`Route::domain`） | `ServiceProvider::registerHomeRoutes()` |
| 12 | 根路径首页 | 访问根路径 `/` 时，已登录停留首页，未登录重定向到 `/login` | `Routes/home.php` |
| 12.1 | 找回密码页路由 | `/forgot-password` 公开访问，未配置系统邮箱时重定向登录页 | `Routes/home.php` / `Controllers/Home/AuthController.php` |
| 13 | 前台 API 全局前缀 | API 始终为 `api/cmspro/sso`，不随访问模式变化 | `ServiceProvider::registerHomeRoutes()` |
| 14 | 前台认证中间件 | 自定义 `cmspro.sso.auth` 中间件，未登录跳转统一登录页 | `Middleware/FrontAuthMiddleware.php` |
| 15 | 路由命名 | 后台设置页命名 `admin.cmspro.sso.setting` | `Routes/admin.php` |
| 16 | 覆盖系统登录中间件 | 开启「覆盖系统」后，`/user/login`、`/user/register` 重定向到本应用登录/注册页 | `Middleware/OverrideSystemAuthMiddleware.php` |

## 四、数据层

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 15 | 登录日志表 | `app_cmspro_sso_login_logs`，记录登录方式/IP/UA/状态 | `Migrations/2026_08_13_000001_*.php` |
| 16 | 模型 | `LoginLog` 模型，`create_time/update_time` 时间字段 | `Models/LoginLog.php` |
| 17 | 复用系统用户模型 | 使用系统 `App\Models\User`、`UserGroup`、`UserThirdParty` | `Controllers/HomeApi/AuthController.php` |
| 18 | 迁移幂等性 | `up()` 使用 `Schema::hasTable()` 检查，`down()` 使用 `dropIfExists()` | `Migrations/*.php` |
| 19 | 表/字段备注 | 所有字段 `->comment()`，表 `ALTER TABLE ... COMMENT` | `Migrations/*.php` |

## 五、钩子系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 20 | 钩子注册入口 | `Hooks::register()` 预留扩展点 | `Hooks.php` |
| 21 | 自定义钩子点 | 当前预留（未注册具体钩子），供后续扩展登录流程 | `Hooks.php` |

## 六、配置系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 22 | 配置组声明 | `access_settings` / `wechat_settings` / `login_settings` / `phone_settings` 四组 | `manifest.json` |
| 23 | 配置三向同步 | `seedDefaultConfigs()` 以 manifest 为基准同步 | `Install.php` |
| 24 | 配置读取服务 | `SettingService::get()` 从 `config_items` 读取，回退默认值 | `Services/SettingService.php` |
| 25 | 配置白名单 | `ALLOWED_KEYS` 白名单，防止篡改其他配置 | `Services/SettingService.php` |
| 26 | 配置保存 | `SettingController::update()` 白名单接收 + 清缓存 | `Controllers/Admin/SettingController.php` |

## 六-1、短信服务

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 26.1 | 短信门面 | `SmsService` 按 `sms_provider` 调度发送器，`isConfigured()` 校验凭证完整性 | `Services/SmsService.php` |
| 26.2 | 阿里云发送器 | AccessKey 签名 + Dysmsapi 接口，模板变量名 `code` | `Services/Sms/AliyunSmsSender.php` |
| 26.3 | 腾讯云发送器 | SecretId/SecretKey 签名 + Sms 接口（SDKAppID 验证码模板） | `Services/Sms/TencentSmsSender.php` |
| 26.4 | 火山引擎发送器 | AccessKey 签名 + 短信消息组接口（SmsAccount） | `Services/Sms/VolcengineSmsSender.php` |
| 26.5 | 验证码限频 | `RateLimiter` 60 秒/次，5 分钟有效，一次性失效 | `Controllers/HomeApi/AuthController.php` |
| 26.6 | 未注册自动建号 | 手机号不存在时自动创建用户（用户名取手机号，随机密码不可登录） | `Controllers/HomeApi/AuthController.php::createPhoneUser()` |

## 七、权限系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 27 | 权限声明 | `cmspro.sso.manage`（统一登录管理）、`cmspro.sso.setting`（登录设置） | `manifest.json` |

## 八、安装/卸载/升级

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 28 | 迁移兜底 | `runMigrations()` 直接 require 迁移文件执行 | `Install.php` |
| 29 | 卸载回滚兜底 | `rollbackMigrations()` 逆序回滚 + 删除 migrations 记录 | `Install.php` |
| 30 | 配置同步 | `install()` / `upgrade()` 均调用 `seedDefaultConfigs()` | `Install.php` |
| 31 | 卸载清理配置 | `removeConfigs()` 全量清理配置组/配置项 | `Install.php` |

## 九、视图规范

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 32 | 前台独立布局 | `layouts/home.blade.php` 应用自带布局，不依赖系统布局 | `Views/layouts/home.blade.php` |
| 33 | 后台独立 HTML | 设置页为完整 HTML 页面，不继承 `layouts.admin` | `Views/Admin/settings/index.blade.php` |
| 34 | CSRF Token | 前台布局含 `csrf-token` meta，JS 通过 `$.ajaxSetup` 携带 | `Views/layouts/home.blade.php` |
| 35 | 401 处理 | 前台 JS 统一处理登录过期 | `Views/Home/*.blade.php` |
| 36 | 前台 API 路径 | `window.SSO_API_BASE` 全局前缀，不随访问模式变化 | `Views/layouts/home.blade.php` |
| 36.1 | 注册邮箱验证码 | 系统已配置邮箱时注册必填 6 位验证码（复用系统 `/api/user/register/email-code` 接口），验证码一次性失效；未配置邮箱时自动跳过 | `Controllers/HomeApi/AuthController.php` / `Views/Home/auth/register.blade.php` |
| 36.2 | 找回密码两步流程 | 输入邮箱发送验证码 → 凭验证码重置新密码，成功后跳转登录页；登录页「注册账号」同行右侧显示入口，复用系统 `/api/user/password/*` 接口 | `Views/Home/auth/forgot-password.blade.php` / `Views/Home/auth/login.blade.php` |
| 37 | 前台页面路径 | `window.SSO_BASE` 兼容双模式 | `Views/layouts/home.blade.php` |

## 十、菜单系统

| # | 特性 | 说明 | 实现位置 |
|---|------|------|---------|
| 38 | 后台菜单 | `menus` 声明「统一登录 → 登录设置」，含 `code` 字段 | `manifest.json` |
| 39 | 前台路由占用 | `home_routes` 声明 `/sso/*` 路由避免冲突 | `manifest.json` |

## 十一、版本历史

| 版本 | 日期 | 说明 |
|------|------|--------|
| 1.0.0 | 2026-08-13 | 初始版本：统一登录应用，支持账号密码 + 微信登录，双模式访问 |
| 1.1.0 | 2026-08-13 | 新增「覆盖系统登录」开关，支持回车提交表单，移除前台 home_menus 导航 |
| 1.1.1 | 2026-08-13 | 修复子域名模式：根路径 `/` 跳转登录页；ShareService 从数据库读取访问配置，覆盖系统重定向到子域名 |
| 1.1.2 | 2026-08-25 | 新增找回密码页（两步验证流程，复用系统密码重置接口）；注册接口接入邮箱验证码校验，与系统注册逻辑一致，未配置邮箱时自动跳过 |
| 1.2.1 | 2026-08-25 | 根路径 `/` 调整为首页：已登录停留首页，未登录跳转登录页 |
| 1.3.0 | 2026-08-25 | 新增手机验证码登录：短信门面 + 阿里云/腾讯云/火山引擎发送器，验证码限频 60 秒、5 分钟有效、一次性失效，未注册手机号自动建号 |
