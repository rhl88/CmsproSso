# 统一登录 · 使用指南

> 版本：1.1.0 | 更新日期：2026-08-13
> 应用版本：1.0.0 | 应用 ID：`cmspro.sso`

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
| `admin_menus` 表 | 存在「统一登录」菜单（含登录设置子菜单） |
| `admin_permissions` 表 | 存在 `cmspro.sso.manage`、`cmspro.sso.setting` 权限 |
| `config_items` 表 | 存在 `app_cmspro_sso_*` 配置项（11 项） |
| `app_cmspro_sso_login_logs` 表 | 已创建 |

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
| 子域名绑定 | `http://sso.example.com/login` | 需配置 DNS + SESSION_DOMAIN；访问绑定域名根路径 `/` 自动跳转到 `/login` |

验证要点：
- 登录页正常渲染，标题为「统一登录」
- 账号密码登录成功跳转默认地址
- 注册页可访问（开启「允许注册」时）
- 已配置系统邮箱（后台「系统设置 → 邮箱配置」）时，登录页「注册账号」同行右侧显示「找回密码」入口，注册页显示邮箱验证码输入框；未配置邮箱时均不显示
- 找回密码页 `/sso/forgot-password` 两步流程可用：输入邮箱发送验证码 → 凭验证码重置密码后跳转登录页（复用系统 `/api/user/password/*` 接口）
- 注册时邮箱验证码必填，验证码错误或过期拒绝注册，注册成功后验证码一次性失效（复用系统 `/api/user/register/email-code` 接口）
- 微信登录入口显示（开启「启用微信登录」且已配置 AppID/AppSecret 时）
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
| GET | `/api/cmspro/sso/qrcode` | 生成二维码图片（PC 扫码用） |
| GET | `/api/cmspro/sso/user/info` | 获取当前登录用户信息（需登录） |

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
│   └── WechatOauthService.php     # 微信 OAuth2 服务
├── Views/
│   ├── Admin/settings/index.blade.php   # 后台设置页
│   ├── Home/                             # 前台页面
│   └── layouts/home.blade.php            # 前台布局
└── doc/                           # 应用文档
```

## 六、常见问题

| 问题 | 解决方案 |
|------|---------|
| 登录页不显示微信登录入口 | 后台「微信设置」开启「启用微信登录」并填写 AppID/AppSecret |
| 微信登录提示「未配置」 | 检查公众号 AppID/AppSecret 是否填写完整 |
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
