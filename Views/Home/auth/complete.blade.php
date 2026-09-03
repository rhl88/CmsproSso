@extends('cmspro.sso::layouts.home')

@php
    $platform = $platform ?? 'wechat';
    $label = $platform === 'qq' ? 'QQ' : ($platform === 'relay' ? '统一登录' : '微信');
    // 快捷登录新用户分流：relay 平台带 token（授权数据暂存句柄），支持「完善资料 / 账号绑定」双 Tab
    $relayToken = (string) ($token ?? '');
    $isRelay = $platform === 'relay' && $relayToken !== '';
@endphp

@section('title', $isRelay ? '快捷登录' : '完善资料')

@section('content')
<div class="sso-card">
    <div class="sso-card-title">{{ $isRelay ? '快捷登录' : '完善资料' }}</div>

    @if ($isRelay)
    {{-- 双 Tab：完善资料（默认） / 账号绑定 --}}
    <div class="sso-tabs">
        <div class="sso-tab active" data-tab="setup">完善资料</div>
        <div class="sso-tab" data-tab="bind">账号绑定</div>
    </div>

    {{-- Tab1：完善资料（新建账号） --}}
    <div class="sso-tab-pane" data-pane="setup">
        <p style="text-align: center; color: #666; font-size: 13px; margin-bottom: 20px;">
            首次快捷登录，请完善以下信息创建新账号
        </p>
        <div class="layui-form-item">
            <input type="text" id="setup-nickname" placeholder="昵称" maxlength="50"
                   value="{{ $user->nickname ?? '' }}" class="layui-input">
        </div>
        <div class="layui-form-item">
            <input type="text" id="setup-phone" placeholder="手机号（可选）" maxlength="20"
                   value="{{ $user->phone ?? '' }}" class="layui-input">
        </div>
        <div class="layui-form-item">
            <button type="button" class="layui-btn layui-btn-fluid" id="setup-submit"
                    style="background: #2563eb; height: 44px; font-size: 15px;">
                创建账号并进入
            </button>
        </div>
    </div>

    {{-- Tab2：账号绑定（登录已有账号） --}}
    <div class="sso-tab-pane" data-pane="bind" style="display: none;">
        <p style="text-align: center; color: #666; font-size: 13px; margin-bottom: 20px;">
            已有本站账号？输入账号密码绑定后登录
        </p>
        <div class="layui-form-item">
            <input type="text" id="bind-account" placeholder="用户名 / 手机号 / 邮箱" maxlength="50"
                   autocomplete="username" class="layui-input">
        </div>
        <div class="layui-form-item">
            <input type="password" id="bind-password" placeholder="密码" maxlength="64"
                   autocomplete="current-password" class="layui-input">
        </div>
        <div class="layui-form-item">
            <button type="button" class="layui-btn layui-btn-fluid" id="bind-submit"
                    style="background: #2563eb; height: 44px; font-size: 15px;">
                绑定并登录
            </button>
        </div>
    </div>

    <div class="sso-tip" id="sso-form-error" style="display: none; color: #ff5722;"></div>
    @else
    <p style="text-align: center; color: #666; font-size: 13px; margin-bottom: 20px;">
        首次{{ $label }}登录，请完善以下信息
    </p>

    <form class="layui-form" lay-filter="complete-form">
        <div class="layui-form-item">
            <input type="text" name="nickname" placeholder="昵称"
                   value="{{ $user->nickname ?? '' }}" class="layui-input">
        </div>
        <div class="layui-form-item">
            <input type="text" name="phone" placeholder="手机号（可选）"
                   value="{{ $user->phone ?? '' }}" class="layui-input">
        </div>
        <div class="layui-form-item">
            <button type="button" class="layui-btn layui-btn-fluid" lay-submit lay-filter="complete-submit"
                    style="background: #2563eb; height: 44px; font-size: 15px;">
                保存并进入
            </button>
        </div>
    </form>
    @endif
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

    // 平台标识（wechat/qq/relay），决定调用的接口
    var platform = @json($platform);
    // 登录页携带的跳转地址（成功后继续透传）
    var redirect = @json($redirect ?? '');

    function showMsg(msg) {
        var el = $('#sso-form-error');
        el.text(msg).show();
    }
    function hideMsg() {
        $('#sso-form-error').hide();
    }

    @if ($isRelay)
    // ------------------------------------------------------------------
    // 快捷登录分流：完善资料（新建账号）/ 账号绑定（登录已有账号）
    // ------------------------------------------------------------------
    var relayToken = @json($relayToken);

    // Tab 切换
    $('.sso-tab').on('click', function(){
        var tab = $(this).data('tab');
        $('.sso-tab').removeClass('active');
        $(this).addClass('active');
        $('.sso-tab-pane').hide();
        $('.sso-tab-pane[data-pane="' + tab + '"]').show();
        hideMsg();
    });

    // Tab1：创建账号
    $('#setup-submit').on('click', function(){
        hideMsg();
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/relay/setup',
            type: 'POST',
            data: {
                token: relayToken,
                nickname: $.trim($('#setup-nickname').val()),
                phone: $.trim($('#setup-phone').val()),
                redirect: redirect
            },
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.msg('账号创建成功', {icon: 1, time: 1000}, function(){
                        window.location.href = res.data.redirect || '/';
                    });
                } else {
                    showMsg(res.message || '创建失败');
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                var msg = '请求失败';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.message) msg = res.message;
                } catch(e) {}
                showMsg(msg);
            }
        });
    });

    // Tab2：绑定已有账号
    $('#bind-submit').on('click', function(){
        hideMsg();
        var account = $.trim($('#bind-account').val());
        var password = $('#bind-password').val();
        if (account === '') { showMsg('请输入账号'); return; }
        if (password === '') { showMsg('请输入密码'); return; }

        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/relay/bind',
            type: 'POST',
            data: { token: relayToken, account: account, password: password, redirect: redirect },
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.msg('绑定成功', {icon: 1, time: 1000}, function(){
                        window.location.href = res.data.redirect || '/';
                    });
                } else {
                    showMsg(res.message || '绑定失败');
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                var msg = '请求失败';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.message) msg = res.message;
                } catch(e) {}
                showMsg(msg);
            }
        });
    });

    // 回车提交：按当前 Tab 分别触发
    $('#setup-nickname, #setup-phone').on('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); $('#setup-submit').trigger('click'); }
    });
    $('#bind-account, #bind-password').on('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); $('#bind-submit').trigger('click'); }
    });
    @else
    // ------------------------------------------------------------------
    // 微信/QQ：原有完善资料表单（lay-submit）
    // ------------------------------------------------------------------
    layui.use('form', function(){
        var form = layui.form;
        // 回车提交完善资料表单
        $('form[lay-filter="complete-form"]').on('keydown', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                $('button[lay-filter="complete-submit"]').trigger('click');
            }
        });

        form.on('submit(complete-submit)', function(data){
            var loadIdx = layer.load(2);
            $.ajax({
                url: window.SSO_API_BASE + '/auth/' + platform + '/complete',
                type: 'POST',
                data: $.extend({}, data.field, { redirect: redirect }),
                success: function(res){
                    layer.close(loadIdx);
                    if (res.code === 0) {
                        layer.msg('保存成功', {icon: 1, time: 1000}, function(){
                            window.location.href = res.data.redirect || '/';
                        });
                    } else {
                        layer.msg(res.message || '保存失败', {icon: 2});
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
    });
    @endif
});
</script>
@endsection
