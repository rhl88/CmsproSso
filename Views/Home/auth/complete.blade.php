@extends('cmspro.sso::layouts.home')

@php
    $platform = $platform ?? 'wechat';
    $label = $platform === 'qq' ? 'QQ' : ($platform === 'relay' ? '统一登录' : '微信');
    $token = (string) ($token ?? '');
    // token 分流模式：新用户未登录，凭 token 完成「完善资料（创建账号）」或「账号绑定」（relay 与独立站社交平台统一）
    $isTokenFlow = $token !== '';
    $isRelay = $platform === 'relay';
    // 昵称回填：无本地账号，取服务端授权昵称
    $nickname = $isTokenFlow ? (string) ($oauthNickname ?? '') : '';
    // 双 Tab 提交接口：relay 凭 token → setup/bind；独立站社交平台凭 token → complete/bind-existing
    $setupUrl = $isRelay ? '/auth/relay/setup' : '/auth/' . $platform . '/complete';
    $bindUrl = $isRelay ? '/auth/relay/bind' : '/auth/' . $platform . '/bind-existing';
@endphp

@section('title', $isRelay ? '快捷登录' : '完善资料')

@section('content')
<div class="sso-card">
    <div class="sso-card-title">{{ $isRelay ? '快捷登录' : '完善资料' }}</div>

    <p style="text-align: center; color: #666; font-size: 13px; margin-bottom: 20px;">
        首次{{ $label }}登录，请完善以下信息创建新账号，或绑定已有账号
    </p>

    {{-- 双 Tab：完善资料（默认） / 账号绑定 --}}
    <div class="sso-tabs">
        <div class="sso-tab active" data-tab="setup">完善资料</div>
        <div class="sso-tab" data-tab="bind">账号绑定</div>
    </div>

    {{-- Tab1：完善资料（新建账号） --}}
    <div class="sso-tab-pane" data-pane="setup">
        <div class="layui-form-item">
            <input type="text" id="setup-nickname" placeholder="昵称" maxlength="50"
                   value="{{ $nickname }}" class="layui-input">
        </div>
        <div class="layui-form-item">
            <input type="text" id="setup-phone" placeholder="手机号（可选）" maxlength="20"
                   value="{{ $phone ?? '' }}" class="layui-input">
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

    // 平台标识（wechat/qq/alipay/douyin/weibo/github/relay），决定调用的接口
    var platform = @json($platform);
    // token 分流模式（新用户未登录，凭 token 建号/绑定）
    var isTokenFlow = @json($isTokenFlow ? true : false);
    // 提交接口（后端 render 时按平台差异计算，确保权限正确）
    var setupUrl = @json($setupUrl);
    var bindUrl = @json($bindUrl);
    // 授权数据暂存句柄（分流页必带）
    var oauthToken = @json($token);
    // 登录页携带的跳转地址（成功后继续透传）
    var redirect = @json($redirect ?? '');

    function payload(fields) {
        var data = $.extend({}, fields, { redirect: redirect });
        // 分流模式所有提交均需携带 token
        if (isTokenFlow) {
            data.token = oauthToken;
        }
        return data;
    }

    function showMsg(msg) {
        var el = $('#sso-form-error');
        el.text(msg).show();
    }
    function hideMsg() {
        $('#sso-form-error').hide();
    }

    // Tab 切换
    $('.sso-tab').on('click', function(){
        var tab = $(this).data('tab');
        $('.sso-tab').removeClass('active');
        $(this).addClass('active');
        $('.sso-tab-pane').hide();
        $('.sso-tab-pane[data-pane="' + tab + '"]').show();
        hideMsg();
    });

    // Tab1：完善资料（凭 token 新建账号）
    $('#setup-submit').on('click', function(){
        hideMsg();
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + setupUrl,
            type: 'POST',
            data: payload({
                nickname: $.trim($('#setup-nickname').val()),
                phone: $.trim($('#setup-phone').val())
            }),
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.msg(res.message || '保存成功', {icon: 1, time: 1000}, function(){
                        window.location.href = res.data.redirect || '/';
                    });
                } else {
                    showMsg(res.message || '提交失败');
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

    // Tab2：账号绑定（登录已有账号）
    $('#bind-submit').on('click', function(){
        hideMsg();
        var account = $.trim($('#bind-account').val());
        var password = $('#bind-password').val();
        if (account === '') { showMsg('请输入账号'); return; }
        if (password === '') { showMsg('请输入密码'); return; }

        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + bindUrl,
            type: 'POST',
            data: payload({ account: account, password: password }),
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.msg(res.message || '绑定成功', {icon: 1, time: 1000}, function(){
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
});
</script>
@endsection