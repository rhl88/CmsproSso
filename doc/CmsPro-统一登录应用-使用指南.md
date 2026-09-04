# 统一登录 · 使用指南

> 版本：1.5.0 | 更新日期：2026-09-02
> 应用版本：1.8.0 | 应用 ID：`cmspro.sso`

> 本指南帮助开发者快速安装、运行和验证统一登录应用。

***

## 一、环境要求

| 项目      | 要求                                        |
| ------- | ----------------------------------------- |
| PHP     | >= 8.3                                    |
| CmsPro  | >= 5.0.0                                  |
| 数据库     | MySQL 5.7+ / MariaDB 10.3+                |
| Web 服务器 | Nginx / Apache（需支持 URL 重写）                |
| 扩展依赖    | `endroid/qr-code`（composer 依赖，PC 扫码二维码生成） |

## 二、安装

### 2.1 通过后台应用市场安装

1. 登录后台，进入「应用 → 应用市场」
2. 找到「统一登录」应用，点击安装
3. 安装完成后点击启用

### 2.2 验证安装

安装并启用后，检查以下数据是否自动生成：

| 检查项                              | 预期结果                                           |
| -------------------------------- | ---------------------------------------------- |
| `apps` 表                         | 存在 `cmspro.sso` 记录，status=1（启用）                |
| `admin_menus` 表                  | 存在「统一登录」菜单（含邀请码管理、登录设置、客户端管理子菜单）               |
| `admin_permissions` 表            | 存在 `cmspro.sso.manage`、`cmspro.sso.setting` 权限 |
| `config_items` 表                 | 存在 `app_cmspro_sso_*` 配置项（42 项，8 组）            |
| `app_cmspro_sso_login_logs` 表    | 已创建                                            |
| `app_cmspro_sso_invite_codes` 表  | 已创建                                            |
| `app_cmspro_sso_invite_orders` 表 | 已创建                                            |
| `app_cmspro_sso_clients` 表       | 已创建（快捷模式客户端）                                   |
| `app_cmspro_sso_auth_codes` 表    | 已创建（快捷模式授权码）                                   |
| `app_cmspro_sso_access_tokens` 表 | 已创建（快捷模式访问令牌）                                  |

## 三、功能验证

### 3.1 后台管理端

| 页面   | URL                         | 验证要点                                                                     |
| ---- | --------------------------- | ------------------------------------------------------------------------ |
| 登录设置 | `/admin/cmspro/sso/setting` | 六个 Tab（访问设置/邀请注册/邮箱验证/微信设置/QQ设置/手机登录）可切换，保存成功提示；站点角色（快捷模式）位于「访问设置」Tab 顶部 |

### 3.2 前端展示端

访问模式由后台「访问设置」控制，支持两种模式：

| 模式       | 登录页 URL                        | 说明                                                               |
| -------- | ------------------------------ | ---------------------------------------------------------------- |
| 路径前缀（默认） | `/sso/login`                   | 通过 `/sso/` 前缀访问                                                  |
| 子域名绑定    | `http://sso.example.com/login` | 需配置 DNS + SESSION\_DOMAIN；访问绑定域名根路径 `/` 时，已登录停留首页，未登录跳转 `/login` |

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

| 方法   | URL                                        | 验证要点                                                                                                                           |
| ---- | ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------ |
| POST | `/api/cmspro/sso/auth/login`               | 账号密码登录，成功返回 code=0                                                                                                             |
| POST | `/api/cmspro/sso/auth/register`            | 注册并自动登录                                                                                                                        |
| POST | `/api/cmspro/sso/auth/logout`              | 退出登录                                                                                                                           |
| GET  | `/api/cmspro/sso/auth/wechat/login-url`    | 获取微信授权 URL（device=pc 为扫码模式）                                                                                                    |
| GET  | `/api/cmspro/sso/auth/wechat/login-status` | PC 扫码登录轮询                                                                                                                      |
| POST | `/api/cmspro/sso/auth/wechat/complete`     | 首次微信登录完善资料                                                                                                                     |
| GET  | `/api/cmspro/sso/auth/qq/login-url`        | 获取 QQ 授权 URL（device=pc 为扫码模式；不带 device 为网页授权模式）                                                                                |
| GET  | `/api/cmspro/sso/auth/qq/login-status`     | PC 扫码登录轮询                                                                                                                      |
| POST | `/api/cmspro/sso/auth/qq/complete`         | 首次 QQ 登录完善资料                                                                                                                   |
| POST | `/api/cmspro/sso/auth/qq/invite`           | QQ 新用户凭邀请码建号                                                                                                                   |
| POST | `/api/cmspro/sso/auth/phone/code`          | 发送手机验证码短信（限频 60 秒/次）                                                                                                           |
| POST | `/api/cmspro/sso/auth/phone/login`         | 手机验证码登录（未注册自动创建用户）                                                                                                             |
| POST | `/api/cmspro/sso/auth/email/code`          | 发送邮箱验证码邮件（限频 3 次/分钟）                                                                                                           |
| POST | `/api/cmspro/sso/auth/email/login`         | 邮箱验证码登录（未注册自动创建用户）                                                                                                             |
| GET  | `/api/cmspro/sso/qrcode`                   | 生成二维码图片（PC 扫码用）                                                                                                                |
| GET  | `/api/cmspro/sso/user/info`                | 获取当前登录用户信息（需登录）                                                                                                                |
| GET  | `/api/cmspro/sso/user/bindings`            | 查询当前用户绑定状态（手机/微信/QQ，需登录）                                                                                                       |
| GET  | `/api/cmspro/sso/auth/wechat/bind-url`     | 获取微信绑定授权 URL（需登录，PC 传 `device=pc` 返回 `display=qrcode` 内嵌扫码）                                                                    |
| GET  | `/api/cmspro/sso/auth/wechat/bind-status`  | PC 扫码绑定状态轮询（需登录，参数 `state`）                                                                                                    |
| GET  | `/api/cmspro/sso/auth/qq/bind-url`         | 获取 QQ 绑定授权 URL（需登录，PC 传 `device=pc` 时依后台 `qq_mode` 返回 `display`：qrcode=内嵌扫码，web/默认=redirect 跳转授权页）                             |
| GET  | `/api/cmspro/sso/auth/qq/bind-status`      | PC 扫码绑定状态轮询（需登录，参数 `state`）                                                                                                    |
| POST | `/api/cmspro/sso/user/bind/phone`          | 手机验证码绑定（需登录）                                                                                                                   |
| POST | `/api/cmspro/sso/user/unbind/{platform}`   | 解绑（platform=phone/wechat/qq，需登录）                                                                                               |
| GET  | `/api/cmspro/sso/auth/relay/login-url`     | 获取快捷模式中转授权跳转地址（客户端模式，state 存 session）；PC 端传 `device=pc` 返回 `mode=relay_qrcode`（扫码会话：session\_id/qrcode\_url/expires\_in，不整页跳转） |
| GET  | `/api/cmspro/sso/auth/relay/login-status`  | PC 扫码中转轮询（客户端模式，参数 session\_id；confirmed 后客户端换令牌建登录态，新用户返回分流页地址）                                                               |
| POST | `/api/cmspro/sso/auth/relay/invite`        | 快捷模式新用户凭邀请码建号                                                                                                                  |
| POST | `/api/cmspro/sso/auth/relay/complete`      | 快捷模式首次登录完善资料（新建账号）                                                                                                             |
| POST | `/api/cmspro/sso/auth/relay/setup`         | 快捷模式新用户分流：完善资料建号（参数：token/nickname/phone，token 一次性消费）                                                                          |
| POST | `/api/cmspro/sso/auth/relay/bind`          | 快捷模式新用户分流：绑定已有本地账号（参数：token/account/password，密码错误计数锁定，openid 冲突拒绝）                                                             |

### 3.5 快捷模式开放 API（服务端）

服务端开放 API 基础路径：`/api/cmspro/sso/oauth`（跨站服务器间调用，无 session/CSRF，限流 120 次/分钟；仅 `quick_mode=server` 时可用）

| 方法   | URL                                          | 说明                                                                                                                                                        |
| ---- | -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET  | `/api/cmspro/sso/oauth/platforms?client_id=` | 查询可中转的社交平台列表（仅返回已启用且凭证已配置的平台）                                                                                                                             |
| POST | `/api/cmspro/sso/oauth/token`                | 授权码换取访问令牌（参数：grant\_type=authorization\_code、client\_id、client\_secret、code、redirect\_uri）                                                                |
| GET  | `/api/cmspro/sso/oauth/userinfo`             | 凭 `Authorization: Bearer {token}` 获取社交授权信息（透传社交平台真实数据：openid/unionid/nickname/avatar/access\_token/refresh\_token/platform/raw\_data；服务端纯跳板不落库，暂存数据一次性取回） |
| POST | `/api/cmspro/sso/oauth/revoke`               | 吊销访问令牌（参数：token）                                                                                                                                          |
| POST | `/api/cmspro/sso/oauth/relay-qrcode`         | 创建中转扫码会话（参数：client\_id、redirect\_uri、platform），返回服务端社交平台授权 URL（客户端渲染为二维码，手机扫码直达微信/QQ 授权确认页）与会话 ID，10 分钟有效                                                 |
| GET  | `/api/cmspro/sso/oauth/relay-status`         | 轮询中转扫码结果（参数：session\_id）；waiting=未扫码/未确认，confirmed=已确认（携带授权码 code 与 platform），failed=授权失败（携带 error）                                                       |

### 3.5 手机验证码登录

后台「登录设置 → 手机验证码登录」开关开启后，前台登录页出现「手机登录」按钮，点击弹出验证码登录弹窗。

配置步骤（三选一短信服务商，同一时间仅一家生效）：

| 服务商  | 后台配置项                                                    | 说明                  |
| ---- | -------------------------------------------------------- | ------------------- |
| 阿里云  | AccessKey ID / AccessKey Secret / 短信签名 / 模板 Code         | 模板变量名须为 `code`      |
| 腾讯云  | SecretId / SecretKey / SDKAppID / 短信签名 / 模板 ID           | 短信应用需开通验证码模板        |
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

### 3.8 邮箱验证码登录

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

### 3.9 快捷模式（社交登录中转）

后台「登录设置 → 访问设置」Tab顶部选择站点角色，实现站点间社交登录中转：

| 角色      | 说明                                                                                                                     |
| ------- | ---------------------------------------------------------------------------------------------------------------------- |
| 独立站（默认） | 社交登录使用本站自身配置，与其他应用无交互                                                                                                  |
| 服务端     | 本站作为**纯跳板中转**，只提供社交授权中转服务：不建号、不绑定、不保存任何用户数据，将社交平台真实 openid 等授权数据透传给客户端站点；通过「客户端管理」发放凭证，前台新增授权端点 `/sso/oauth/authorize` |
| 客户端     | 本地社交配置强制失效（微信/QQ 开关与凭证均不生效），登录页社交入口改为服务端可中转平台，社交登录委托服务端中转，以社交平台真实 openid 为身份锚点本地建号登录                                   |

**菜单联动**：保存站点角色后左侧菜单自动刷新，「客户端管理」菜单默认隐藏，仅站点角色为「服务端」时可见（安装后与切换角色时自动同步）。

**社交登录配置联动**：站点角色为「客户端」时，微信/QQ 设置 Tab 仅保留启用开关，本地凭证配置（AppID/AppSecret/AppKey/网页授权外挂等）自动隐藏，保存时不再校验本地凭证——客户端社交登录统一走服务端中转。

**服务端配置步骤**：站点角色选「服务端」→「登录设置」开启微信/QQ 并配置凭证 →「客户端管理」新建客户端（填写名称与回调地址白名单）→ 将 client\_id / client\_secret 发放给客户端站点。

**客户端配置步骤**：站点角色选「客户端」→ 填写服务端地址（子域名模式填域名如 `https://sso.example.com`，路径前缀模式填含前缀地址如 `http://www.example.com/sso`）→ 填写服务端发放的 client\_id / client\_secret。

**流程说明**：客户端用户点击社交入口 → 调用 `/api/cmspro/sso/auth/relay/login-url`（state 存 session）→ 跳转服务端 `/sso/oauth/authorize` → 服务端无论是否已登录均代跳社交平台授权页（不静默签发授权码）→ 用户在社交平台完成授权 → 社交平台回调服务端 → 服务端**纯透传**（不建号、不绑定、不落任何用户数据，仅将社交授权数据暂存 Cache 并签发不关联服务端用户的授权码）回跳客户端 `/sso/auth/relay/callback` → 客户端以 code 换 access\_token（服务器间调用，1 小时有效）→ 凭令牌取社交授权信息（社交平台真实 openid/unionid/昵称/头像/令牌及原始数据）→ 以「社交平台真实 openid」为身份锚点查找/创建本地账号并登录。同一社交账号无论经哪个服务端中转，在客户端始终对应同一本地账号。

**PC 扫码中转流程**（客户端 PC 端，微信/QQ PC 登录不再整页跳服务端授权页）：PC 端调 `login-url?device=pc` → 客户端服务器间调服务端 `relay-qrcode` 创建扫码会话 → 返回服务端社交平台授权 URL（微信 `open.weixin.qq.com/connect/oauth2/authorize` 或 QQ `graph.qq.com/oauth2.0/authorize`，state=relay\_qr:{session\_id}），客户端渲染为二维码弹窗 → 用户手机扫码直达微信/QQ 授权确认页（无需先登录服务端）→ 授权后社交平台回调服务端 `/auth/{platform}/callback` → 服务端识别 relay\_qr state 后**纯透传**（不建号不绑定，社交授权数据暂存 Cache 凭 data\_token 关联，签发 user\_id 为 null 的授权码写入扫码会话），手机端展示确认成功提示页 `/sso/auth/relay/pc-success` → PC 端每 2 秒轮询 `/api/cmspro/sso/auth/relay/login-status` → confirmed 时客户端凭授权码走既有 token 换取流程，取回社交授权信息后以社交真实 openid 建号登录（老用户直接登录、新用户跳完善资料/账号绑定分流页）；扫码会话 10 分钟有效，授权码一次性消费。

**透传平台无关性**：服务端中转链路（授权端点 → 社交回调透传 → token/userinfo 开放 API）对平台完全无感知，未来新增第三方登录（如支付宝、GitHub 等）只要在服务端接入对应社交回调（`/auth/{platform}/callback`），客户端中转即自动支持，服务端与客户端中转逻辑均无需改动。

验证要点：

- 非对应模式下访问 `/sso/oauth/authorize`、`/sso/auth/relay/callback` 均返回 404 页

- 客户端模式登录页社交入口展示服务端可中转平台（服务端未启用或凭证未配置的平台不展示），点击走中转流程

- 服务端 authorize 校验 client\_id 存在且启用、回调地址与白名单精确一致、response\_type=code、platform 已启用，任一不满足返回 400 且不跳转（防开放重定向）

- 授权码一次性使用，重放/过期/凭证错误/回调地址不一致均被拒绝

- 服务端禁用或删除客户端后，客户端站点的中转登录立即失效

- 邀请注册开启时，客户端模式新用户先跳邀请码填写页，与其他社交平台一致

- 客户端登录日志 `platform` 记录为 `relay`

- PC 扫码中转：relay-qrcode 校验 platform 有效且服务端已启用，无效平台返回 400；手机扫码社交授权后会话无效/过期或回调地址与会话登记不一致时，跳 pc-success?result=failed 提示页且不写会话结果；PC 端轮询返回 waiting/confirmed/failed 三态，confirmed 结果一次性消费

## 四、卸载验证

卸载后检查以下数据是否清理：

| 检查项                                | 预期结果                     |
| ---------------------------------- | ------------------------ |
| `apps` 表                           | `cmspro.sso` 记录已删除       |
| `admin_menus` 表                    | 「统一登录」菜单已删除              |
| `admin_permissions` 表              | 相关权限已删除                  |
| `config_items` / `config_groups` 表 | `app_cmspro_sso_*` 配置已清理 |
| `app_cmspro_sso_login_logs` 表      | 已删除                      |

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
│   │   ├── SettingController.php  # 后台设置
│   │   ├── InviteCodeController.php # 邀请码管理
│   │   └── ClientController.php   # 快捷模式客户端管理
│   ├── Home/
│   │   ├── AuthController.php     # 前台页面控制器
│   │   └── OAuthController.php    # 快捷模式服务端授权端点
│   └── HomeApi/
│       ├── AuthController.php     # 前台 API 控制器（登录/微信/QQ/中转）
│       ├── OAuthController.php    # 快捷模式开放 API 控制器
│       └── QrCodeController.php   # 二维码生成
├── Middleware/
│   ├── FrontAuthMiddleware.php    # 前台认证中间件
│   └── OverrideSystemAuthMiddleware.php # 覆盖系统登录中间件
├── Migrations/                    # 迁移（登录日志/邀请码/购买订单/客户端/授权码/访问令牌）
├── Models/
│   ├── LoginLog.php               # 登录日志模型
│   ├── InviteCode.php             # 邀请码模型
│   ├── InviteOrder.php            # 邀请码购买订单模型
│   ├── Client.php                 # 快捷模式客户端模型
│   ├── AuthCode.php               # 快捷模式授权码模型
│   └── AccessToken.php            # 快捷模式访问令牌模型
├── Routes/
│   ├── admin.php                  # 后台路由
│   ├── home.php                   # 前台页面路由
│   ├── home-api.php               # 前台 API 路由
│   └── oauth-api.php              # 快捷模式开放 API 路由（服务端）
├── Services/
│   ├── SettingService.php         # 配置服务
│   ├── ShareService.php           # 前台 URL 生成
│   ├── WechatOauthService.php     # 微信 OAuth2 服务
│   ├── QqOauthService.php         # QQ 互联 OAuth2 服务
│   ├── SmsService.php             # 短信发送门面（按服务商调度）
│   ├── Sms/                       # 短信发送器（阿里云/腾讯云/火山引擎）
│   ├── OAuthService.php           # 快捷模式服务端 OAuth 服务
│   └── RelayClientService.php     # 快捷模式客户端中转服务
├── Tests/                         # 应用级测试（Feature）
│   ├── SsoPcBindTest.php
│   └── SsoOAuthRelayTest.php      # 快捷模式全流程测试（21 用例）
├── Views/
│   ├── Admin/settings/index.blade.php   # 后台设置页
│   ├── Admin/clients/index.blade.php    # 后台客户端管理页
│   ├── Admin/invite/index.blade.php     # 后台邀请码管理页
│   ├── Home/                             # 前台页面
│   └── layouts/home.blade.php            # 前台布局
└── doc/                           # 应用文档
```

## 六、常见问题

| 问题               | 解决方案                                                          |
| ---------------- | ------------------------------------------------------------- |
| 登录页不显示手机登录入口     | 后台「手机登录」Tab 开启「启用手机验证码登录」                                     |
| 登录页不显示邮箱登录入口     | 后台「邮箱验证」Tab 开启「邮箱验证码登录」，且系统已配置 SMTP 邮箱                        |
| 发送验证码提示「短信服务未配置」 | 检查所选短信服务商的凭证、签名、模板是否填写完整                                      |
| 收不到验证码           | 确认短信服务商控制台签名/模板已审核通过，模板变量名须为 `code`                           |
| 子域名模式无法访问        | 配置 DNS 解析、Web 服务器虚拟主机，`.env` 设置 `SESSION_DOMAIN=.example.com` |
| 切换访问模式后不生效       | 执行 `php artisan route:clear && php artisan config:clear`      |
| 二维码接口 500        | 确认 `endroid/qr-code` 依赖已安装                                    |

## 七、版本历史

| 版本     | 日期         | 说明                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | <br /> |
| ------ | ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | :----- |
| 1.0.0  | 2026-08-13 | 初始版本：统一登录应用，支持账号密码 + 微信登录，双模式访问                                                                                                                                                                                                                                                                                                                                                                                                                                              | <br /> |
| 1.1.0  | 2026-08-13 | 新增「覆盖系统登录」开关，支持回车提交表单，移除前台 home\_menus 导航                                                                                                                                                                                                                                                                                                                                                                                                                                    | <br /> |
| 1.1.1  | 2026-08-13 | 修复子域名模式：根路径 `/` 跳转登录页；ShareService 从数据库读取访问配置，覆盖系统重定向到子域名                                                                                                                                                                                                                                                                                                                                                                                                                    | <br /> |
| 1.1.2  | 2026-08-25 | 新增找回密码页（两步验证流程，复用系统密码重置接口）；注册接口接入邮箱验证码校验，与系统注册逻辑一致，未配置邮箱时自动跳过                                                                                                                                                                                                                                                                                                                                                                                                                | <br /> |
| 1.2.1  | 2026-08-25 | 根路径 `/` 调整为首页：已登录停留首页，未登录跳转登录页；不再无条件重定向到 `/login`                                                                                                                                                                                                                                                                                                                                                                                                                            | <br /> |
| 1.3.0  | 2026-08-25 | 新增手机验证码登录：支持阿里云/腾讯云/火山引擎短信服务商，验证码 5 分钟有效、60 秒限频，未注册手机号自动创建用户；后台设置页新增「手机登录」Tab                                                                                                                                                                                                                                                                                                                                                                                                | <br /> |
| 1.5.3  | 2026-08-31 | 登录跳转 redirect 全链路透传：登录 URL 携带 `redirect` 时，账号密码/手机验证码/微信扫码/微信内授权登录成功后均跳转到该地址（安全校验：仅允许相对路径与同主域地址，防开放重定向）                                                                                                                                                                                                                                                                                                                                                                      | <br /> |
| 1.6.0  | 2026-09-02 | 新增 QQ 互联登录：后台「QQ登录设置」配置 AppID/AppKey 并启用后，前台登录页显示 QQ 登录入口；「功能选项」支持「QQ扫码登录」（PC 弹二维码，QQ 手机版扫码）与「QQ网页登录（默认）」（跳转 QQ互联 OAuth2.0 认证页授权）;邀请注册开启时新 QQ 用户经 `complete-invite` 页收集邀请码建号                                                                                                                                                                                                                                                                                                 | <br /> |
| 1.7.0  | 2026-09-02 | 新增账号绑定中心：登录后首页可查看/绑定/解绑手机号、微信、QQ 三种登录方式；微信/QQ 走授权绑定（state 存 session 绑定专用键，与登录态隔离，openid 冲突检测），手机号走短信验证码绑定；解绑保护确保至少保留一种可登录方式                                                                                                                                                                                                                                                                                                                                                  | <br /> |
| 1.8.0  | 2026-09-02 | 新增邮箱验证码登录：后台新增「邮箱验证」Tab（SMTP 未配置时显示警示条），开启后前台登录页显示「邮箱」入口；验证码 10 分钟有效（复用系统注册验证码缓存键与邮件模板）、每分钟每邮箱限发 3 次、一次性失效；未注册邮箱自动创建用户（用户名取 @ 前缀），邀请注册开启时新邮箱凭邀请码建号                                                                                                                                                                                                                                                                                                                           | <br /> |
| 1.9.0  | 2026-09-02 | 绑定中心微信/QQ 支持 PC 扫码绑定：PC 端改为弹出二维码扫码（`bind-url?device=pc` state 存 Cache 绑定键含目标用户、跨设备回调落库，`bind-status` 轮询结果）；移动端/微信内仍走跳转授权                                                                                                                                                                                                                                                                                                                                                     | <br /> |
| 1.9.1  | 2026-09-03 | 绑定展示形态由后端统一决策：`bind-url` 返回新增 `display` 字段（qrcode=内嵌二维码，redirect=跳转授权），PC 端 QQ 依后台 `qq_mode` 分流（修正绑定中心固定弹二维码、未按 `qq_mode` 分流的问题）；微信 PC 固定扫码、移动端跳转授权不变                                                                                                                                                                                                                                                                                                                        | <br /> |
| 1.10.0 | 2026-09-03 | 新增快捷模式（社交登录中转）：后台「登录设置 → 访问设置」Tab 顶部选择站点角色（独立站/服务端/客户端）；服务端以 OAuth2 授权码模式开放统一登录中转（`/sso/oauth/authorize` 授权端点 + `/api/cmspro/sso/oauth` 开放 API：platforms/token/userinfo/revoke），新增「客户端管理」（client\_id/client\_secret 发放、回调白名单、启停、重置密钥）；客户端本地社交配置强制失效，登录页社交入口改为服务端可中转平台，以「sso+服务端用户id」为身份锚点建号登录，邀请注册与 redirect 跳转全链路兼容；新增 `app_cmspro_sso_clients/auth_codes/access_tokens` 三表与 21 个 Feature 测试用例                                                                              | <br /> |
| 2.0.1  | 2026-09-03 | 修复站点角色保存失效（`quick_mode`/`relay_*` 配置键缺失于保存白名单导致落库被过滤，刷新后回退独立站）；新增菜单联动：保存站点角色后同步「客户端管理」菜单可见性（仅服务端模式可见）并自动刷新左侧菜单；客户端模式保存时校验服务端地址必填；新增 6 个设置保存与菜单联动 Feature 测试用例                                                                                                                                                                                                                                                                                                                | <br /> |
| 2.0.2  | 2026-09-03 | 「客户端管理」菜单默认隐藏：新增 `Install::postInstall` 钩子按站点角色同步菜单状态（默认独立站隐藏，重装时按已存配置同步）；菜单可见性同步逻辑收敛至 `SettingService::syncClientsMenuVisibility`；公众号 AppSecret 与 QQ互联 AppKey 输入框改为明文显示；新增安装菜单同步测试用例                                                                                                                                                                                                                                                                                        | <br /> |
| 2.0.3  | 2026-09-03 | 站点角色为客户端时微信/QQ 设置 Tab 仅保留启用开关，本地凭证配置自动隐藏，保存时不再校验本地凭证（社交登录走服务端中转）；新增客户端模式免凭证校验测试用例                                                                                                                                                                                                                                                                                                                                                                                            | <br /> |
| 2.0.4  | 2026-09-03 | 客户端模式登录页微信/QQ 登录入口改为委托服务端中转：`login-url` 接口在客户端模式返回服务端 OAuth2 authorize 跳转地址（新增 `mode: relay` 字段），前端按 mode 整页跳转服务端授权页（不再提示「本站社交登录已由统一登录中转」）；回调复用 `/auth/relay/callback` 建号登录；原 400 报错断言测试替换为委托中转测试；访问设置「客户端密钥」输入框同步改为明文显示                                                                                                                                                                                                                                                     | <br /> |
| 2.0.5  | 2026-09-03 | 快捷登录新用户分流页支持双 Tab：客户端模式回调未检测到本地账号时不再直接建号，授权数据暂存 Cache（token 一次性消费）后跳转完善资料页；页内提供「完善资料（默认，新建账号）」与「账号绑定（登录已有账号）」两种选择，绑定需账号密码验证（错误 5 次锁定 15 分钟），openid 已被其他账号绑定或账号停用时拒绝并提示；redirect 跳转全链路透传；新增 10 个分流测试用例                                                                                                                                                                                                                                                                        | <br /> |
| 2.0.6  | 2026-09-03 | 客户端 PC 端社交登录改为扫码中转：微信/QQ PC 端不再整页跳服务端授权页，新增服务端开放 API `relay-qrcode`（创建扫码会话并返回服务端授权页地址，10 分钟有效）与 `relay-status`（轮询扫码结果 waiting/confirmed/failed，一次性消费）；客户端 `login-url?device=pc` 返回 `mode=relay_qrcode` 扫码会话数据，前端弹二维码（内容为服务端 authorize 授权页，state=relay\_qr:{session\_id}），手机扫码在服务端登录授权后 PC 端轮询建登录态；新增 `/sso/auth/relay/pc-success` 手机端确认成功提示页；新用户分流与邀请注册全链路兼容；修复中转邀请页平台识别（relay 路径误判为 wechat）；新增 16 个扫码中转 Feature 测试用例                                                        | <br /> |
| 2.0.7  | 2026-09-03 | PC 扫码中转二维码内容改为服务端社交平台授权 URL：`relay-qrcode` 返回微信（`open.weixin.qq.com/connect/oauth2/authorize`）/QQ（`graph.qq.com/oauth2.0/authorize`）授权地址（state=relay\_qr:{session\_id}），手机扫码直达授权确认页、无需先登录服务端；社交回调 `/auth/{platform}/callback` 识别 relay\_qr state，解析社交用户（老用户复用绑定、新用户自动建号）后签发授权码写入扫码会话；会话内容新增 client\_id 供签发校验；会话无效/客户端校验失败/建号失败跳 `pc-success?result=failed`；`SsoRelayQrcodeTest` 适配社交授权链路并新增用例至 18 个                                                                          | <br /> |
| 2.0.9  | 2026-09-04 | 服务端重构为纯跳板透传：服务端不再建号、不绑定、不保存任何用户数据，社交授权数据（社交平台真实 openid/unionid/昵称/头像/令牌及原始数据）经 Cache 暂存（data\_token 关联）透传给客户端；authorize 端点已登录不再静默签发授权码、始终代跳社交平台授权页；`userinfo` 开放 API 返回结构改为社交平台真实数据（原 id/username/nickname/avatar）；客户端以社交平台真实 openid 为身份锚点本地建号（原「sso+服务端用户id」前缀逻辑移除）；同一社交账号无论经哪个服务端中转对应同一本地账号；透传链路平台无关，未来新增第三方登录仅需服务端接入对应社交回调；迁移 `auth_codes`/`access_tokens` 表 `user_id` 改 nullable、`access_tokens` 新增 `data_token` 字段；测试重构适配（全量 74 用例通过）                                   | <br /> |
| 2.0.10 | 2026-09-04 | 修复客户端整页跳转中转登录回调：服务端 authorize 端点此前丢弃客户端原始 state，透传回客户端回调时仅带授权码、不带 state，导致客户端以会话暂存值校验必失败（报「授权状态校验失败，请重新登录」）；现 authorize 将客户端原始 state 一并编入社交回调中转 state（`{ctx, uri, s}`），`relayPassthroughCallback` 回跳客户端回调时原样带回 `state`，客户端会话校验正常通过；配套增强 `SsoOAuthRelayTest` 服务端透传用例断言（全量 74 用例通过）                                                                                                                                                                                            | <br /> |
| 2.0.11 | 2026-09-04 | 补齐支付宝/抖音/微博/GitHub 四个社交平台登录闭环：后台设置页新增四平台配置 Tab（启用开关 + AppID/Key/秘密/回调说明）；新增 `AlipayOauthService`/`DouyinOauthService`/`WeiboOauthService`/`GithubOauthService` 授权服务与对应回调接口；前台登录页按开关显示对应登录入口（品牌色描边 + 文字徽标，无第三方图标依赖），点击经 `/auth/{platform}/login-url` 整页授权跳转，回调支持完善资料、邀请码（`complete-invite`/`invite`）、账号绑定（`bind-url`）全链路；独立站/服务端模式前台显示四平台入口，快捷模式中转客户端由服务端代跳且仅支持微信/QQ，四平台入口自动隐藏；`Home\AuthController` 抽取 `platformFromPath` 统一解析平台标识；新增 `SsoLoginPageTest` 登录页渲染测试（全量 78 用例通过） | <br /> |
| 2.0.12 | 2026-09-04 | 后台设置页 GitHub Client Secret 输入框由密文（password）改为明文（text）展示，便于核对已保存的凭证                                                                                                                                                                                                                                                                                                                                                                                                           | <br /> |
| 2.0.13 | 2026-09-04 | 登录页快捷登录图标区布局优化：一行最多显示 4 个入口，超出自动换行，不足一行时按钮自动拉伸填满整行（支付宝/抖音/微博/GitHub 等入口随启用数量自适应排版）                                                                                                                                                                                                                                                                                                                                                                                           | <br /> |
| 2.0.14 | 2026-09-04 | 完善资料页全平台双 Tab 统一：所有快捷登录新用户分流页（relay）与独立站社交平台（微信/QQ/支付宝/抖音/微博/GitHub）完善资料页统一为「完善资料（默认）/账号绑定」双 Tab；独立站平台新增账号绑定接口（`/auth/{platform}/bind-existing`），将当前平台 openid 第三方绑定转移到目标既有账号并切换登录态，与 relay 模式 `bind` 行为一致；前台登录页支付宝/微博/GitHub 入口由文字徽标替换为内嵌真实 SVG 品牌图标（本地化 base64，无第三方依赖，白底与品牌色 hover 背景下均清晰可见）；新增 `SsoBindExistingTest`（全量 179 用例通过）                                                                                                                                          | <br /> |
| 2.0.15 | 2026-09-04 | 新增通用「绑定中心」共用组件 `cmspro.sso::Home.partials.bind-center`：前台登录后首页重构为复用该组件（行为不变）；当本应用启用时，框架账号管理页（`user.account.index` / `/account`）新增「账号绑定」卡片与弹层入口，可绑定已开启的快捷登录（手机/邮箱/微信/QQ 等），入口按统一登录应用启用状态条件渲染（`AppModel::app_id=cmspro.sso` 且状态启用）；绑定中心平台图标改用 layui 内置图标，避免跨页面样式依赖；新增 `SsoBindCenterComponentTest`（全量 181 用例通过）                                                                                                                                                                | <br /> |
| 2.0.16 | 2026-09-04 | 快捷模式中转客户端登录页快捷登录入口改为按后台启用状态显示：此前客户端模式仅显示微信/QQ，支付宝/抖音/微博/GitHub 即使后台已启用也隐藏；现客户端模式同样展示全部已启用平台入口，点击经 `/auth/{platform}/login-url` 由服务端检测中转客户端并委托服务端代跳，与独立站模式一致                                                                                                                                                                                                                                                                                                      | <br /> |
| 2.0.17 | 2026-09-04 | 调整前台账号管理页「账号绑定」注入方式：此前为直接修改框架账号页视图，现转由本应用按 CmsproNotepad 覆盖 dashboard 模式，在 ServiceProvider 覆盖系统同名路由 user.account（/user/account）渲染应用视图——保留系统个人信息管理并注入账号绑定入口与共用绑定中心组件，全程不修改任何框架文件（全量 88 用例通过） | <br /> |

