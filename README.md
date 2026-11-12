# 统一登录应用 应用文档

> 应用ID：`cmspro.sso` | 文档版本：2.0.20 | 应用版本：2.8.0 | 依赖：PHP ≥ 8.3, CmsPro ≥ 5.0.0

- 官方地址：<https://www.cmspro.cn/apps/cmspro.sso>

- 本应用基于 CMSPRO 运行，请先安装 [CMSPRO](https://www.cmspro.cn/help/2)

## 1. 应用概述

为当前系统提供前台用户统一登录入口，使系统各处登录/注册入口统一进入同一认证流程：

- **登录方式**：账号密码登录（用户名/邮箱/手机号）+ 微信扫码登录（PC）/ 微信内授权登录（移动端）+ QQ 互联登录（扫码/网页）+ 手机验证码登录 + 邮箱验证码登录

- **发信与短信通道**：邮箱验证码可选「系统邮件」或复用「SMTP邮箱」应用（该应用开启「接管系统邮件」时强制走该应用）；手机验证码可选内置服务商（阿里云/腾讯云/火山引擎）或复用「短信发送」应用，按模板标识调用其发送服务，本应用不重复保存服务商凭证；通道对应应用未安装/未启用时选项置灰并提供安装与启用引导

- **注册**：用户名 + 邮箱注册；系统已配置邮箱时强制 6 位邮箱验证码（复用系统验证码接口），未配置时自动跳过；支持邀请注册（邀请码管理、在线购买发放）

- **社交新用户分流**：社交登录（微信/QQ/支付宝/抖音/微博/GitHub）回调发现新用户时不再自动建号，授权数据暂存后跳分流页，由用户选择「完善资料（创建账号）」或「账号绑定（登录已有账号）」后完成登录；PC 扫码登录新用户同样跳分流页

- **找回密码**：两步验证流程（邮箱验证码 → 重置密码），系统已配置邮箱时在登录页提供入口

- **账号绑定中心**：登录后可查看/绑定/解绑手机号、微信、QQ，解绑保护确保至少保留一种可登录方式

- **快捷模式（社交登录中转）**：站点角色三选一——独立站（默认）/ 服务端（纯跳板中转：不建号、不绑定、不保存任何用户数据，将社交平台真实 openid 等授权数据透传给客户端站点，含客户端管理）/ 客户端（本地社交配置失效，社交登录委托服务端中转，以社交真实 openid 本地建号登录）

- **访问模式**：路径前缀（`/sso/`）或子域名绑定（如 `sso.example.com`）

- **覆盖系统登录**：可将系统默认 `/user/login`、`/user/register` 重定向到本应用统一页面

- **登录日志**：`app_cmspro_sso_login_logs` 记录登录方式/IP/UA/状态

## 2. 应用截图

> 截图来源：[官方应用页 · cmspro.sso](https://www.cmspro.cn/apps/cmspro.sso)（官网标注 v1.5.1）。每次发布与更新必须核对本节截图与官网「应用截图」是否一致，不一致时以官网为准同步更新。

![应用截图 1](https://www.cmspro.cn/uploads/2026/09/08/OFPTzqwtvMXRBQTOM185fFyiREnNprbZT6OvD4I0.png)

![应用截图 2](https://www.cmspro.cn/uploads/2026/09/01/Kdo6Nsl4hkDaRiCyR9TpzwpHwINVsCCsmKH7DxMO.png)

![应用截图 3](https://www.cmspro.cn/uploads/2026/09/01/LV59dD72KPTmefu2y6lTkeW3NQMIdogV6QNzQH8l.png)

![应用截图 4](https://www.cmspro.cn/uploads/2026/09/01/XwlhoTAB6nn7ZKWWEsuHcljp11CkCgahFplw4RO7.png)

![应用截图 5](https://www.cmspro.cn/uploads/2026/09/01/uTyAZoTsKBQg8DChKk4Ki6pjMLjkLVYJKA4QMhcw.png)

![应用截图 6](https://www.cmspro.cn/uploads/2026/09/01/TTkyyCvuZ2mVsZ45M2ZQLEbSqS5o11zxEFMrtJ6g.png)

![应用截图 7](https://www.cmspro.cn/uploads/2026/09/01/KMQ6KDae8DNvIifgmmAttwuQ4rX8pPSmgXp4umO6.png)

![应用截图 8](https://www.cmspro.cn/uploads/2026/09/01/Wwqwcd9VHGOW66Zpfgey7RVVaBS1FswlFNEv5zVe.png)

## 3. 目录结构

```
CmsproSso/
├── Config/sso.php                   # 应用配置默认值
├── Routes/
│   ├── admin.php                    # 后台路由（设置/邀请码/客户端管理）
│   ├── home.php                     # 前台页面路由（登录/注册/找回密码/社交回调/OAuth授权端点）
│   ├── home-api.php                 # 前台 API 路由（登录/注册/社交登录/绑定中心/中转）
│   └── oauth-api.php                # 快捷模式开放 API 路由（platforms/token/userinfo/revoke）
├── Controllers/
│   ├── Admin/                       # 后台控制器（设置/邀请码/客户端管理）
│   ├── Home/                        # 前台页面控制器（含快捷模式服务端授权端点）
│   └── HomeApi/                     # 前台 API 控制器（含快捷模式开放 API 与中转登录）
├── Middleware/
│   ├── FrontAuthMiddleware.php      # 前台认证中间件
│   └── OverrideSystemAuthMiddleware.php  # 覆盖系统登录中间件
├── Migrations/                      # 应用表迁移（app_cmspro_sso_ 前缀：日志/邀请码/订单/客户端/授权码/令牌）
├── Models/                          # LoginLog/InviteCode/InviteOrder/Client/AuthCode/AccessToken
├── Services/
│   ├── SettingService.php           # 配置服务
│   ├── ShareService.php             # 前台 URL 生成
│   ├── WechatOauthService.php       # 微信 OAuth2 服务
│   ├── QqOauthService.php           # QQ 互联 OAuth2 服务
│   ├── SmsService.php               # 短信发送门面
│   ├── Sms/                         # 短信发送器（阿里云/腾讯云/火山引擎/「短信发送」应用通道）
│   ├── OAuthService.php             # 快捷模式服务端 OAuth 服务
│   └── RelayClientService.php       # 快捷模式客户端中转服务
├── Tests/                           # 应用级 Feature 测试
├── Views/
│   ├── Admin/                       # 设置页/邀请码管理/客户端管理
│   ├── Home/                        # 前台页面（登录/注册/找回密码/完善资料/邀请码购买等）
│   └── layouts/home.blade.php       # 前台布局
├── Hooks.php                        # 钩子注册（预留）
├── Install.php                      # 安装/卸载/升级逻辑
├── manifest.json                    # 应用元数据唯一声明源
└── doc/                             # 应用文档
```

## 4. 应用文档

| 文档                                                 | 说明            |
| -------------------------------------------------- | ------------- |
| [CmsPro-统一登录应用-特性清单.md](doc/CmsPro-统一登录应用-特性清单.md) | 应用全部特性与对应实现位置 |
| [CmsPro-统一登录应用-使用指南.md](doc/CmsPro-统一登录应用-使用指南.md) | 安装、配置、功能验证指南  |
| [CmsPro-统一登录应用-扩展指南.md](doc/CmsPro-统一登录应用-扩展指南.md) | 应用扩展点与二次开发指南  |

<br />
