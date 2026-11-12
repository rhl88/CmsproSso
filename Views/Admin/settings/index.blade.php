<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>统一登录设置</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    <style>
        .layui-form-label { width: 160px; }
        .layui-input-block { margin-left: 190px; }
        .layui-form-item .layui-input-inline { width: 260px; }
        .settings-tab-content { padding-top: 16px; }
    </style>
</head>
<body>
<div class="pear-container">
    <div class="layui-card">
        <div class="layui-card-body">
                <form class="layui-form" lay-filter="settings-form">
                <div class="layui-tab layui-tab-brief" lay-filter="settings-tab">
                <ul class="layui-tab-title">
                    <li class="layui-this"><i class="fa fa-globe"></i> 访问设置</li>
                    <li><i class="fa fa-user-plus"></i> 邀请注册</li>
                    <li><i class="fa fa-envelope"></i> 邮箱验证</li>
                    <li><i class="fa fa-mobile"></i> 手机登录</li>
                    <li><i class="fa fa-weixin"></i> 微信设置</li>
                    <li><i class="fa fa-qq"></i> QQ设置</li>
                    <li><i class="fa fa-credit-card"></i> 支付宝</li>
                    <li><i class="fa fa-music"></i> 抖音</li>
                    <li><i class="fa fa-weibo"></i> 微博</li>
                    <li><i class="fa fa-github"></i> GitHub</li>
                </ul>
                <div class="layui-tab-content settings-tab-content">

                        {{-- 访问设置 Tab --}}
                        <div class="layui-tab-item layui-show">
                            <div class="layui-form-item">
                                <label class="layui-form-label">站点角色</label>
                                <div class="layui-input-block">
                                    <div style="width: 260px;">
                                    <select name="quick_mode" id="quick_mode" lay-filter="quick_mode">
                                        <option value="standalone" {{ ($configs['quick_mode'] ?? 'standalone') === 'standalone' ? 'selected' : '' }}>独立站（默认）</option>
                                        <option value="server" {{ ($configs['quick_mode'] ?? 'standalone') === 'server' ? 'selected' : '' }}>服务端（提供中转）</option>
                                        <option value="client" {{ ($configs['quick_mode'] ?? 'standalone') === 'client' ? 'selected' : '' }}>客户端（委托中转）</option>
                                    </select></div>
                                    <div class="layui-form-mid layui-word-aux">
                                        服务端：本站已配置的微信/QQ 等社交登录可供客户端站点中转使用；<br>
                                        客户端：本站微信/QQ 等本地社交配置强制失效，登录页社交入口改为服务端可中转平台。
                                    </div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="quick-mode-server-item" style="display:none;">
                                <label class="layui-form-label"></label>
                                <div class="layui-input-block">
                                    <div style="padding: 8px 12px; border-left: 3px solid #16baaa; background: #e8f7f5; color: #0f6b60; font-size: 13px; line-height: 1.8;">
                                        服务端模式下，请前往「<a href="/admin/cmspro/sso/clients" style="color:#1e9fff;">客户端管理</a>」创建客户端并将 client_id / client_secret 发放给客户端站点；
                                        客户端站点的回调地址须加入其白名单。
                                    </div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="relay-server-url-item" style="display:none;">
                                <label class="layui-form-label">服务端地址</label>
                                <div class="layui-input-block">
                                    <input type="text" name="relay_server_url" value="{{ $configs['relay_server_url'] ?? '' }}"
                                           placeholder="https://sso.example.com 或 http://www.example.com/sso" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">服务端统一登录前台基础地址：子域名模式填域名地址；路径前缀模式填含前缀地址（如 http://www.example.com/sso）</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="relay-client-id-item" style="display:none;">
                                <label class="layui-form-label">客户端 ID</label>
                                <div class="layui-input-block">
                                    <input type="text" name="relay_client_id" value="{{ $configs['relay_client_id'] ?? '' }}"
                                           placeholder="sso_xxxxxxxxxxxxxxxx" class="layui-input" style="width: 320px;">
                                    <div class="layui-form-mid layui-word-aux">服务端「客户端管理」发放的 client_id</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="relay-client-secret-item" style="display:none;">
                                <label class="layui-form-label">客户端密钥</label>
                                <div class="layui-input-block">
                                    <input type="text" name="relay_client_secret" value="{{ $configs['relay_client_secret'] ?? '' }}"
                                           placeholder="请输入 client_secret" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">服务端「客户端管理」发放的 client_secret（仅创建/重置时展示一次）</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="relay-callback-item" style="display:none;">
                                <label class="layui-form-label"></label>
                                <div class="layui-input-block">
                                    <div style="padding: 8px 12px; border-left: 3px solid #12b7f5; background: #e6f7ff; color: #05506b; font-size: 13px; line-height: 1.8;">
                                        <strong>回调地址：</strong>请将本站回调地址加入服务端对应客户端的白名单：<br>
                                        <code id="relay-callback-url">{{ $relay_callback_url ?? '' }}</code>
                                    </div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">登录页标题</label>
                                <div class="layui-input-block">
                                    <input type="text" name="login_title" value="{{ $configs['login_title'] ?? '统一登录' }}"
                                           class="layui-input" style="width: 200px;">
                                    <div class="layui-form-mid layui-word-aux">前台登录页展示的标题</div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">访问模式</label>
                                <div class="layui-input-block">
                                    <div style="width: 200px;">
                                    <select name="access_mode" id="access_mode" lay-filter="access_mode">
                                        <option value="path" {{ ($configs['access_mode'] ?? 'path') === 'path' ? 'selected' : '' }}>路径前缀</option>
                                        <option value="domain" {{ ($configs['access_mode'] ?? 'path') === 'domain' ? 'selected' : '' }}>子域名绑定</option>
                                    </select></div>
                                    <div class="layui-form-mid layui-word-aux">路径前缀：/sso/ 访问；子域名：sso.example.com 访问</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="access-domain-item">
                                <label class="layui-form-label">绑定域名</label>
                                <div class="layui-input-block">
                                    <input type="text" name="access_domain" value="{{ $configs['access_domain'] ?? '' }}"
                                           placeholder="sso.example.com" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">子域名模式下生效，支持多域名逗号分隔。需配置 DNS 与 SESSION_DOMAIN</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="access-path-item">
                                <label class="layui-form-label">路径前缀</label>
                                <div class="layui-input-block">
                                    <input type="text" name="access_path" value="{{ $configs['access_path'] ?? 'sso' }}"
                                           placeholder="sso" class="layui-input" style="width: 200px;">
                                    <div class="layui-form-mid layui-word-aux">路径前缀模式下生效，如 sso 则通过 /sso/ 访问</div>
                                </div>
                            </div>

                            <div class="layui-form-item">
                                <label class="layui-form-label">覆盖系统</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="override_system" lay-skin="switch" lay-text="开启|关闭"
                                           {{ ($configs['override_system'] ?? '0') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">开启后，访问系统默认登录 /user/login 与注册 /user/register 将重定向到本应用登录/注册页</div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">允许注册</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="allow_register" lay-skin="switch" lay-text="开启|关闭"
                                           {{ ($configs['allow_register'] ?? '1') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">开启后前台显示注册入口</div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">用户名屏蔽词</label>
                                <div class="layui-input-block">
                                    <textarea name="username_banned_words" class="layui-textarea" placeholder="如：admin,root,管理员">{{ $configs['username_banned_words'] ?? '' }}</textarea>
                                    <div class="layui-form-mid layui-word-aux">注册用户名、昵称包含任一屏蔽词将被拒绝，多个词用逗号、分号或换行分隔，不区分大小写</div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">默认跳转地址</label>
                                <div class="layui-input-block">
                                    <input type="text" name="default_redirect" value="{{ $configs['default_redirect'] ?? '/' }}"
                                           placeholder="/" class="layui-input" style="width: 200px;">
                                    <div class="layui-form-mid layui-word-aux">登录成功后未指定 redirect 参数时的默认跳转地址</div>
                                </div>
                            </div>

                            <div class="layui-form-item">
                                <label class="layui-form-label">验证码开关</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="captcha_enabled" lay-skin="switch" lay-text="开启|关闭" lay-filter="captcha_enabled"
                                           {{ ($configs['captcha_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">开启后前台登录/注册表单需先通过人机验证，由「验证码」应用提供能力</div>
                                </div>
                            </div>
                            @if(!($captcha_installed ?? false) || !($captcha_enabled_app ?? false))
                            <div class="layui-form-item">
                                <label class="layui-form-label"></label>
                                <div class="layui-input-block">
                                    <div style="padding: 8px 12px; border-left: 3px solid #ffb800; background: #fffbe6; color: #874d00; font-size: 13px; line-height: 1.8;">
                                        @if(!($captcha_installed ?? false))
                                        「验证码」应用当前<strong>未安装</strong>，请前往「应用管理」安装「验证码」应用。
                                        @else
                                        「验证码」应用已安装但<strong>未启用</strong>，请前往「应用管理」启用「验证码」应用。
                                        @endif
                                        依赖就绪前无法保存验证码开关。
                                    </div>
                                </div>
                            </div>
                            @endif
                            <div id="captcha-extra-fields" style="{{ ($configs['captcha_enabled'] ?? '0') === '1' ? '' : 'display:none;' }}">
                                <div class="layui-form-item">
                                    <label class="layui-form-label">启用位置</label>
                                    <div class="layui-input-block" style="padding-top: 8px;">
                                        <input type="checkbox" name="captcha_scene_login" value="login" title="登录" lay-skin="primary"
                                               {{ ($captcha_scene_login ?? false) ? 'checked' : '' }}>
                                        <input type="checkbox" name="captcha_scene_register" value="register" title="注册" lay-skin="primary"
                                               {{ ($captcha_scene_register ?? false) ? 'checked' : '' }}>
                                        <div class="layui-form-mid layui-word-aux">控制验证码在哪些表单生效，至少选择一项</div>
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">验证码模式</label>
                                    <div class="layui-input-block">
                                        <div style="width: 220px;">
                                        <select name="captcha_driver">
                                            <option value="image" {{ ($configs['captcha_driver'] ?? 'image') === 'image' ? 'selected' : '' }}>图片字符验证码</option>
                                            <option value="math" {{ ($configs['captcha_driver'] ?? 'image') === 'math' ? 'selected' : '' }}>算术计算验证码</option>
                                            <option value="chinese" {{ ($configs['captcha_driver'] ?? 'image') === 'chinese' ? 'selected' : '' }}>中文汉字验证码</option>
                                            <option value="click" {{ ($configs['captcha_driver'] ?? 'image') === 'click' ? 'selected' : '' }}>文字点选验证码</option>
                                            <option value="slider" {{ ($configs['captcha_driver'] ?? 'image') === 'slider' ? 'selected' : '' }}>滑块拼图验证码</option>
                                            <option value="rotate" {{ ($configs['captcha_driver'] ?? 'image') === 'rotate' ? 'selected' : '' }}>旋转验证码</option>
                                            <option value="click_button" {{ ($configs['captcha_driver'] ?? 'image') === 'click_button' ? 'selected' : '' }}>点击按钮验证码</option>
                                        </select></div>
                                        <div class="layui-form-mid layui-word-aux">各模式效果可 <a href="/admin/cmspro/captcha/demo" target="_blank" style="color: #1e9fff;">点击预览体验</a>（打开「验证码」应用演示页）</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- 邀请注册 Tab --}}
                        <div class="layui-tab-item">
                            <div class="layui-form-item">
                                <label class="layui-form-label">邀请注册开关</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="invite_enabled" lay-skin="switch" lay-text="开启|关闭" lay-filter="invite_enabled"
                                           {{ ($configs['invite_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">开启后前台注册需使用邀请码</div>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">邀请码发放方式</label>
                                <div class="layui-input-block">
                                    <div style="width: 200px;">
                                    <select name="invite_grant_mode" id="invite_grant_mode" lay-filter="invite_grant_mode">
                                        <option value="online_buy" {{ ($configs['invite_grant_mode'] ?? 'online_buy') === 'online_buy' ? 'selected' : '' }}>在线购买</option>
                                        <option value="url" {{ ($configs['invite_grant_mode'] ?? 'online_buy') === 'url' ? 'selected' : '' }}>指定URL</option>
                                        <option value="text" {{ ($configs['invite_grant_mode'] ?? 'online_buy') === 'text' ? 'selected' : '' }}>文字提示</option>
                                    </select></div>
                                    <div class="layui-form-mid layui-word-aux">选择邀请码的发放渠道，切换后仅显示对应设置</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="onlinepay-status-item" style="{{ (($configs['invite_grant_mode'] ?? 'online_buy') === 'online_buy' && !($onlinepay_enabled ?? false)) ? '' : 'display:none;' }}">
                                <label class="layui-form-label"></label>
                                <div class="layui-input-block">
                                    <div style="padding: 8px 12px; border-left: 3px solid #ffb800; background: #fffbe6; color: #874d00; font-size: 13px; line-height: 1.8;">
                                        在线购买模式依赖「在线支付」应用：
                                        @if(!($onlinepay_installed ?? false))
                                        当前<strong>未安装</strong>，请前往「应用管理」安装「在线支付」应用并启用。
                                        @elseif(!($onlinepay_enabled ?? false))
                                        当前已安装但<strong>未启用</strong>，请前往「应用管理」启用「在线支付」应用。
                                        @endif
                                        依赖就绪前无法保存在线购买模式。
                                    </div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="invite-price-item" style="{{ ($configs['invite_grant_mode'] ?? 'online_buy') === 'online_buy' ? '' : 'display:none;' }}">
                                <label class="layui-form-label">邀请码费用</label>
                                <div class="layui-input-block">
                                    <input type="text" name="invite_price" value="{{ $configs['invite_price'] ?? '' }}"
                                           placeholder="9.90" class="layui-input" style="width: 200px;">
                                    <div class="layui-form-mid layui-word-aux">在线购买模式下单个邀请码的售价（元）</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="invite-url-item" style="{{ ($configs['invite_grant_mode'] ?? 'online_buy') === 'url' ? '' : 'display:none;' }}">
                                <label class="layui-form-label">获取链接</label>
                                <div class="layui-input-block">
                                    <input type="text" name="invite_url" value="{{ $configs['invite_url'] ?? '' }}"
                                           placeholder="https://example.com/buy-invite" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">指定URL模式下，前台展示该链接引导用户前往获取邀请码</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="invite-text-item" style="{{ ($configs['invite_grant_mode'] ?? 'online_buy') === 'text' ? '' : 'display:none;' }}">
                                <label class="layui-form-label">文字提示</label>
                                <div class="layui-input-block">
                                    <textarea name="invite_text" class="layui-textarea" placeholder="如：请联系管理员获取邀请码">{{ $configs['invite_text'] ?? '' }}</textarea>
                                    <div class="layui-form-mid layui-word-aux">文字提示模式下，前台注册页展示该提示内容</div>
                                </div>
                            </div>
                        </div>

                        {{-- 邮箱验证 Tab --}}
                        <div class="layui-tab-item">
                            <div class="layui-form-item">
                                <label class="layui-form-label">邮箱验证码登录</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="email_login_enabled" lay-skin="switch" lay-text="开启|关闭"
                                           {{ ($configs['email_login_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">开启后前台登录页显示邮箱验证码登录入口</div>
                                </div>
                            </div>
                            @if($smtpmail_takeover ?? false)
                            {{-- SMTP邮箱已接管系统邮件：强制使用 SMTP邮箱，无需选择 --}}
                            <input type="hidden" name="email_mailer" value="smtpmail">
                            <div class="layui-form-item">
                                <label class="layui-form-label">发信方式</label>
                                <div class="layui-input-block">
                                    <div style="padding: 8px 12px; border-left: 3px solid #16baaa; background: #e8f7f5; color: #0f6b60; font-size: 13px; line-height: 1.8;">
                                        <i class="fa fa-check-circle"></i> 「SMTP邮箱」应用已开启接管系统邮件，验证码邮件将通过 SMTP邮箱 发送，无需额外配置。
                                    </div>
                                </div>
                            </div>
                            @else
                            <div class="layui-form-item">
                                <label class="layui-form-label">发信方式</label>
                                <div class="layui-input-block">
                                    <div style="width: 220px;">
                                    <select name="email_mailer" id="email_mailer" lay-filter="email_mailer">
                                        <option value="system" {{ ($configs['email_mailer'] ?? 'system') === 'system' ? 'selected' : '' }}>系统邮件配置</option>
                                        <option value="smtpmail" {{ ($configs['email_mailer'] ?? 'system') === 'smtpmail' ? 'selected' : '' }}
                                                {{ !($smtpmail_enabled ?? false) ? 'disabled' : '' }}>SMTP邮箱应用@if(!($smtpmail_installed ?? false))（未安装）@elseif(!($smtpmail_enabled ?? false))（未启用）@endif</option>
                                    </select></div>
                                    <div class="layui-form-mid layui-word-aux">
                                        选择验证码邮件的发送通道；「SMTP邮箱」开启接管系统邮件后将自动切换为该通道发送。
                                    </div>
                                </div>
                            </div>
                            @if(!($smtpmail_installed ?? false) || !($smtpmail_enabled ?? false))
                            {{-- SMTP邮箱应用未就绪：置灰原因说明 + 安装/启用引导 --}}
                            <div class="layui-form-item">
                                <label class="layui-form-label"></label>
                                <div class="layui-input-block">
                                    <div style="padding: 10px 14px; border-left: 3px solid #1e9fff; background: #f0f7ff; color: #12457a; font-size: 13px; line-height: 1.9;">
                                        <div style="font-weight: 600; margin-bottom: 4px;">
                                            <i class="fa fa-info-circle"></i>
                                            @if(!($smtpmail_installed ?? false))
                                            「SMTP邮箱」应用尚未安装，该发信方式已置灰不可选
                                            @else
                                            「SMTP邮箱」应用已安装但尚未启用，该发信方式已置灰不可选
                                            @endif
                                        </div>
                                        <div style="color: #4a6a8a;">
                                            @if(!($smtpmail_installed ?? false))
                                            安装并启用后，可直接复用应用内配置的邮箱账号发送验证码邮件，无需再单独配置系统 SMTP。
                                            <a href="{{ route('admin.app.market') }}" class="layui-btn layui-btn-xs layui-btn-normal" style="margin-left: 6px;">
                                                <i class="fa fa-download"></i> 前往应用市场安装
                                            </a>
                                            @else
                                            启用后即可复用应用内配置的邮箱账号发送验证码邮件，无需再单独配置系统 SMTP。
                                            <a href="{{ route('admin.app.index') }}" class="layui-btn layui-btn-xs layui-btn-normal" style="margin-left: 6px;">
                                                <i class="fa fa-cog"></i> 前往应用管理启用
                                            </a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @endif
                            {{-- 系统邮件配置未就绪提示 --}}
                            <div id="email-mailer-system-warn" style="{{ ($configs['email_mailer'] ?? 'system') === 'system' && !mail_configured() ? '' : 'display:none;' }}">
                                <div class="layui-form-item">
                                    <label class="layui-form-label"></label>
                                    <div class="layui-input-block">
                                        <div style="padding: 8px 12px; border-left: 3px solid #ffb800; background: #fffbe6; color: #874d00; font-size: 13px; line-height: 1.8;">
                                            系统尚未配置 SMTP 邮箱，验证码邮件无法发送。请前往「系统设置 → 邮件设置」完成 SMTP 配置，或安装并启用「SMTP邮箱」应用后切换发信方式。
                                        </div>
                                    </div>
                                </div>
                            </div>
                            {{-- SMTP邮箱应用未就绪提示 --}}
                            <div id="email-mailer-smtpmail-warn" style="{{ ($configs['email_mailer'] ?? 'system') === 'smtpmail' && !($smtpmail_enabled ?? false) ? '' : 'display:none;' }}">
                                <div class="layui-form-item">
                                    <label class="layui-form-label"></label>
                                    <div class="layui-input-block">
                                        <div style="padding: 8px 12px; border-left: 3px solid #ffb800; background: #fffbe6; color: #874d00; font-size: 13px; line-height: 1.8;">
                                            当前发信方式指向的「SMTP邮箱」应用不可用，验证码邮件无法发送。请切换为系统邮件配置，或按上方引导完成安装启用。
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @endif
                        </div>

                        {{-- 手机登录 Tab --}}
                        <div class="layui-tab-item">
                            <div class="layui-form-item">
                                <label class="layui-form-label">启用验证码登录</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="phone_login_enabled" lay-skin="switch" lay-text="开启|关闭"
                                           {{ ($configs['phone_login_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">开启后前台登录页显示手机验证码登录入口</div>
                                </div>
                            </div>
                            @php
                                $smsChannel = $configs['sms_channel'] ?? 'builtin';
                                $savedAlias = trim($configs['sms_niuren_template_alias'] ?? '');
                                $aliasList = array_column($smssend_templates ?? [], 'alias');
                            @endphp
                            <div class="layui-form-item">
                                <label class="layui-form-label">短信通道</label>
                                <div class="layui-input-block">
                                    <div style="width: 200px;">
                                    <select name="sms_channel" id="sms_channel" lay-filter="sms_channel">
                                        <option value="builtin" {{ $smsChannel === 'builtin' ? 'selected' : '' }}>内置短信服务</option>
                                        <option value="niuren" {{ $smsChannel === 'niuren' ? 'selected' : '' }}
                                                {{ !($smssend_enabled ?? false) ? 'disabled' : '' }}>短信发送应用@if(!($smssend_installed ?? false))（未安装）@elseif(!($smssend_enabled ?? false))（未启用）@endif</option>
                                    </select></div>
                                    <div class="layui-form-mid layui-word-aux">
                                        内置短信服务使用下方服务商凭证；短信发送应用的服务商、签名与模板由该应用统一管理。
                                    </div>
                                </div>
                            </div>
                            @if(!($smssend_installed ?? false) || !($smssend_enabled ?? false))
                            {{-- 短信发送应用未就绪：置灰原因说明 + 安装/启用引导 --}}
                            <div class="layui-form-item">
                                <label class="layui-form-label"></label>
                                <div class="layui-input-block">
                                    <div style="padding: 10px 14px; border-left: 3px solid #1e9fff; background: #f0f7ff; color: #12457a; font-size: 13px; line-height: 1.9;">
                                        <div style="font-weight: 600; margin-bottom: 4px;">
                                            <i class="fa fa-info-circle"></i>
                                            @if(!($smssend_installed ?? false))
                                            「短信发送」应用尚未安装，该短信通道已置灰不可选
                                            @else
                                            「短信发送」应用已安装但尚未启用，该短信通道已置灰不可选
                                            @endif
                                        </div>
                                        <div style="color: #4a6a8a;">
                                            @if(!($smssend_installed ?? false))
                                            安装并启用后，可直接复用该应用统一维护的服务商凭证、签名与短信模板，无需在本应用重复配置。
                                            <a href="{{ route('admin.app.market') }}" class="layui-btn layui-btn-xs layui-btn-normal" style="margin-left: 6px;">
                                                <i class="fa fa-download"></i> 前往应用市场安装
                                            </a>
                                            @else
                                            启用后即可复用该应用统一维护的服务商凭证、签名与短信模板，无需在本应用重复配置。
                                            <a href="{{ route('admin.app.index') }}" class="layui-btn layui-btn-xs layui-btn-normal" style="margin-left: 6px;">
                                                <i class="fa fa-cog"></i> 前往应用管理启用
                                            </a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @endif

                            {{-- 内置短信服务配置 --}}
                            <div id="sms-builtin-fields" style="display:none;">
                            <div class="layui-form-item">
                                <label class="layui-form-label">短信服务商</label>
                                <div class="layui-input-block">
                                    <div style="width: 200px;">
                                    <select name="sms_provider" id="sms_provider" lay-filter="sms_provider">
                                        <option value="aliyun" {{ ($configs['sms_provider'] ?? 'aliyun') === 'aliyun' ? 'selected' : '' }}>阿里云</option>
                                        <option value="tencent" {{ ($configs['sms_provider'] ?? 'aliyun') === 'tencent' ? 'selected' : '' }}>腾讯云</option>
                                        <option value="volcengine" {{ ($configs['sms_provider'] ?? 'aliyun') === 'volcengine' ? 'selected' : '' }}>火山引擎</option>
                                    </select></div>
                                    <div class="layui-form-mid layui-word-aux">选择用于发送验证码短信的服务商，同一时间仅一家生效</div>
                                </div>
                            </div>

                            <div id="sms-aliyun-fields" style="display:none;">
                                <div class="layui-form-item">
                                    <label class="layui-form-label">AccessKey ID</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="sms_aliyun_access_key_id" value="{{ $configs['sms_aliyun_access_key_id'] ?? '' }}" placeholder="LTAI..." class="layui-input">
                                        <div class="layui-form-mid layui-word-aux">阿里云 RAM 用户的 AccessKey ID</div>
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">AccessKey Secret</label>
                                    <div class="layui-input-block">
                                        <input type="password" name="sms_aliyun_access_key_secret" value="{{ $configs['sms_aliyun_access_key_secret'] ?? '' }}" placeholder="请输入 AccessKey Secret" class="layui-input">
                                        <div class="layui-form-mid layui-word-aux">阿里云 RAM 用户的 AccessKey Secret</div>
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">短信签名</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="sms_aliyun_sign_name" value="{{ $configs['sms_aliyun_sign_name'] ?? '' }}" placeholder="已报备的签名内容" class="layui-input">
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">模板 Code</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="sms_aliyun_template_code" value="{{ $configs['sms_aliyun_template_code'] ?? '' }}" placeholder="SMS_123456789" class="layui-input">
                                        <div class="layui-form-mid layui-word-aux">短信模板 CODE，模板变量为 ${code}</div>
                                    </div>
                                </div>
                            </div>

                            <div id="sms-tencent-fields" style="display:none;">
                                <div class="layui-form-item">
                                    <label class="layui-form-label">SecretId</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="sms_tencent_secret_id" value="{{ $configs['sms_tencent_secret_id'] ?? '' }}" placeholder="AKID..." class="layui-input">
                                        <div class="layui-form-mid layui-word-aux">腾讯云 API 密钥 SecretId</div>
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">SecretKey</label>
                                    <div class="layui-input-block">
                                        <input type="password" name="sms_tencent_secret_key" value="{{ $configs['sms_tencent_secret_key'] ?? '' }}" placeholder="请输入 SecretKey" class="layui-input">
                                        <div class="layui-form-mid layui-word-aux">腾讯云 API 密钥 SecretKey</div>
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">SDKAppID</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="sms_tencent_sdk_app_id" value="{{ $configs['sms_tencent_sdk_app_id'] ?? '' }}" placeholder="1400006666" class="layui-input">
                                        <div class="layui-form-mid layui-word-aux">短信应用 SDKAppID</div>
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">短信签名</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="sms_tencent_sign_name" value="{{ $configs['sms_tencent_sign_name'] ?? '' }}" placeholder="已报备的签名内容" class="layui-input">
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">模板 ID</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="sms_tencent_template_id" value="{{ $configs['sms_tencent_template_id'] ?? '' }}" placeholder="1234567" class="layui-input">
                                    </div>
                                </div>
                            </div>

                            <div id="sms-volcengine-fields" style="display:none;">
                                <div class="layui-form-item">
                                    <label class="layui-form-label">AccessKey ID</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="sms_volc_access_key_id" value="{{ $configs['sms_volc_access_key_id'] ?? '' }}" placeholder="AKLT..." class="layui-input">
                                        <div class="layui-form-mid layui-word-aux">火山引擎 API 访问密钥 Access Key ID</div>
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">Secret Access Key</label>
                                    <div class="layui-input-block">
                                        <input type="password" name="sms_volc_access_key_secret" value="{{ $configs['sms_volc_access_key_secret'] ?? '' }}" placeholder="请输入 Secret Access Key" class="layui-input">
                                        <div class="layui-form-mid layui-word-aux">火山引擎 API 访问密钥 Secret Access Key</div>
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">消息组 ID</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="sms_volc_sms_account" value="{{ $configs['sms_volc_sms_account'] ?? '' }}" placeholder="A123****" class="layui-input">
                                        <div class="layui-form-mid layui-word-aux">短信消息组 SmsAccount</div>
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">短信签名</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="sms_volc_sign_name" value="{{ $configs['sms_volc_sign_name'] ?? '' }}" placeholder="已报备的签名内容" class="layui-input">
                                    </div>
                                </div>
                                <div class="layui-form-item">
                                    <label class="layui-form-label">模板 ID</label>
                                    <div class="layui-input-block">
                                        <input type="text" name="sms_volc_template_id" value="{{ $configs['sms_volc_template_id'] ?? '' }}" placeholder="ST_6f8c****" class="layui-input">
                                    </div>
                                </div>
                            </div>
                            </div>{{-- /内置短信服务配置 --}}

                            {{-- 短信发送应用配置 --}}
                            <div id="sms-niuren-fields" style="display:none;">
                                <div class="layui-form-item">
                                    <label class="layui-form-label">短信模板</label>
                                    <div class="layui-input-block">
                                        <div style="width: 360px;">
                                        <select name="sms_niuren_template_alias" id="sms_niuren_template_alias" lay-search
                                                {{ !($smssend_enabled ?? false) ? 'disabled' : '' }}>
                                            {{-- 保留空选项：应用无可用模板时避免收集到 null 覆盖已存值 --}}
                                            <option value="">请选择短信模板</option>
                                            @foreach($smssend_templates ?? [] as $tpl)
                                            <option value="{{ $tpl['alias'] }}" {{ $savedAlias === $tpl['alias'] ? 'selected' : '' }}>
                                                {{ $tpl['name'] }}（{{ $tpl['alias'] }}）
                                            </option>
                                            @endforeach
                                            @if($savedAlias !== '' && !in_array($savedAlias, $aliasList, true))
                                            <option value="{{ $savedAlias }}" selected>{{ $savedAlias }}（当前不可用）</option>
                                            @endif
                                        </select></div>
                                        <div class="layui-form-mid layui-word-aux">
                                            支持输入模板名称或模板标识搜索；模板需先在「短信发送」应用中创建并填写模板标识，且模板内须包含变量 code 用于接收验证码。
                                        </div>
                                    </div>
                                </div>
                                @if(!($smssend_enabled ?? false) || $savedAlias === '')
                                <div class="layui-form-item">
                                    <label class="layui-form-label"></label>
                                    <div class="layui-input-block">
                                        <div style="padding: 8px 12px; border-left: 3px solid #ffb800; background: #fffbe6; color: #874d00; font-size: 13px; line-height: 1.8;">
                                            @if(!($smssend_enabled ?? false))
                                            「短信发送」应用未就绪，验证码短信无法发送。请按上方引导安装并启用该应用，或切换为内置短信服务。
                                            @else
                                            尚未选择短信模板，验证码短信无法发送。请前往
                                            <a href="/admin/niuren/smssend/templates" style="color:#1e9fff;">「短信发送 → 模板管理」</a>
                                            创建验证码模板并设置模板标识后，再回到本页选择。
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                @endif
                            </div>
                        </div>

                        {{-- 微信设置 Tab --}}
                        <div class="layui-tab-item">
                            <div class="layui-form-item">
                                <label class="layui-form-label">启用微信登录</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="wechat_enabled" lay-skin="switch" lay-text="开启|关闭"
                                           {{ ($configs['wechat_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">开启后前台登录页显示微信登录入口</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="wechat-appid-item">
                                <label class="layui-form-label">公众号 AppID</label>
                                <div class="layui-input-block">
                                    <input type="text" name="wechat_mp_app_id" value="{{ $configs['wechat_mp_app_id'] ?? '' }}"
                                           placeholder="wx..." class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">微信公众平台的 AppID，用于网页授权登录</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="wechat-secret-item">
                                <label class="layui-form-label">公众号 AppSecret</label>
                                <div class="layui-input-block">
                                    <input type="text" name="wechat_mp_app_secret" value="{{ $configs['wechat_mp_app_secret'] ?? '' }}"
                                           placeholder="请输入 AppSecret" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">微信公众平台的 AppSecret，用于 code 换取 openid</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="wechat-oauth-item">
                                <label class="layui-form-label">网页授权外挂</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="wechat_oauth_enabled" lay-skin="switch" lay-text="开启|关闭" lay-filter="wechat_oauth_enabled"
                                           {{ ($configs['wechat_oauth_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">微信「网页授权域名」配额有限时启用</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="wechat_oauth_url_row" style="{{ ($configs['wechat_oauth_enabled'] ?? '0') === '1' ? '' : 'display:none;' }}">
                                <label class="layui-form-label">外挂地址</label>
                                <div class="layui-input-block">
                                    <input type="text" name="wechat_oauth_url" value="{{ $configs['wechat_oauth_url'] ?? '' }}"
                                           placeholder="https://oauth.example.com/wechat_oauth.php" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">
                                        部署 <code>wechat_oauth.php</code> 的完整访问地址（参考 <code>https://gitee.com/holley/wechat_oauth</code>）。<br>
                                        <strong>使用场景：</strong>公众号「网页授权域名」绑定数量受限时，将外挂脚本部署到已绑定域名下，多个站点共用同一授权入口。<br>
                                        <strong>登录对接：</strong>开启后，用户在微信内点击「微信登录」时跳转到 <code>{外挂地址}?action=authorize&type=userinfo&return_url={统一登录回调地址}&state={state}</code>，外挂脚本完成授权后跳回统一登录回调地址 <code>/auth/wechat/callback</code> 并附带 <code>openid</code>、<code>state</code> 参数，获取昵称头像用于首次登录创建用户。
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- QQ设置 Tab --}}
                        <div class="layui-tab-item">
                            <div class="layui-form-item">
                                <label class="layui-form-label">启用QQ登录</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="qq_enabled" lay-skin="switch" lay-text="开启|关闭"
                                           {{ ($configs['qq_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">开启后前台登录页显示QQ登录入口</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="qq-mode-item">
                                <label class="layui-form-label">功能选项</label>
                                <div class="layui-input-block">
                                    <div style="width: 200px;">
                                        <select name="qq_mode" id="qq_mode">
                                            <option value="qrcode" {{ ($configs['qq_mode'] ?? 'web') === 'qrcode' ? 'selected' : '' }}>QQ扫码登录</option>
                                            <option value="web" {{ ($configs['qq_mode'] ?? 'web') === 'web' ? 'selected' : '' }}>QQ网页登录</option>
                                        </select>
                                    </div>
                                    <div class="layui-form-mid layui-word-aux">QQ扫码登录：PC 端展示二维码，需使用QQ手机版扫一扫登录；QQ网页登录（默认）：跳转 QQ互联 OAuth2.0 认证页授权登录</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="qq-appid-item">
                                <label class="layui-form-label">QQ互联 AppID</label>
                                <div class="layui-input-block">
                                    <input type="text" name="qq_app_id" value="{{ $configs['qq_app_id'] ?? '' }}"
                                           placeholder="101xxxxxx" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">QQ互联开放平台的应用 AppID，用于网页授权登录</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="qq-appkey-item">
                                <label class="layui-form-label">QQ互联 AppKey</label>
                                <div class="layui-input-block">
                                    <input type="text" name="qq_app_key" value="{{ $configs['qq_app_key'] ?? '' }}"
                                           placeholder="请输入 AppKey" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">QQ互联开放平台的 AppKey（AppSecret），用于 code 换取 openid</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="qq-callback-item">
                                <label class="layui-form-label"></label>
                                <div class="layui-input-block">
                                    <div style="padding: 8px 12px; border-left: 3px solid #12b7f5; background: #e6f7ff; color: #05506b; font-size: 13px; line-height: 1.8;">
                                        <strong>回调地址：</strong>请在 QQ互联开放平台「网站应用」中，将授权回调域配置为当前系统的回调地址。<br>
                                        <strong>子域名模式：</strong><code>http://{绑定域名}/auth/qq/callback</code><br>
                                        <strong>路径模式：</strong><code>http://{当前域名}/{访问路径}/auth/qq/callback</code>（默认路径前缀为 <code>sso</code>）<br>
                                        <strong>首次登录：</strong>QQ 授权成功后需完善资料并绑定邀请码，与微信登录流程一致。
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- 支付宝 Tab --}}
                        <div class="layui-tab-item">
                            <div class="layui-form-item">
                                <label class="layui-form-label">启用支付宝登录</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="alipay_enabled" lay-skin="switch" lay-text="开启|关闭"
                                           {{ ($configs['alipay_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">开启后前台登录页显示支付宝登录入口</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="alipay-appid-item">
                                <label class="layui-form-label">支付宝 AppID</label>
                                <div class="layui-input-block">
                                    <input type="text" name="alipay_app_id" value="{{ $configs['alipay_app_id'] ?? '' }}"
                                           placeholder="2021000000000000" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">支付宝开放平台「网页/移动应用」的 AppID，用于网页授权登录（RSA2 签名）</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="alipay-private-key-item">
                                <label class="layui-form-label">应用私钥</label>
                                <div class="layui-input-block">
                                    <textarea name="alipay_private_key" class="layui-textarea" placeholder="-----BEGIN RSA PRIVATE KEY-----" style="height: 120px;">{{ $configs['alipay_private_key'] ?? '' }}</textarea>
                                    <div class="layui-form-mid layui-word-aux">支付宝开放平台生成的应用私钥（RSA2），用于签名单个授权请求</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="alipay-callback-item">
                                <label class="layui-form-label"></label>
                                <div class="layui-input-block">
                                    <div style="padding: 8px 12px; border-left: 3px solid #12b7f5; background: #e6f7ff; color: #05506b; font-size: 13px; line-height: 1.8;">
                                        <strong>回调地址：</strong>请在支付宝开放平台的「授权回调地址」中填入本站支付宝登录回调地址（授权后平台回调）。<br>
                                        <strong>子域名模式：</strong><code>http://{绑定域名}/auth/alipay/callback</code><br>
                                        <strong>路径模式：</strong><code>http://{当前域名}/{访问路径}/auth/alipay/callback</code>（默认路径前缀为 <code>sso</code>）<br>
                                        <strong>首次登录：</strong>支付宝授权成功后需完善资料并绑定邀请码，与其他社交登录流程一致。
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- 抖音 Tab --}}
                        <div class="layui-tab-item">
                            <div class="layui-form-item">
                                <label class="layui-form-label">启用抖音登录</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="douyin_enabled" lay-skin="switch" lay-text="开启|关闭"
                                           {{ ($configs['douyin_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">开启后前台登录页显示抖音登录入口</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="douyin-client-key-item">
                                <label class="layui-form-label">抖音 Client Key</label>
                                <div class="layui-input-block">
                                    <input type="text" name="douyin_client_key" value="{{ $configs['douyin_client_key'] ?? '' }}"
                                           placeholder="a1b2c3d4..." class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">抖音开放平台应用的 Client Key（应用标识），用于网页授权登录</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="douyin-client-secret-item">
                                <label class="layui-form-label">抖音 Client Secret</label>
                                <div class="layui-input-block">
                                    <input type="text" name="douyin_client_secret" value="{{ $configs['douyin_client_secret'] ?? '' }}"
                                           placeholder="请输入 Client Secret" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">抖音开放平台应用的 Client Secret，用于授权后换取用户信息</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="douyin-callback-item">
                                <label class="layui-form-label"></label>
                                <div class="layui-input-block">
                                    <div style="padding: 8px 12px; border-left: 3px solid #12b7f5; background: #e6f7ff; color: #05506b; font-size: 13px; line-height: 1.8;">
                                        <strong>回调地址：</strong>请在抖音开放平台将「授权回调地址」配置为本站抖音登录回调地址。<br>
                                        <strong>子域名模式：</strong><code>http://{绑定域名}/auth/douyin/callback</code><br>
                                        <strong>路径模式：</strong><code>http://{当前域名}/{访问路径}/auth/douyin/callback</code>（默认路径前缀为 <code>sso</code>）<br>
                                        <strong>首次登录：</strong>抖音授权成功后需完善资料并绑定邀请码，与其他社交登录流程一致。
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- 微博 Tab --}}
                        <div class="layui-tab-item">
                            <div class="layui-form-item">
                                <label class="layui-form-label">启用微博登录</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="weibo_enabled" lay-skin="switch" lay-text="开启|关闭"
                                           {{ ($configs['weibo_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">开启后前台登录页显示微博登录入口</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="weibo-app-key-item">
                                <label class="layui-form-label">微博 App Key</label>
                                <div class="layui-input-block">
                                    <input type="text" name="weibo_app_key" value="{{ $configs['weibo_app_key'] ?? '' }}"
                                           placeholder="1234567890" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">微博开放平台的 App Key（应用标识），用于网页授权登录</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="weibo-app-secret-item">
                                <label class="layui-form-label">微博 App Secret</label>
                                <div class="layui-input-block">
                                    <input type="text" name="weibo_app_secret" value="{{ $configs['weibo_app_secret'] ?? '' }}"
                                           placeholder="请输入 App Secret" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">微博开放平台的 App Secret，用于授权后换取用户信息</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="weibo-callback-item">
                                <label class="layui-form-label"></label>
                                <div class="layui-input-block">
                                    <div style="padding: 8px 12px; border-left: 3px solid #12b7f5; background: #e6f7ff; color: #05506b; font-size: 13px; line-height: 1.8;">
                                        <strong>回调地址：</strong>请在微博开放平台将「OAuth2.0 授权回调页」配置为本站微博登录回调地址。<br>
                                        <strong>子域名模式：</strong><code>http://{绑定域名}/auth/weibo/callback</code><br>
                                        <strong>路径模式：</strong><code>http://{当前域名}/{访问路径}/auth/weibo/callback</code>（默认路径前缀为 <code>sso</code>）<br>
                                        <strong>首次登录：</strong>微博授权成功后需完善资料并绑定邀请码，与其他社交登录流程一致。
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- GitHub Tab --}}
                        <div class="layui-tab-item">
                            <div class="layui-form-item">
                                <label class="layui-form-label">启用 GitHub 登录</label>
                                <div class="layui-input-block">
                                    <input type="checkbox" name="github_enabled" lay-skin="switch" lay-text="开启|关闭"
                                           {{ ($configs['github_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                                    <div class="layui-form-mid layui-word-aux">开启后前台登录页显示 GitHub 登录入口</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="github-client-id-item">
                                <label class="layui-form-label">GitHub Client ID</label>
                                <div class="layui-input-block">
                                    <input type="text" name="github_client_id" value="{{ $configs['github_client_id'] ?? '' }}"
                                           placeholder="Iv1.xxxxxxxx..." class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">GitHub OAuth App 的 Client ID，用于网页授权登录</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="github-client-secret-item">
                                <label class="layui-form-label">GitHub Client Secret</label>
                                <div class="layui-input-block">
                                    <input type="text" name="github_client_secret" value="{{ $configs['github_client_secret'] ?? '' }}"
                                           placeholder="请输入 Client Secret" class="layui-input">
                                    <div class="layui-form-mid layui-word-aux">GitHub OAuth App 的 Client Secret，用于授权后换取用户信息</div>
                                </div>
                            </div>
                            <div class="layui-form-item" id="github-callback-item">
                                <label class="layui-form-label"></label>
                                <div class="layui-input-block">
                                    <div style="padding: 8px 12px; border-left: 3px solid #12b7f5; background: #e6f7ff; color: #05506b; font-size: 13px; line-height: 1.8;">
                                        <strong>回调地址：</strong>请在 GitHub OAuth App 的「Authorization callback URL」中填入本站 GitHub 登录回调地址。<br>
                                        <strong>子域名模式：</strong><code>http://{绑定域名}/auth/github/callback</code><br>
                                        <strong>路径模式：</strong><code>http://{当前域名}/{访问路径}/auth/github/callback</code>（默认路径前缀为 <code>sso</code>）<br>
                                        <strong>首次登录：</strong>GitHub 授权成功后需完善资料并绑定邀请码，与其他社交登录流程一致。
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- 提交按钮 --}}
                        <div style="padding: 20px 0; text-align: center;">
                            <button type="button" class="layui-btn layui-btn-lg" id="save-settings">
                                <i class="layui-icon layui-icon-ok"></i> 保存设置
                            </button>
                        </div>
                </div>
            </div>
                </form>
        </div>
    </div>
</div>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
layui.use(['form', 'jquery', 'element', 'common'], function() {
    var form = layui.form;
    var $ = layui.jquery;
    var layer = layui.layer;
    var element = layui.element;

    // CSRF Token 全局配置（所有 AJAX 自动携带）
    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
    });

    form.render();
    element.render();

    // 访问模式联动：路径前缀 / 子域名绑定，仅显示对应表单
    function toggleAccessFields(mode) {
        $('#access-domain-item').toggle(mode === 'domain');
        $('#access-path-item').toggle(mode === 'path');
    }

    form.on('select(access_mode)', function(data) {
        toggleAccessFields(data.value);
    });

    toggleAccessFields($('#access_mode').val());

    // 邀请码发放方式联动：仅显示对应设置
    var onlinepayReady = @json((bool) ($onlinepay_enabled ?? false));
    function toggleInviteFields(mode) {
        $('#invite-price-item').toggle(mode === 'online_buy');
        $('#onlinepay-status-item').toggle(mode === 'online_buy' && !onlinepayReady);
        $('#invite-url-item').toggle(mode === 'url');
        $('#invite-text-item').toggle(mode === 'text');
    }

    form.on('select(invite_grant_mode)', function(data) {
        toggleInviteFields(data.value);
    });

    toggleInviteFields($('#invite_grant_mode').val());

    // 网页授权外挂开关：动态显示/隐藏外挂地址表单
    form.on('switch(wechat_oauth_enabled)', function(data) {
        $('#wechat_oauth_url_row').toggle(data.elem.checked);
    });

    // 验证码开关：动态显示/隐藏启用位置与模式选择
    form.on('switch(captcha_enabled)', function(data) {
        $('#captcha-extra-fields').toggle(data.elem.checked);
    });

    // 快捷模式联动：按站点角色显示对应配置
    function toggleQuickModeFields(mode) {
        $('#quick-mode-server-item').toggle(mode === 'server');
        $('#relay-server-url-item, #relay-client-id-item, #relay-client-secret-item, #relay-callback-item').toggle(mode === 'client');
        // 客户端模式下本地社交登录走服务端中转，微信/QQ/支付宝/抖音/微博/GitHub 仅保留启用开关，隐藏本地凭证配置
        var isClient = (mode === 'client');
        $('#wechat-appid-item, #wechat-secret-item, #wechat-oauth-item, #qq-mode-item, #qq-appid-item, #qq-appkey-item, #qq-callback-item'
            + ', #alipay-appid-item, #alipay-private-key-item, #alipay-callback-item'
            + ', #douyin-client-key-item, #douyin-client-secret-item, #douyin-callback-item'
            + ', #weibo-app-key-item, #weibo-app-secret-item, #weibo-callback-item'
            + ', #github-client-id-item, #github-client-secret-item, #github-callback-item').toggle(!isClient);
        if (isClient) {
            $('#wechat_oauth_url_row').hide();
        } else {
            // 恢复网页授权外挂联动显隐（外挂开关关闭时隐藏）
            $('#wechat_oauth_url_row').toggle($('input[name="wechat_oauth_enabled"]').is(':checked'));
        }
    }

    form.on('select(quick_mode)', function(data) {
        toggleQuickModeFields(data.value);
    });

    toggleQuickModeFields($('#quick_mode').val());

    // 短信服务商切换：动态显示对应服务商的配置字段
    function toggleSmsProviderFields(provider) {
        $('#sms-aliyun-fields, #sms-tencent-fields, #sms-volcengine-fields').hide();
        $('#sms-' + provider + '-fields').show();
    }

    form.on('select(sms_provider)', function(data) {
        toggleSmsProviderFields(data.value);
    });

    toggleSmsProviderFields($('#sms_provider').val());

    // 短信通道切换：内置短信服务显示服务商凭证，短信发送应用显示模板选择
    function toggleSmsChannelFields(channel) {
        var isNiuren = channel === 'niuren';
        $('#sms-builtin-fields').toggle(!isNiuren);
        $('#sms-niuren-fields').toggle(isNiuren);
    }

    form.on('select(sms_channel)', function(data) {
        toggleSmsChannelFields(data.value);
    });

    toggleSmsChannelFields($('#sms_channel').val());

    // 发信方式切换：动态显示对应未就绪提示
    var systemMailConfigured = @json(mail_configured());
    var smtpmailEnabled = @json((bool)($smtpmail_enabled ?? false));
    function toggleEmailMailerWarns(mailer) {
        $('#email-mailer-system-warn').toggle(mailer === 'system' && !systemMailConfigured);
        $('#email-mailer-smtpmail-warn').toggle(mailer === 'smtpmail' && !smtpmailEnabled);
    }
    form.on('select(email_mailer)', function(data) {
        toggleEmailMailerWarns(data.value);
    });

    // 开关字段名列表（checkbox 提交时不会出现在 serialize 中，需单独收集）
    var switchFields = ['wechat_enabled', 'wechat_oauth_enabled', 'qq_enabled', 'alipay_enabled', 'douyin_enabled', 'weibo_enabled', 'github_enabled', 'allow_register', 'override_system', 'phone_login_enabled', 'email_login_enabled', 'invite_enabled', 'captcha_enabled'];

    $('#save-settings').on('click', function() {
        var formData = {};

        // 收集 input/textarea/select
        $('.layui-form input[type="text"], .layui-form input[type="password"], '
          + '.layui-form select, .layui-form textarea').each(function() {
            var name = $(this).attr('name');
            if (name) formData[name] = $(this).val();
        });

        // 单独收集开关字段，转为 '1' / '0'
        switchFields.forEach(function(field) {
            var checked = $('input[name="' + field + '"]').is(':checked');
            formData[field] = checked ? '1' : '0';
        });

        // 收集验证码启用位置多选（逗号分隔存储）
        var captchaScenes = [];
        if ($('input[name="captcha_scene_login"]').is(':checked')) captchaScenes.push('login');
        if ($('input[name="captcha_scene_register"]').is(':checked')) captchaScenes.push('register');
        formData.captcha_scenes = captchaScenes.join(',');

        var loadIdx = layer.load(2);
        $.ajax({
            url: '/admin/cmspro/sso/api/settings',
            type: 'PUT',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            success: function(res) {
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.msg('保存成功', { icon: 1, time: 1500 });
                    // 站点角色影响「客户端管理」菜单可见性，保存成功后刷新左侧菜单
                    if (top.PearAdmin && top.PearAdmin.refreshMenu) {
                        top.PearAdmin.refreshMenu();
                    }
                } else {
                    layer.msg(res.message || '保存失败', { icon: 2 });
                }
            },
            error: function(xhr) {
                layer.close(loadIdx);
                if (xhr.status === 401) {
                    layer.msg('登录已过期，请刷新页面', { icon: 2 });
                } else {
                    var msg = '网络错误，请重试';
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.message) msg = res.message;
                    } catch(e) {}
                    layer.msg(msg, { icon: 2 });
                }
            }
        });
    });
});
</script>
</body>
</html>