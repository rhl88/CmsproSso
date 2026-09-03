# 统一登录 · 使用指南

> 版本：1.5.0 | 更新日期：2026-09-02
> 应用版本：1.8.0 | 应用 ID：`cmspro.sso`

> 本指南帮助开发者快速安装、运行和验证统一登录应用。

---

## 一、环境要求

| 项目 | 要求 |
|------|------|
| PHP | >= 8.3 |
| CmsPro | >= 5.0.0 |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ |
| Web 服务器 | Nginx / Apache（需支持 URL 重写） |
| 扩展依赖 | `endroid/qr-code`（composer 依赖，PC 扫码二维码生成） |

## 二、安装

### 2.1 通过后台应用市场安装

1. 登录后台，进入「应用 → 应用市场」
2. 找到「统一登录」应用，点击安装
3. 安装完成后点击启用

### 2.2 验证安装

安装并启用后，检查以下数据是否自动生成：

| 检查项 | 预期结果 |
|--------|---------|
| `apps` 表 | 存在 `cmspro.sso` 记录，status=1（启用） |
| `admin_menus` 表 | 存在「统一登录」菜单（含邀请码管理、登录设置子菜单） |
| `admin_permissions` 表 | 存在 `cmspro.sso.manage`、`cmspro.sso.setting` 权限 |
| `config_items` 表 | 存在 `app_cmspro_sso_*` 配置项（33 项） |
| `app_cmspro_sso_login_logs` 表 | 已创建 |
| `app_cmspro_sso_invite_codes` 表 | 已创建 |
| `app_cmspro_sso_invite_orders` 表 | 已创建 |

## 三、功能验证

### 3.1 后台管理端

| 页面 | URL | 验证要点 |
|------|-----|---------|
| 登录设置 | `/admin/cmspro/sso/setting` | 三个 Tab（访问设置/微信设置/登录设置）可切换，保存成功提示 |

### 3.2 前端展示端

访问模式由后台「访问设置」控制，支持两种模式：

| 模式 | 登录页 URL | 说明 |
|------|-----------|------|
| 路径前缀（默认） | `/sso/login` | 通过 `/sso/` 前缀访问 |
| 子域名绑定 | `http://sso.example.com/login` | 需配置 DNS + SESSION_DOMAIN；访问绑定域名根路径 `/` 时，已登录停留首页，未登录跳转 `/login` |

验证要点：
- 登录页正常渲染，标题为「统一登录」
- 账号密码登录成功跳转默认地址
- 注册页可访问（开启「允许注册」时）
- 已配置系统邮箱（后台「系统设置 → 邮箱配置」）时，登录页「注册账号」同行右侧显示「找回密码」入口，注册页显示邮箱验证码输入框；未配置邮箱时均不显示
- 找回密码页 `/sso/forgot-password` 两步流程可用：输入邮箱发送验证码 → 凭验证码重置密码后跳转登录页（复用系统 `/api/user/password/*` 接口）
- 注册时邮箱验证码必填，验证码错误或过期拒绝注册，注册成功后验证码一次性失效（复用系统 `/api/user/register/email-code` 接口）
- 微信登录入口显示（开启「启用微信登录」且已配置 AppID/AppSecret 时）
- 手机登录入口显示（开启「手机验证码登录」且短信服务已配置时）
- 子域名模式下访问绑定域名根路径 `/` 跳转到 `/login`

### 3.3 覆盖系统登录

后台「访问设置 → 覆盖系统」开关开启后，系统默认登录页 `/user/login` 与注册页 `/user/register` 将被重定向到本应用统一登录/注册页，使系统各处硬编码的登录入口统一进入 SSO 流程。关闭开关后恢复系统默认登录/注册页。重定向目标随访问模式变化：路径前缀模式跳转到 `/sso/login`、`/sso/register`；子域名模式跳转到绑定域名（如 `http://sso.example.com/login`）。

验证要点：
- 开启后访问 `/user/login` 返回 302 并跳转到本应用登录页（路径前缀模式为 `/sso/login`，子域名模式为 `http://sso.example.com/login`），携带 `redirect` 参数
- 开启后访问 `/user/register` 返回 302 并跳转到本应用注册页
- 关闭后访问 `/user/login`、`/user/register` 正常显示系统默认页面

### 3.4 前端 API

前台 API 基础路径：`/api/cmspro/sso`（不随访问模式变化）

| 方法 | URL | 验证要点 |
|------|-----|---------|
| POST | `/api/cmspro/sso/auth/login` | 账号密码登录，成功返回 code=0 |
| POST | `/api/cmspro/sso/auth/register` | 注册并自动登录 |
| POST | `/api/cmspro/sso/auth/logout` | 退出登录 |
| GET | `/api/cmspro/sso/auth/wechat/login-url` | 获取微信授权 URL（device=pc 为扫码模式） |
| GET | `/api/cmspro/sso/auth/wechat/login-status` | PC 扫码登录轮询 |
| POST | `/api/cmspro/sso/auth/wechat/complete` | 首次微信登录完善资料 |
| GET | `/api/cmspro/sso/auth/qq/login-url` | 获取 QQ 授权 URL（device=pc 为扫码模式；不带 device 为网页授权模式） |
| GET | `/api/cmspro/sso/auth/qq/login-status` | PC 扫码登录轮询 |
| POST | `/api/cmspro/sso/auth/qq/complete` | 首次 QQ 登录完善资料 |
| POST | `/api/cmspro/sso/auth/qq/invite` | QQ 新用户凭邀请码建号 |
| POST | `/api/cmspro/sso/auth/phone/code` | 发送手机验证码短信（限频 60 秒/次） |
| POST | `/api/cmspro/sso/auth/phone/login` | 手机验证码登录（未注册自动创建用户） |
| POST | `/api/cmspro/sso/auth/email/code` | 发送邮箱验证码邮件（限频 3 次/分钟） |
| POST | `/api/cmspro/sso/auth/email/login` | 邮箱验证码登录（未注册自动创建用户） |
| GET | `/api/cmspro/sso/qrcode` | 生成二维码图片（PC 扫码用） |
| GET | `/api/cmspro/sso/user/info` | 获取当前登录用户信息（需登录） |
| GET | `/api/cmspro/sso/user/bindings` | 查询当前用户绑定状态（手机/微信/QQ，需登录） |
| GET | `/api/cmspro/sso/auth/wechat/bind-url` | 获取微信绑定授权 URL（需登录，PC 传 `device=pc` 返回 `display=qrcode` 内嵌扫码） |
| GET | `/api/cmspro/sso/auth/wechat/bind-status` | PC 扫码绑定状态轮询（需登录，参数 `state`） |
| GET | `/api/cmspro/sso/auth/qq/bind-url` | 获取 QQ 绑定授权 URL（需登录，PC 传 `device=pc` 时依后台 `qq_mode` 返回 `display`：qrcode=内嵌扫码，web/默认=redirect 跳转授权页） |
| GET | `/api/cmspro/sso/auth/qq/bind-status` | PC 扫码绑定状态轮询（需登录，参数 `state`） |
| POST | `/api/cmspro/sso/user/bind/phone` | 手机验证码绑定（需登录） |
| POST | `/api/cmspro/sso/user/unbind/{platform}` | 解绑（platform=phone/wechat/qq，需登录） |

### 3.5 手机验证码登录

后台「登录设置 → 手机验证码登录」开关开启后，前台登录页出现「手机登录」按钮，点击弹出验证码登录弹窗。

配置步骤（三选一短信服务商，同一时间仅一家生效）：

| 服务商 | 后台配置项 | 说明 |
|--------|-----------|------|
| 阿里云 | AccessKey ID / AccessKey Secret / 短信签名 / 模板 Code | 模板变量名须为 `code` |
| 腾讯云 | SecretId / SecretKey / SDKAppID / 短信签名 / 模板 ID | 短信应用需开通验证码模板 |
| 火山引擎 | AccessKey ID / Secret Access Key / 消息组 ID / 短信签名 / 模板 ID | 消息组 ID 即 SmsAccount |

验证要点：
- 开启开关但未填写短信凭证时，发送验证码返回「短信服务未配置，请联系管理员」
- 点击「获取验证码」后按钮进入 60 秒倒计时，同一手机号 60 秒内不可重复发送
- 验证码 5 分钟有效，错误或过期返回「验证码不正确或已过期」，验证码一次性失效
- 手机号已注册：验证码校验通过后自动登录；未注册：自动创建用户（用户名取手机号，密码随机不可登录），跳转完善资料页
- 登录日志 `platform` 记录为 `phone`

### 3.6 账号绑定中心

登录后首页点击「账号绑定」按钮，弹窗仅展示后台已开启的快捷登录方式（手机号看「手机登录 → 启用验证码登录」，微信看「微信设置 → 启用微信登录」，QQ 看「QQ设置 → 启用QQ登录」），展示对应绑定状态，支持绑定与解绑。

交互说明：
- 绑定微信/QQ：展示形态由后端 `bind-url` 返回的 `display` 字段统一决策——微信 PC 端固定弹出二维码扫码绑定；QQ PC 端依后台「QQ登录设置」的 `qq_mode` 分流（qrcode=弹二维码扫码，web/默认=跳转 QQ 授权页）；移动端（手机浏览器/微信内）一律跳转对应平台授权页。扫码绑定扫一扫授权完成后自动关闭弹窗并刷新绑定列表；跳转授权完成后自动回跳首页并提示绑定结果；与登录共用回调地址，一个平台仅能绑定一个账号，openid 已被其他账号占用时拒绝绑定
- 绑定手机号：弹窗输入手机号 + 短信验证码（复用登录短信配置，60 秒限频、验证码一次性失效）；目标手机号已被其他账号使用时拒绝
- 解绑保护：解绑后须保留至少一种可登录方式（密码 / 手机号 / 微信 / QQ），仅剩一种时该方式不可解绑（按钮置灰并提示）
- 平台随机密码（手机/社交首次登录自动生成，`phone_`/`wx_`/`qq_` 前缀）不计入可登录方式统计

验证要点：
- 未登录访问绑定相关接口返回 401
- 已绑定平台再次发起绑定授权 URL 返回「已绑定，请先解绑」
- 绑定成功后首页 flash 提示「绑定成功」，绑定列表实时刷新
- 仅剩一种登录方式时点击「解绑」返回「当前仅剩此一种登录方式，不可解绑」

### 3.7 邮箱验证码登录

后台「邮箱验证 → 邮箱验证码登录」开关开启后，前台登录页出现「邮箱」按钮，点击弹出邮箱验证码登录弹窗。

前置条件：
- 系统已配置 SMTP 邮箱（后台「系统设置 → 邮件设置」）；未配置时后台设置页显示黄色警示条，且即使开关开启前台也不显示入口（`mail_configured()` 判断）

验证要点：
- 未配置 SMTP 时开关开启不生效（登录页无「邮箱」按钮，接口返回「邮箱验证码登录未启用」）
- 点击「获取验证码」后按钮进入 60 秒倒计时，同一邮箱每分钟最多发送 3 次，超出返回 429
- 验证码 10 分钟有效（复用系统注册验证码缓存键与邮件模板），错误或过期返回「验证码不正确或已过期」，验证码一次性失效
- 邮箱已注册：验证码校验通过后自动登录；未注册：自动创建用户（用户名取邮箱 @ 前缀，密码随机不可登录），跳转完善资料页
- 邀请注册开启时与手机登录一致：老邮箱用户直接发送验证码登录，新邮箱需凭有效邀请码获取验证码（前端动态展开邀请码输入框）
- 登录日志 `platform` 记录为 `email`

## 四、卸载验证

卸载后检查以下数据是否清理：

| 检查项 | 预期结果 |
|--------|---------|
| `apps` 表 | `cmspro.sso` 记录已删除 |
| `admin_menus` 表 | 「统一登录」菜单已删除 |
| `admin_permissions` 表 | 相关权限已删除 |
| `config_items` / `config_groups` 表 | `app_cmspro_sso_*` 配置已清理 |
| `app_cmspro_sso_login_logs` 表 | 已删除 |

## 五、目录结构

```
app/Apps/CmsproSso/
├── manifest.json                  # 应用声明
├── ServiceProvider.php            # 服务注册（路由/视图/钩子/中间件）
├── Hooks.php                      # 钩子注册（预留）
├── Install.php                    # 安装/卸载/升级
├── icon.svg                       # 应用图标
├── Config/
│   └── sso.php                    # 应用配置默认值
├── Controllers/
│   ├── Admin/
│   │   └── SettingController.php  # 后台设置
│   ├── Home/
│   │   └── AuthController.php     # 前台页面控制器
│   └── HomeApi/
│       ├── AuthController.php     # 前台 API 控制器（登录/微信）
│       └── QrCodeController.php   # 二维码生成
├── Middleware/
│   └── FrontAuthMiddleware.php    # 前台认证中间件
├── Migrations/
│   └── 2026_08_13_000001_create_app_cmspro_sso_login_logs_table.php
├── Models/
│   └── LoginLog.php               # 登录日志模型
├── Routes/
│   ├── admin.php                  # 后台路由
│   ├── home.php                   # 前台页面路由
│   └── home-api.php               # 前台 API 路由
├── Services/
│   ├── SettingService.php         # 配置服务
│   ├── ShareService.php           # 前台 URL 生成
│   ├── WechatOauthService.php     # 微信 OAuth2 服务
│   ├── SmsService.php             # 短信发送门面（按服务商调度）
│   └── Sms/                       # 短信发送器（阿里云/腾讯云/火山引擎）
├── Views/
│   ├── Admin/settings/index.blade.php   # 后台设置页
│   ├── Home/                             # 前台页面
│   └── layouts/home.blade.php            # 前台布局
└── doc/                           # 应用文档
```

## 六、常见问题

| 问题 | 解决方案 |
|------|---------|
| 登录页不显示手机登录入口 | 后台「手机登录」Tab 开启「启用手机验证码登录」 |
| 登录页不显示邮箱登录入口 | 后台「邮箱验证」Tab 开启「邮箱验证码登录」，且系统已配置 SMTP 邮箱 |
| 发送验证码提示「短信服务未配置」 | 检查所选短信服务商的凭证、签名、模板是否填写完整 |
| 收不到验证码 | 确认短信服务商控制台签名/模板已审核通过，模板变量名须为 `code` |
| 子域名模式无法访问 | 配置 DNS 解析、Web 服务器虚拟主机，`.env` 设置 `SESSION_DOMAIN=.example.com` |
| 切换访问模式后不生效 | 执行 `php artisan route:clear && php artisan config:clear` |
| 二维码接口 500 | 确认 `endroid/qr-code` 依赖已安装 |

## 七、版本历史

| 版本 | 日期 | 说明 |
|------|------|------|
| 1.0.0 | 2026-08-13 | 初始版本：统一登录应用，支持账号密码 + 微信登录，双模式访问 |
| 1.1.0 | 2026-08-13 | 新增「覆盖系统登录」开关，支持回车提交表单，移除前台 home_menus 导航 |
| 1.1.1 | 2026-08-13 | 修复子域名模式：根路径 `/` 跳转登录页；ShareService 从数据库读取访问配置，覆盖系统重定向到子域名 |
| 1.1.2 | 2026-08-25 | 新增找回密码页（两步验证流程，复用系统密码重置接口）；注册接口接入邮箱验证码校验，与系统注册逻辑一致，未配置邮箱时自动跳过 |
| 1.2.1 | 2026-08-25 | 根路径 `/` 调整为首页：已登录停留首页，未登录跳转登录页；不再无条件重定向到 `/login` |
| 1.3.0 | 2026-08-25 |新增手机验证码登录：支持阿里云/腾讯云/火山引擎短信服务商，验证码 5 分钟有效、60 秒限频，未注册手机号自动创建用户；后台设置页新增「手机登录」Tab |
| 1.5.3 |2026-08-31 |登录跳转 redirect 全链路透传：登录 URL 携带 `redirect` 时，账号密码/手机验证码/微信扫码/微信内授权登录成功后均跳转到该地址（安全校验：仅允许相对路径与同主域地址，防开放重定向） |
| 1.6.0 |2026-09-02 |新增 QQ 互联登录：后台「QQ登录设置」配置 AppID/AppKey 并启用后，前台登录页显示 QQ 登录入口；「功能选项」支持「QQ扫码登录」（PC 弹二维码，QQ 手机版扫码）与「QQ网页登录（默认）」（跳转 QQ互联 OAuth2.0 认证页授权）;邀请注册开启时新 QQ 用户经 `complete-invite` 页收集邀请码建号 |
| 1.7.0 |2026-09-02 |新增账号绑定中心：登录后首页可查看/绑定/解绑手机号、微信、QQ 三种登录方式；微信/QQ 走授权绑定（state 存 session 绑定专用键，与登录态隔离，openid 冲突检测），手机号走短信验证码绑定；解绑保护确保至少保留一种可登录方式 |
| 1.8.0 |2026-09-02 |新增邮箱验证码登录：后台新增「邮箱验证」Tab（SMTP 未配置时显示警示条），开启后前台登录页显示「邮箱」入口；验证码 10 分钟有效（复用系统注册验证码缓存键与邮件模板）、每分钟每邮箱限发 3 次、一次性失效；未注册邮箱自动创建用户（用户名取 @ 前缀），邀请注册开启时新邮箱凭邀请码建号 |
| 1.9.0 | 2026-09-02 | 绑定中心微信/QQ 支持 PC 扫码绑定：PC 端改为弹出二维码扫码（`bind-url?device=pc` state 存 Cache 绑定键含目标用户、跨设备回调落库，`bind-status` 轮询结果）；移动端/微信内仍走跳转授权 |
| 1.9.1 | 2026-09-03 | 绑定展示形态由后端统一决策：`bind-url` 返回新增 `display` 字段（qrcode=内嵌二维码，redirect=跳转授权），PC 端 QQ 依后台 `qq_mode` 分流（修正绑定中心固定弹二维码、未按 `qq_mode` 分流的问题）；微信 PC 固定扫码、移动端跳转授权不变 |
