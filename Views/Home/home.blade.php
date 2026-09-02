@extends('cmspro.sso::layouts.home')

@section('title', '登录后首页')

@section('content')
<div class="sso-card" style="text-align: center;">
    @if(session('success'))
    <div style="background: #f0fff4; color: #28a745; border: 1px solid #c3e6cb; border-radius: 4px; padding: 8px 12px; margin-bottom: 16px; font-size: 13px;">
        {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div style="background: #fff5f5; color: #dc3545; border: 1px solid #f5c6cb; border-radius: 4px; padding: 8px 12px; margin-bottom: 16px; font-size: 13px;">
        {{ session('error') }}
    </div>
    @endif
    <div style="font-size: 48px; color: #2563eb; margin-bottom: 16px;">
        <i class="layui-icon layui-icon-username"></i>
    </div>
    <div class="sso-card-title" style="margin-bottom: 8px;">欢迎，{{ $user->nickname ?? $user->username }}</div>
    <p style="color: #666; font-size: 14px; margin-bottom: 24px;">
        您已通过统一登录成功登录当前系统
    </p>
    <div style="display: flex; gap: 12px; justify-content: center;">
        <button type="button" class="layui-btn layui-btn-primary" id="bind-center-btn">
            <i class="layui-icon layui-icon-link"></i> 账号绑定
        </button>
        <button type="button" class="layui-btn" id="logout-btn" style="background: #2563eb;">
            退出登录
        </button>
    </div>
</div>

{{-- 绑定中心弹层 --}}
<div id="bind-center-modal" style="display: none; padding: 20px 24px;">
    <div id="bind-center-list"></div>
</div>
@endsection

@section('script')
<script>
layui.use(['jquery', 'layer'], function(){
    var $ = layui.jquery;
    var layer = layui.layer;

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
    });

    var bindModalIdx = null;

    // 绑定中心：平台元信息
    var BIND_PLATFORMS = {
        phone:  { label: '手机号', icon: 'layui-icon-cellphone',    color: '#2563eb' },
        wechat: { label: '微信',   icon: 'layui-icon-login-wechat', color: '#07c160' },
        qq:     { label: 'QQ',     icon: 'sso-qq-icon',             color: '#12b7f5' }
    };

    // 打开绑定中心弹层并加载绑定状态
    $('#bind-center-btn').on('click', function(){
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/user/bindings',
            type: 'GET',
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    renderBindList(res.data);
                    bindModalIdx = layer.open({
                        type: 1,
                        title: '账号绑定',
                        area: ['420px', '460px'],
                        content: $('#bind-center-modal')
                    });
                } else {
                    layer.msg(res.message || '查询失败', {icon: 2});
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                var msg = '请求失败';
                try { var res = JSON.parse(xhr.responseText); if (res.message) msg = res.message; } catch(e) {}
                layer.msg(msg, {icon: 2});
            }
        });
    });

    // 渲染绑定列表
    function renderBindList(data){
        var html = '';
        (data.platforms || []).forEach(function(platform){
            var meta = BIND_PLATFORMS[platform] || {label: platform, icon: '', color: '#666'};
            var item = data[platform] || {};
            var bound = !!item.bound;

            var statusHtml = bound
                ? '<span style="color:#28a745; font-size:13px;">已绑定' + (item.phone ? '（' + item.phone + '）' : (item.nickname ? '（' + escapeHtml(item.nickname) + '）' : '')) + '</span>'
                : '<span style="color:#999; font-size:13px;">未绑定</span>';

            var actionHtml = bound
                ? '<a href="javascript:;" data-action="unbind" data-platform="' + platform + '"'
                    + (item.locked ? ' style="color:#ccc; cursor:not-allowed;" title="仅剩此一种登录方式，不可解绑"' : ' style="color:#dc3545;"')
                    + '>解绑</a>'
                : '<a href="javascript:;" data-action="bind" data-platform="' + platform + '" style="color:#2563eb;">绑定</a>';

            html += '<div style="display:flex; align-items:center; padding:12px 0; border-bottom:1px solid #f0f0f0;">'
                + '<i class="layui-icon ' + meta.icon + '" style="font-size:22px; color:' + meta.color + '; margin-right:12px; width:26px; text-align:center;"></i>'
                + '<div style="flex:1; text-align:left;">'
                + '<div style="font-size:14px; color:#333;">' + meta.label + '</div>'
                + '<div>' + statusHtml + '</div>'
                + '</div>'
                + actionHtml
                + '</div>';
        });
        $('#bind-center-list').html(html);
    }

    // HTML 转义（防止第三方昵称注入）
    function escapeHtml(str){
        return String(str).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    // 绑定/解绑按钮点击（事件委托，支持列表重绘）
    $('#bind-center-list').on('click', 'a[data-action]', function(){
        var action = $(this).data('action');
        var platform = $(this).data('platform');
        if (action === 'unbind') {
            doUnbind(platform);
        } else {
            doBind(platform);
        }
    });

    // 解绑
    function doUnbind(platform){
        var label = (BIND_PLATFORMS[platform] || {}).label || platform;
        layer.confirm('确定解绑' + label + '吗？解绑后无法使用该方式登录。', {icon: 3, title: '解绑确认'}, function(idx){
            layer.close(idx);
            var loadIdx = layer.load(2);
            $.ajax({
                url: window.SSO_API_BASE + '/user/unbind/' + platform,
                type: 'POST',
                success: function(res){
                    layer.close(loadIdx);
                    if (res.code === 0) {
                        layer.msg(res.message || '解绑成功', {icon: 1});
                        refreshBindList();
                    } else {
                        layer.msg(res.message || '解绑失败', {icon: 2});
                    }
                },
                error: function(xhr){
                    layer.close(loadIdx);
                    var msg = '请求失败';
                    try { var res = JSON.parse(xhr.responseText); if (res.message) msg = res.message; } catch(e) {}
                    layer.msg(msg, {icon: 2});
                }
            });
        });
    }

    // 绑定：手机走验证码弹窗，微信/QQ 跳授权
    function doBind(platform){
        if (platform === 'phone') {
            openPhoneBindModal();
            return;
        }

        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/' + platform + '/bind-url',
            type: 'GET',
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    if (bindModalIdx !== null) {
                        layer.close(bindModalIdx);
                        bindModalIdx = null;
                    }
                    // 跳转授权（QQ：qrcode 模式下 PC 仍走网页授权页，绑定后自动回跳首页）
                    window.location.href = res.data.url;
                } else {
                    layer.msg(res.message || '获取授权链接失败', {icon: 2});
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                var msg = '请求失败';
                try { var res = JSON.parse(xhr.responseText); if (res.message) msg = res.message; } catch(e) {}
                layer.msg(msg, {icon: 2});
            }
        });
    }

    // 刷新绑定列表（解绑/绑定成功后调用）
    function refreshBindList(){
        $.ajax({
            url: window.SSO_API_BASE + '/user/bindings',
            type: 'GET',
            success: function(res){
                if (res.code === 0) {
                    renderBindList(res.data);
                }
            }
        });
    }

    // 手机验证码绑定弹窗
    function openPhoneBindModal(){
        var content = '<form class="layui-form" style="padding:20px 24px 0;">'
            + '<div class="layui-form-item">'
            + '<input type="text" id="bind-phone" placeholder="请输入手机号" maxlength="11" class="layui-input" autocomplete="off">'
            + '</div>'
            + '<div class="layui-form-item" style="position: relative; margin-bottom: 5px;">'
            + '<input type="text" id="bind-code" placeholder="请输入验证码" maxlength="6" class="layui-input" style="padding-right: 100px;" autocomplete="off">'
            + '<button type="button" class="layui-btn" id="bind-send-code" style="position:absolute; right:0; top:0; height:38px; line-height:38px; padding:0 12px;">获取验证码</button>'
            + '</div>'
            + '</form>';

        var idx = layer.open({
            type: 1,
            title: '绑定手机号',
            area: ['360px', '200px'],
            content: content,
            btn: ['确认绑定'],
            yes: function(btnIdx){
                var phone = $.trim($('#bind-phone').val());
                var code = $.trim($('#bind-code').val());
                if (!/^1\d{10}$/.test(phone)) {
                    layer.msg('请输入正确的手机号', {icon: 2});
                    return;
                }
                if (code.length !== 6) {
                    layer.msg('请输入6位验证码', {icon: 2});
                    return;
                }

                var loadIdx = layer.load(2);
                $.ajax({
                    url: window.SSO_API_BASE + '/user/bind/phone',
                    type: 'POST',
                    data: { phone: phone, code: code },
                    success: function(res){
                        layer.close(loadIdx);
                        if (res.code === 0) {
                            layer.close(btnIdx);
                            layer.msg(res.message || '绑定成功', {icon: 1});
                            refreshBindList();
                        } else {
                            layer.msg(res.message || '绑定失败', {icon: 2});
                        }
                    },
                    error: function(xhr){
                        layer.close(loadIdx);
                        var msg = '请求失败';
                        try { var res = JSON.parse(xhr.responseText); if (res.message) msg = res.message; } catch(e) {}
                        layer.msg(msg, {icon: 2});
                    }
                });
            }
        });

        // 发送验证码（含 60 秒倒计时，复用登录发码接口）
        var countdown = 0;
        var timer = null;
        $('#bind-send-code').on('click', function(){
            var phone = $.trim($('#bind-phone').val());
            if (!/^1\d{10}$/.test(phone)) {
                layer.msg('请输入正确的手机号', {icon: 2});
                return;
            }
            if (countdown > 0) return;

            var loadIdx = layer.load(2);
            $.ajax({
                url: window.SSO_API_BASE + '/auth/phone/code',
                type: 'POST',
                data: { phone: phone, scene: 'bind' },
                success: function(res){
                    layer.close(loadIdx);
                    if (res.code === 0) {
                        layer.msg('验证码已发送', {icon: 1});
                        countdown = 60;
                        var $btn = $('#bind-send-code');
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
                    var msg = '发送失败';
                    try { var res = JSON.parse(xhr.responseText); if (res.message) msg = res.message; } catch(e) {}
                    layer.msg(msg, {icon: 2});
                }
            });
        });
    }

    $('#logout-btn').on('click', function(){
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/logout',
            type: 'POST',
            success: function(res){
                layer.close(loadIdx);
                layer.msg('已退出登录', {icon: 1, time: 1000}, function(){
                    window.location.href = window.SSO_BASE + 'login';
                });
            }
        });
    });
});
</script>
@endsection