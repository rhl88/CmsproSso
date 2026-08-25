@extends('cmspro.sso::layouts.home')

@section('title', '找回密码')

@section('head')
<style>
.sso-step-panel { display: none; }
.sso-step-panel.active { display: block; }
.sso-code-group { display: flex; align-items: center; }
.sso-code-group .layui-input { flex: 1; border-top-right-radius: 0; border-bottom-right-radius: 0; }
.sso-send-btn {
    width: 110px; height: 38px; line-height: 38px;
    border-top-left-radius: 0; border-bottom-left-radius: 0;
    cursor: pointer; font-size: 13px; text-align: center; white-space: nowrap;
    background: #fff; border: 1px solid #d2d2d2; color: #555;
}
.sso-send-btn.disabled { cursor: not-allowed; opacity: 0.6; }
.sso-email-tip { font-size: 13px; color: #64748b; margin-bottom: 12px; line-height: 1.6; }
</style>
@endsection

@section('content')
<div class="sso-card">
    <div class="sso-card-title">找回密码</div>

    <div id="step1" class="sso-step-panel active">
        <form class="layui-form" lay-filter="send-code-form">
            <div class="layui-form-item">
                <input type="email" name="email" placeholder="请输入注册邮箱"
                       autocomplete="email" class="layui-input" lay-verify="required|email">
            </div>
            <div class="layui-form-item">
                <button type="button" class="layui-btn layui-btn-fluid" id="sendCodeBtn" lay-submit lay-filter="send-code-submit"
                        style="background: #2563eb; height: 44px; font-size: 15px;">
                    发送验证码
                </button>
            </div>
            <div class="sso-link-row">
                <a href="{{ \App\Apps\CmsproSso\Services\ShareService::frontUrl('/login') }}">想起密码了？去登录</a>
                <span></span>
            </div>
        </form>
    </div>

    <div id="step2" class="sso-step-panel">
        <form class="layui-form" lay-filter="reset-form">
            <input type="hidden" name="email" id="resetEmail">
            <div class="sso-email-tip">
                验证码已发送到 <span id="showEmail" style="color: #2563eb; font-weight: bold;"></span>，请查收
            </div>
            <div class="layui-form-item">
                <div class="sso-code-group">
                    <input type="text" name="code" placeholder="请输入6位验证码" maxlength="6"
                           autocomplete="off" class="layui-input" style="letter-spacing: 4px;" lay-verify="required">
                    <button type="button" id="resendCodeBtn" class="sso-send-btn">重新发送</button>
                </div>
            </div>
            <div class="layui-form-item">
                <input type="password" name="password" placeholder="新密码（至少6位）"
                       autocomplete="new-password" class="layui-input" lay-verify="required">
            </div>
            <div class="layui-form-item">
                <input type="password" name="password_confirmation" placeholder="确认新密码"
                       autocomplete="new-password" class="layui-input" lay-verify="required">
            </div>
            <div class="layui-form-item">
                <button type="button" class="layui-btn layui-btn-fluid" id="resetBtn" lay-submit lay-filter="reset-submit"
                        style="background: #2563eb; height: 44px; font-size: 15px;">
                    重置密码
                </button>
            </div>
            <div class="sso-link-row">
                <a href="javascript:void(0)" id="backStep1" style="color: #94a3b8;">返回重新输入邮箱</a>
                <span></span>
            </div>
        </form>
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

    // 回车提交
    $('form').on('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            $(this).find('button[lay-submit]').trigger('click');
        }
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

    // 请求邮箱验证码（复用系统公开接口；子域名模式下需请求主站域名，避免被 catch-all 拦截）
    function requestCode(email, done, fail) {
        $.ajax({
            url: @json(main_url('api/user/password/email-code')),
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify({ email: email }),
            success: function(res){
                if (res.code === 0) {
                    layer.msg(res.message || '验证码已发送', {icon: 1});
                    done && done();
                } else {
                    layer.msg(extractError(res), {icon: 2});
                    fail && fail();
                }
            },
            error: function(xhr){
                layer.msg(extractError(xhr.responseJSON), {icon: 2});
                fail && fail();
            }
        });
    }

    // 步骤一：发送验证码
    form.on('submit(send-code-submit)', function(data){
        var email = data.field.email;
        var btn = $('#sendCodeBtn');
        btn.text('发送中...');
        requestCode(email, function(){
            $('#resetEmail').val(email);
            $('#showEmail').text(email);
            $('#step1').removeClass('active');
            $('#step2').addClass('active');
            startCountdown($('#resendCodeBtn'));
        }, function(){
            btn.text('发送验证码');
        });
        return false;
    });

    // 步骤二：重新发送
    $('#resendCodeBtn').on('click', function(){
        var btn = $(this);
        if (btn.hasClass('disabled')) return;
        btn.text('发送中...').addClass('disabled');
        var email = $('#resetEmail').val();
        requestCode(email, function(){
            startCountdown(btn);
        }, function(){
            btn.text('重新发送').removeClass('disabled');
        });
    });

    function startCountdown(btn) {
        var seconds = 60;
        btn.addClass('disabled');
        var timer = setInterval(function(){
            seconds--;
            btn.text(seconds + 's 后重发');
            if (seconds <= 0) {
                clearInterval(timer);
                btn.text('重新发送').removeClass('disabled');
            }
        }, 1000);
    }

    // 步骤二：重置密码
    form.on('submit(reset-submit)', function(data){
        var field = data.field;
        if (field.password !== field.password_confirmation) {
            layer.msg('两次密码输入不一致', {icon: 2});
            return false;
        }
        if (field.password.length < 6) {
            layer.msg('新密码至少6个字符', {icon: 2});
            return false;
        }

        var btn = $('#resetBtn');
        btn.text('提交中...');
        $.ajax({
            url: @json(main_url('api/user/password/reset-by-code')),
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify(field),
            success: function(res){
                if (res.code === 0) {
                    layer.msg('密码重置成功', {icon: 1, time: 1200}, function(){
                        window.location.href = @json(\App\Apps\CmsproSso\Services\ShareService::frontUrl('/login'));
                    });
                } else {
                    layer.msg(extractError(res), {icon: 2});
                    btn.text('重置密码');
                }
            },
            error: function(xhr){
                layer.msg(extractError(xhr.responseJSON), {icon: 2});
                btn.text('重置密码');
            }
        });
        return false;
    });

    // 返回步骤一
    $('#backStep1').on('click', function(){
        $('#step2').removeClass('active');
        $('#step1').addClass('active');
    });
});
</script>
@endsection
