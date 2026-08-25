@extends('cmspro.sso::layouts.home')

@section('title', '注册账号')

@section('head')
<style>
.sso-code-group { display: flex; align-items: center; }
.sso-code-group .layui-input { flex: 1; border-top-right-radius: 0; border-bottom-right-radius: 0; }
.sso-send-btn {
    width: 110px; height: 38px; line-height: 38px;
    border-top-left-radius: 0; border-bottom-left-radius: 0;
    cursor: pointer; font-size: 13px; text-align: center; white-space: nowrap;
    background: #fff; border: 1px solid #d2d2d2; color: #555;
}
.sso-send-btn.disabled { cursor: not-allowed; opacity: 0.6; }
</style>
@endsection

@section('content')
<div class="sso-card">
    <div class="sso-card-title">注册账号</div>

    <form class="layui-form" lay-filter="register-form">
        <div class="layui-form-item">
            <input type="text" name="username" placeholder="用户名（至少3个字符）"
                   autocomplete="username" class="layui-input" lay-verify="required">
        </div>
        <div class="layui-form-item">
            <input type="text" name="nickname" placeholder="昵称（可选）"
                   class="layui-input">
        </div>
        <div class="layui-form-item">
            <input type="email" name="email" id="emailInput" placeholder="邮箱地址"
                   autocomplete="email" class="layui-input">
        </div>
        @if($mailConfigured)
        <div class="layui-form-item">
            <div class="sso-code-group">
                <input type="text" name="email_code" placeholder="邮箱验证码" maxlength="6"
                       autocomplete="off" class="layui-input" style="letter-spacing: 4px;">
                <button type="button" id="sendCodeBtn" class="sso-send-btn">获取验证码</button>
            </div>
        </div>
        @endif
        <div class="layui-form-item">
            <input type="password" name="password" placeholder="密码（至少6个字符）"
                   autocomplete="new-password" class="layui-input" lay-verify="required">
        </div>
        <div class="layui-form-item">
            <input type="password" name="password_confirmation" placeholder="确认密码"
                   autocomplete="new-password" class="layui-input" lay-verify="required">
        </div>
        <div class="layui-form-item">
            <button type="button" class="layui-btn layui-btn-fluid" lay-submit lay-filter="register-submit"
                    style="background: #2563eb; height: 44px; font-size: 15px;">
                注 册
            </button>
        </div>
    </form>

    <div class="sso-link-row">
        <a href="{{ \App\Apps\CmsproSso\Services\ShareService::frontUrl('/login') . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : '') }}">已有账号？去登录</a>
        <span></span>
    </div>
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

    // 回车提交注册表单
    $('form[lay-filter="register-form"]').on('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            $('button[lay-filter="register-submit"]').trigger('click');
        }
    });

    // 发送注册邮箱验证码（复用系统公开接口）
    $('#sendCodeBtn').on('click', function(){
        var btn = $(this);
        if (btn.hasClass('disabled')) return;

        var email = $('#emailInput').val();
        if (!email) {
            layer.msg('请先填写邮箱地址', {icon: 2});
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            layer.msg('邮箱格式不正确', {icon: 2});
            return;
        }

        btn.text('发送中...').addClass('disabled');
        $.ajax({
            url: '/api/user/register/email-code',
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify({ email: email }),
            success: function(res){
                if (res.code === 0) {
                    layer.msg(res.message || '验证码已发送', {icon: 1});
                    var seconds = 60;
                    var timer = setInterval(function(){
                        seconds--;
                        btn.text(seconds + 's 后重发');
                        if (seconds <= 0) {
                            clearInterval(timer);
                            btn.text('获取验证码').removeClass('disabled');
                        }
                    }, 1000);
                } else {
                    layer.msg(extractError(res), {icon: 2});
                    btn.text('获取验证码').removeClass('disabled');
                }
            },
            error: function(xhr){
                layer.msg(extractError(xhr.responseJSON), {icon: 2});
                btn.text('获取验证码').removeClass('disabled');
            }
        });
    });

    // 从统一响应结构提取用户可读的错误信息
    function extractError(res) {
        if (res && res.data && typeof res.data === 'object') {
            for (var key in res.data) {
                if (Array.isArray(res.data[key]) && res.data[key].length) {
                    return res.data[key][0];
                }
                if (typeof res.data[key] === 'string' && res.data[key]) {
                    return res.data[key];
                }
            }
        }
        return (res && res.message) ? res.message : '请求失败';
    }

    form.on('submit(register-submit)', function(data){
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/register',
            type: 'POST',
            data: $.extend({}, data.field, { redirect: redirect }),
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.msg('注册成功', {icon: 1, time: 1000}, function(){
                        window.location.href = res.data.redirect || '/';
                    });
                } else {
                    layer.msg(extractError(res), {icon: 2});
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                layer.msg(extractError(xhr.responseJSON), {icon: 2});
            }
        });
        return false;
    });
});
</script>
@endsection