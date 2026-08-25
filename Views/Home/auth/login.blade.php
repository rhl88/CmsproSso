@extends('cmspro.sso::layouts.home')

@section('title', $configs['login_title'])

@section('content')
<div class="sso-card">
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

    @if($configs['wechat_enabled'] === '1')
    <div class="sso-divider"><span>其他登录方式</span></div>
    <button type="button" class="sso-wechat-btn" id="wechat-login-btn">
        <i class="layui-icon layui-icon-login-wechat"></i> 微信登录
    </button>
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

    // 微信登录
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
                openQrModal(url, state);
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

    // 获取微信授权 URL
    function wechatLogin(device, callback){
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/wechat/login-url',
            type: 'GET',
            data: { device: device },
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

    // PC 端弹出二维码弹窗并轮询登录状态
    function openQrModal(url, state){
        var qrImg = window.SSO_API_BASE + '/qrcode?url=' + encodeURIComponent(url) + '&size=240';
        var idx = layer.open({
            type: 1,
            title: '微信扫码登录',
            area: ['300px', '380px'],
            content: '<div style="padding: 20px; text-align: center;">'
                + '<img src="' + qrImg + '" style="width: 240px; height: 240px;" alt="微信扫码登录">'
                + '<p style="margin-top: 12px; color: #666; font-size: 13px;">请使用微信扫一扫登录</p>'
                + '</div>',
            closeBtn: 1,
            end: function(){
                clearInterval(pollTimer);
            }
        });

        // 每 2 秒轮询登录状态
        var pollTimer = setInterval(function(){
            $.ajax({
                url: window.SSO_API_BASE + '/auth/wechat/login-status',
                type: 'GET',
                data: { state: state },
                success: function(res){
                    if (res.code === 0 && res.data.logged_in) {
                        clearInterval(pollTimer);
                        layer.close(idx);
                        layer.msg('登录成功', {icon: 1, time: 1000}, function(){
                            window.location.href = res.data.redirect || '/';
                        });
                    }
                }
            });
        }, 2000);
    }
});
</script>
@endsection