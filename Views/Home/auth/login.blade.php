@extends('cmspro.sso::layouts.home')

@section('title', $configs['login_title'])

@section('content')
<div class="sso-card">
    @if(session('error'))
    <div style="background: #fff5f5; color: #dc3545; border: 1px solid #f5c6cb; border-radius: 4px; padding: 8px 12px; margin-bottom: 16px; font-size: 13px;">
        {{ session('error') }}
    </div>
    @endif
    <div class="sso-card-title">{{ $configs['login_title'] }}</div>

    <form class="layui-form" lay-filter="login-form">
        <div class="layui-form-item">
            <input type="text" name="account" placeholder="用户名 / 邮箱 / 手机号"
                   autocomplete="username" class="layui-input" lay-verify="required">
        </div>
        <div class="layui-form-item">
            <input type="password" name="password" placeholder="密码"
                   autocomplete="current-password" class="layui-input" lay-verify="required">
        </div>
        <div class="layui-form-item">
            <button type="button" class="layui-btn layui-btn-fluid" lay-submit lay-filter="login-submit"
                    style="background: #2563eb; height: 44px; font-size: 15px;">
                登 录
            </button>
        </div>
    </form>

    @if($configs['wechat_enabled'] === '1' || $configs['qq_enabled'] === '1' || $configs['phone_login_enabled'] === '1' || $configs['email_login_enabled'] === '1')
    <div class="sso-divider"><span>其他登录方式</span></div>
    <div class="sso-oauth-row">
        @if($configs['wechat_enabled'] === '1')
        <button type="button" class="sso-wechat-btn" id="wechat-login-btn">
            <i class="layui-icon layui-icon-login-wechat"></i> 微信
        </button>
        @endif
        @if($configs['qq_enabled'] === '1')
        <button type="button" class="sso-qq-btn" id="qq-login-btn">
            <i class="sso-qq-icon"></i> QQ
        </button>
        @endif
        @if($configs['phone_login_enabled'] === '1')
        <button type="button" class="sso-phone-btn" id="phone-login-btn">
            <i class="layui-icon layui-icon-cellphone"></i> 手机
        </button>
        @endif
        @if($configs['email_login_enabled'] === '1')
        <button type="button" class="sso-email-btn" id="email-login-btn">
            <i class="layui-icon layui-icon-email"></i> 邮箱
        </button>
        @endif
    </div>
    @endif

    <div class="sso-link-row">
        @if($configs['allow_register'] === '1')
        <a href="{{ \App\Apps\CmsproSso\Services\ShareService::frontUrl('/register') . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : '') }}">注册账号</a>
        @else
        <span></span>
        @endif
        @if(\mail_configured())
        <a href="{{ \App\Apps\CmsproSso\Services\ShareService::frontUrl('/forgot-password') . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : '') }}">找回密码</a>
        @endif
    </div>

    <div class="sso-tip">登录即代表同意本站服务条款</div>
</div>
@endsection

@section('script')
<script>
layui.use(['form', 'jquery', 'layer'], function(){
    var form = layui.form;
    var $ = layui.jquery;
    var layer = layui.layer;

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
    });

    var redirect = @json($redirect);
    // 邀请注册开关（微信/手机登录收集邀请码用）
    var inviteEnabled = {{ ($configs['invite_enabled'] ?? '0') === '1' ? 'true' : 'false' }};
    // QQ 登录功能选项（web：网页授权跳转 / qrcode：PC 扫码）
    var qqMode = @json($configs['qq_mode'] ?? 'web');

    // 回车提交登录表单
    $('form[lay-filter="login-form"]').on('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            $('button[lay-filter="login-submit"]').trigger('click');
        }
    });

    // 账号密码登录
    form.on('submit(login-submit)', function(data){
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/login',
            type: 'POST',
            data: $.extend({}, data.field, { redirect: redirect }),
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.msg('登录成功', {icon: 1, time: 1000}, function(){
                        window.location.href = res.data.redirect || '/';
                    });
                } else {
                    layer.msg(res.message || '登录失败', {icon: 2});
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                var msg = '请求失败';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.message) msg = res.message;
                } catch(e) {}
                layer.msg(msg, {icon: 2});
            }
        });
        return false;
    });

    // 微信登录：先授权确认账号是否存在，仅新用户（need_invite）才提示填写邀请码
    $('#wechat-login-btn').on('click', function(){
        var isWechat = /MicroMessenger/i.test(navigator.userAgent);
        var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

        // 微信内：直接跳转授权
        if (isWechat) {
            wechatLogin('', function(url){
                window.location.href = url;
            });
            return;
        }

        // PC 端：弹出二维码扫码
        if (!isMobile) {
            wechatLogin('pc', function(url, state){
                openQrModal(url, state, 'wechat');
            });
            return;
        }

        // 手机浏览器：提示在微信内打开
        layer.open({
            type: 0,
            title: '微信登录',
            content: '请在微信中打开本页面进行登录',
            btn: ['知道了']
        });
    });

    // QQ 登录：web 模式直接跳转 QQ 授权；qrcode 模式 PC 弹二维码扫码，移动端跳转 QQ 授权
    $('#qq-login-btn').on('click', function(){
        var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
        if (qqMode === 'qrcode' && !isMobile) {
            qqLogin('pc', function(url, state){
                openQrModal(url, state, 'qq');
            });
        } else {
            qqLogin('', function(url){
                window.location.href = url;
            });
        }
    });

    // 邀请码输入弹窗（PC 扫码轮询到 need_invite 时弹出，凭 token 提交完成注册）
    function openWechatInviteModal(inviteToken, platform){
        platform = platform || 'wechat';
        layer.open({
            type: 1,
            title: '填写邀请码',
            area: ['360px', '200px'],
            content: '<div style="padding: 20px 24px 0;">'
                + '<p style="color: #666; font-size: 13px; margin-bottom: 10px;">本站开启邀请注册，请输入邀请码完成登录</p>'
                + '<input type="text" id="wechat-invite-input" placeholder="请输入邀请码" maxlength="32" class="layui-input" autocomplete="off">'
                + '</div>',
            btn: ['完成登录'],
            success: function(){
                $('#wechat-invite-input').focus();
            },
            yes: function(idx){
                var code = $.trim($('#wechat-invite-input').val());
                if (code === '') {
                    layer.msg('请输入邀请码', {icon: 2});
                    return;
                }

                var loadIdx = layer.load(2);
                $.ajax({
                    url: window.SSO_API_BASE + '/auth/' + platform + '/invite',
                    type: 'POST',
                    data: { token: inviteToken, invite_code: code.toUpperCase(), redirect: redirect },
                    success: function(res){
                        layer.close(loadIdx);
                        if (res.code === 0) {
                            layer.close(idx);
                            layer.msg('登录成功', {icon: 1, time: 1000}, function(){
                                window.location.href = res.data.redirect || '/';
                            });
                        } else {
                            layer.msg(res.message || '提交失败', {icon: 2});
                        }
                    },
                    error: function(xhr){
                        layer.close(loadIdx);
                        var msg = '请求失败';
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.message) msg = res.message;
                        } catch(e) {}
                        layer.msg(msg, {icon: 2});
                    }
                });
            }
        });
    }

    // 手机验证码登录
    $('#phone-login-btn').on('click', function(){
        openPhoneLoginModal();
    });

    // 弹出手机验证码登录弹窗
    function openPhoneLoginModal(){
        var content = '<form class="layui-form" style="padding:20px 24px 0;">'
            + '<div class="layui-form-item">'
            + '<input type="text" name="phone" id="phone-login-phone" placeholder="请输入手机号" maxlength="11" class="layui-input" autocomplete="off">'
            + '</div>'
            + '<div class="layui-form-item" style="position: relative; margin-bottom: 5px;">'
            + '<input type="text" name="code" id="phone-login-code" placeholder="请输入验证码" maxlength="6" class="layui-input" style="padding-right: 100px;" autocomplete="off">'
            + '<button type="button" class="layui-btn" id="phone-send-code" style="position:absolute; right:0; top:0; height:38px; line-height:38px; padding:0 12px;">获取验证码</button>'
            + '</div>'
            // 邀请码字段默认隐藏：仅新手机号注册（服务端返回 need_invite）时显示
            + '<div class="layui-form-item" id="phone-invite-item" style="display: none; margin-bottom: 5px;">'
            + '<input type="text" name="invite_code" id="phone-login-invite" placeholder="请输入邀请码" maxlength="32" class="layui-input" autocomplete="off">'
            + '</div>'
            + '<div id="phone-invite-tip" style="display: none; color: #ff5722; font-size: 12px; margin-bottom: 5px;">该手机号未注册，注册新账号需要邀请码</div>'
            + '</form>';

        phoneInviteModalIdx = layer.open({
            type: 1,
            title: '手机验证码登录',
            area: ['360px', '225px'],
            content: content,
            btn: ['登录'],
            yes: function(btnIndex){
                phoneLogin(btnIndex);
            }
        });

        // 发送验证码（含 60 秒倒计时）
        var countdown = 0;
        var timer = null;
        $('#phone-send-code').on('click', function(){
            var phone = $.trim($('#phone-login-phone').val());
            if (!/^1\d{10}$/.test(phone)) {
                layer.msg('请输入正确的手机号', {icon: 2});
                return;
            }
            if (countdown > 0) return;

            var loadIdx = layer.load(2);
            $.ajax({
                url: window.SSO_API_BASE + '/auth/phone/code',
                type: 'POST',
                data: {
                    phone: phone,
                    invite_code: $.trim($('#phone-login-invite').val() || '')
                },
                success: function(res){
                    layer.close(loadIdx);
                    if (res.code === 0) {
                        layer.msg('验证码已发送', {icon: 1});
                        countdown = 60;
                        var $btn = $('#phone-send-code');
                        $btn.text(countdown + ' 秒后重发').attr('disabled', true);
                        timer = setInterval(function(){
                            countdown--;
                            if (countdown <= 0) {
                                clearInterval(timer);
                                $btn.text('获取验证码').removeAttr('disabled');
                            } else {
                                $btn.text(countdown + ' 秒后重发');
                            }
                        }, 1000);
                    } else {
                        layer.msg(res.message || '发送失败', {icon: 2});
                    }
                },
                error: function(xhr){
                    layer.close(loadIdx);
                    var msg = '请求失败';
                    var needInvite = false;
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.message) msg = res.message;
                        needInvite = !!(res.data && res.data.need_invite);
                    } catch(e) {}
                    layer.msg(msg, {icon: 2});
                    // 新手机号且开启邀请注册：显示邀请码输入框，填好后重新获取验证码
                    if (needInvite) {
                        $('#phone-invite-tip').text('该手机号未注册，请先填写邀请码，再获取验证码完成注册');
                        showPhoneInviteField();
                    }
                }
            });
        });
    }

    // 邀请注册：手机登录弹窗中展开邀请码输入框（新手机号需补填邀请码后重新获取验证码）
    var phoneInviteModalIdx = 0;
    function showPhoneInviteField(){
        $('#phone-invite-item, #phone-invite-tip').show();
        if (phoneInviteModalIdx) {
            layer.style(phoneInviteModalIdx, { area: ['360px', '300px'] });
        }
        $('#phone-login-invite').focus();
    }

    // 手机验证码登录提交
    function phoneLogin(btnIndex){
        var phone = $.trim($('#phone-login-phone').val());
        var code = $.trim($('#phone-login-code').val());
        if (!/^1\d{10}$/.test(phone)) {
            layer.msg('请输入正确的手机号', {icon: 2});
            return;
        }
        if (code.length !== 6) {
            layer.msg('请输入 6 位验证码', {icon: 2});
            return;
        }

        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/phone/login',
            type: 'POST',
            data: {
                phone: phone,
                code: code,
                invite_code: $.trim($('#phone-login-invite').val() || '')
            },
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.close(btnIndex);
                    layer.msg('登录成功', {icon: 1, time: 1000}, function(){
                        window.location.href = res.data.redirect || '/';
                    });
                } else {
                    layer.msg(res.message || '登录失败', {icon: 2});
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                var msg = '请求失败';
                var needInvite = false;
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.message) msg = res.message;
                    needInvite = !!(res.data && res.data.need_invite);
                } catch(e) {}
                layer.msg(msg, {icon: 2});
                // 兜底：新手机号且开启邀请注册（正常应在获取验证码阶段拦截），显示邀请码输入框
                if (needInvite) {
                    $('#phone-invite-tip').text('该手机号未注册，请先填写邀请码，再获取验证码完成注册');
                    showPhoneInviteField();
                }
            }
        });
    }

    // 邮箱验证码登录
    $('#email-login-btn').on('click', function(){
        openEmailLoginModal();
    });

    // 弹出邮箱验证码登录弹窗
    function openEmailLoginModal(){
        var content = '<form class="layui-form" style="padding:20px 24px 0;">'
            + '<div class="layui-form-item">'
            + '<input type="email" name="email" id="email-login-email" placeholder="请输入邮箱地址" maxlength="100" class="layui-input" autocomplete="off">'
            + '</div>'
            + '<div class="layui-form-item" style="position: relative; margin-bottom: 5px;">'
            + '<input type="text" name="code" id="email-login-code" placeholder="请输入验证码" maxlength="6" class="layui-input" style="padding-right: 100px;" autocomplete="off">'
            + '<button type="button" class="layui-btn" id="email-send-code" style="position:absolute; right:0; top:0; height:38px; line-height:38px; padding:0 12px;">获取验证码</button>'
            + '</div>'
            // 邀请码字段默认隐藏：仅新邮箱注册（服务端返回 need_invite）时显示
            + '<div class="layui-form-item" id="email-invite-item" style="display: none; margin-bottom: 5px;">'
            + '<input type="text" name="invite_code" id="email-login-invite" placeholder="请输入邀请码" maxlength="32" class="layui-input" autocomplete="off">'
            + '</div>'
            + '<div id="email-invite-tip" style="display: none; color: #ff5722; font-size: 12px; margin-bottom: 5px;">该邮箱未注册，注册新账号需要邀请码</div>'
            + '</form>';

        emailInviteModalIdx = layer.open({
            type: 1,
            title: '邮箱验证码登录',
            area: ['360px', '225px'],
            content: content,
            btn: ['登录'],
            yes: function(btnIndex){
                emailLogin(btnIndex);
            }
        });

        // 发送验证码（含 60 秒倒计时）
        var countdown = 0;
        var timer = null;
        $('#email-send-code').on('click', function(){
            var email = $.trim($('#email-login-email').val());
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                layer.msg('请输入正确的邮箱地址', {icon: 2});
                return;
            }
            if (countdown > 0) return;

            var loadIdx = layer.load(2);
            $.ajax({
                url: window.SSO_API_BASE + '/auth/email/code',
                type: 'POST',
                data: {
                    email: email,
                    invite_code: $.trim($('#email-login-invite').val() || '')
                },
                success: function(res){
                    layer.close(loadIdx);
                    if (res.code === 0) {
                        layer.msg('验证码已发送，请查收邮件', {icon: 1});
                        countdown = 60;
                        var $btn = $('#email-send-code');
                        $btn.text(countdown + ' 秒后重发').attr('disabled', true);
                        timer = setInterval(function(){
                            countdown--;
                            if (countdown <= 0) {
                                clearInterval(timer);
                                $btn.text('获取验证码').removeAttr('disabled');
                            } else {
                                $btn.text(countdown + ' 秒后重发');
                            }
                        }, 1000);
                    } else {
                        layer.msg(res.message || '发送失败', {icon: 2});
                    }
                },
                error: function(xhr){
                    layer.close(loadIdx);
                    var msg = '请求失败';
                    var needInvite = false;
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.message) msg = res.message;
                        needInvite = !!(res.data && res.data.need_invite);
                    } catch(e) {}
                    layer.msg(msg, {icon: 2});
                    // 新邮箱且开启邀请注册：显示邀请码输入框，填好后重新获取验证码
                    if (needInvite) {
                        $('#email-invite-tip').text('该邮箱未注册，请先填写邀请码，再获取验证码完成注册');
                        showEmailInviteField();
                    }
                }
            });
        });
    }

    // 邀请注册：邮箱登录弹窗中展开邀请码输入框（新邮箱需补填邀请码后重新获取验证码）
    var emailInviteModalIdx = 0;
    function showEmailInviteField(){
        $('#email-invite-item, #email-invite-tip').show();
        if (emailInviteModalIdx) {
            layer.style(emailInviteModalIdx, { area: ['360px', '300px'] });
        }
        $('#email-login-invite').focus();
    }

    // 邮箱验证码登录提交
    function emailLogin(btnIndex){
        var email = $.trim($('#email-login-email').val());
        var code = $.trim($('#email-login-code').val());
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            layer.msg('请输入正确的邮箱地址', {icon: 2});
            return;
        }
        if (code.length !== 6) {
            layer.msg('请输入 6 位验证码', {icon: 2});
            return;
        }

        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/email/login',
            type: 'POST',
            data: {
                email: email,
                code: code,
                invite_code: $.trim($('#email-login-invite').val() || '')
            },
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.close(btnIndex);
                    layer.msg('登录成功', {icon: 1, time: 1000}, function(){
                        window.location.href = res.data.redirect || '/';
                    });
                } else {
                    layer.msg(res.message || '登录失败', {icon: 2});
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                var msg = '请求失败';
                var needInvite = false;
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.message) msg = res.message;
                    needInvite = !!(res.data && res.data.need_invite);
                } catch(e) {}
                layer.msg(msg, {icon: 2});
                // 兜底：新邮箱且开启邀请注册（正常应在获取验证码阶段拦截），显示邀请码输入框
                if (needInvite) {
                    $('#email-invite-tip').text('该邮箱未注册，请先填写邀请码，再获取验证码完成注册');
                    showEmailInviteField();
                }
            }
        });
    }

    // 快捷模式中转登录：点击后向服务端请求授权地址并整页跳转
    $('.sso-relay-btn').on('click', function(){
        var platform = $(this).data('platform');
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/relay/login-url',
            type: 'GET',
            data: { platform: platform, redirect: redirect },
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    window.location.href = res.data.url;
                } else {
                    layer.msg(res.message || '获取授权地址失败', {icon: 2});
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                var msg = '请求失败';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.message) msg = res.message;
                } catch(e) {}
                layer.msg(msg, {icon: 2});
            }
        });
    });

    // 获取微信授权 URL
    function wechatLogin(device, callback){
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/wechat/login-url',
            type: 'GET',
            data: { device: device, redirect: redirect },
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    callback(res.data.url, res.data.state);
                } else {
                    layer.msg(res.message || '获取微信授权失败', {icon: 2});
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                var msg = '请求失败';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.message) msg = res.message;
                } catch(e) {}
                layer.msg(msg, {icon: 2});
            }
        });
    }

    // 获取 QQ 授权 URL
    function qqLogin(device, callback){
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/qq/login-url',
            type: 'GET',
            data: { device: device, redirect: redirect },
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    callback(res.data.url, res.data.state);
                } else {
                    layer.msg(res.message || '获取QQ授权失败', {icon: 2});
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                var msg = '请求失败';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.message) msg = res.message;
                } catch(e) {}
                layer.msg(msg, {icon: 2});
            }
        });
    }

    // PC 端弹出二维码弹窗并轮询登录状态（微信/QQ 通用）
    function openQrModal(url, state, platform){
        platform = platform || 'wechat';
        var label = platform === 'qq' ? 'QQ' : '微信';
        var qrImg = window.SSO_API_BASE + '/qrcode?url=' + encodeURIComponent(url) + '&size=240';
        var idx = layer.open({
            type: 1,
            title: label + '扫码登录',
            area: ['300px', '380px'],
            content: '<div style="padding: 20px; text-align: center;">'
                + '<img src="' + qrImg + '" style="width: 240px; height: 240px;" alt="' + label + '扫码登录">'
                + '<p style="margin-top: 12px; color: #666; font-size: 13px;">请使用' + label + '扫一扫登录</p>'
                + '</div>',
            closeBtn: 1,
            end: function(){
                clearInterval(pollTimer);
            }
        });

        // 每 2 秒轮询登录状态
        var pollTimer = setInterval(function(){
            $.ajax({
                url: window.SSO_API_BASE + '/auth/' + platform + '/login-status',
                type: 'GET',
                data: { state: state },
                success: function(res){
                    if (res.code === 0 && res.data.logged_in) {
                        clearInterval(pollTimer);
                        layer.close(idx);
                        layer.msg('登录成功', {icon: 1, time: 1000}, function(){
                            window.location.href = res.data.redirect || '/';
                        });
                        return;
                    }
                    // 扫码确认后发现是新用户且开启邀请注册：停止轮询，弹窗收集邀请码
                    if (res.code === 0 && res.data.need_invite) {
                        clearInterval(pollTimer);
                        layer.close(idx);
                        openWechatInviteModal(res.data.invite_token, platform);
                    }
                },
                error: function(xhr){
                    // 扫码确认后建号失败：停止轮询并提示
                    if (xhr.status === 422) {
                        clearInterval(pollTimer);
                        layer.close(idx);
                        var msg = '登录失败';
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.message) msg = res.message;
                        } catch(e) {}
                        layer.msg(msg, {icon: 2, time: 2500});
                    }
                }
            });
        }, 2000);
    }
});
</script>
@endsection