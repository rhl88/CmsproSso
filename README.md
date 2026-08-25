# 统一登录应用 应用文档

> 应用ID：`cmspro.sso` | 文档版本：1.3.0 | 应用版本：1.3.0 | 依赖：PHP ≥ 8.3, CmsPro ≥ 5.0.0

- 官方地址：https://www.cmspro.cn/apps/cmspro.sso
- 本应用基于 CMSPRO 运行，请先安装 [CMSPRO](https://www.cmspro.cn/help/2)

## 1. 应用概述

为当前系统提供前台用户统一登录入口，使系统各处登录/注册入口统一进入同一认证流程：

- **登录方式**：账号密码登录（用户名/邮箱/手机号）+ 微信扫码登录（PC）/ 微信内授权登录（移动端）+ 手机验证码登录（阿里云/腾讯云/火山引擎短信）
- **注册**：用户名 + 邮箱注册；系统已配置邮箱时强制 6 位邮箱验证码（复用系统验证码接口），未配置时自动跳过
- **找回密码**：两步验证流程（邮箱验证码 → 重置密码），系统已配置邮箱时在登录页提供入口
- **访问模式**：路径前缀（`/sso/`）或子域名绑定（如 `sso.example.com`）
- **覆盖系统登录**：可将系统默认 `/user/login`、`/user/register` 重定向到本应用统一页面
- **登录日志**：`app_cmspro_sso_login_logs` 记录登录方式/IP/UA/状态

## 2. 目录结构

```
CmsproSso/
├── Config/sso.php                   # 应用配置默认值
├── Routes/
│   ├── admin.php                    # 后台路由（登录设置页）
│   ├── home.php                     # 前台页面路由（登录/注册/找回密码/微信回调）
│   └── home-api.php                 # 前台 API 路由（登录/注册/微信登录）
├── Controllers/
│   ├── Admin/SettingController.php  # 后台设置控制器
│   ├── Home/AuthController.php      # 前台页面控制器
│   └── HomeApi/
│       ├── AuthController.php       # 前台 API 控制器（登录/注册/微信）
│       └── QrCodeController.php     # 二维码生成
├── Middleware/
│   ├── FrontAuthMiddleware.php      # 前台认证中间件
│   └── OverrideSystemAuthMiddleware.php  # 覆盖系统登录中间件
├── Migrations/                      # 应用表迁移（app_cmspro_sso_ 前缀）
├── Models/LoginLog.php              # 登录日志模型
├── Services/
│   ├── SettingService.php           # 配置服务
│   ├── ShareService.php             # 前台 URL 生成
│   ├── WechatOauthService.php       # 微信 OAuth2 服务
│   ├── SmsService.php               # 短信发送门面
│   └── Sms/                         # 短信发送器（阿里云/腾讯云/火山引擎）
├── Views/
│   ├── Admin/settings/index.blade.php    # 后台设置页
│   ├── Home/                              # 前台页面（登录/注册/找回密码/微信）
│   └── layouts/home.blade.php             # 前台布局
├── Hooks.php                        # 钩子注册（预留）
├── Install.php                      # 安装/卸载/升级逻辑
├── manifest.json                    # 应用元数据唯一声明源
└── doc/                             # 应用文档
```

## 3. 应用文档

| 文档 | 说明 |
|------|------|
| [CmsPro-统一登录应用-特性清单.md](doc/CmsPro-统一登录应用-特性清单.md) | 应用全部特性与对应实现位置 |
| [CmsPro-统一登录应用-使用指南.md](doc/CmsPro-统一登录应用-使用指南.md) | 安装、配置、功能验证指南 |
| [CmsPro-统一登录应用-扩展指南.md](doc/CmsPro-统一登录应用-扩展指南.md) | 应用扩展点与二次开发指南 |
