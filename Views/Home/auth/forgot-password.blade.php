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
/* 找回密码通道 tab（邮箱/手机均开启时显示） */
.sso-tabs { display: flex; margin-bottom: 18px; border-bottom: 1px solid #e5e7eb; }
.sso-tab {
    flex: 1; padding: 10px 0; font-size: 14px; text-align: center; cursor: pointer;
    background: none; border: none; border-bottom: 2px solid transparent;
    color: #64748b; transition: color .15s, border-color .15s;
}
.sso-tab.active { color: #2563eb; border-bottom-color: #2563eb; font-weight: 600; }
.sso-forgot-disabled { padding: 28px 0 8px; text-align: center; color: #64748b; font-size: 14px; line-height: 1.8; }
</style>
@endsection

@section('content')
<div class="sso-card">
    <div class="sso-card-title">找回密码</div>

    @if(!$email_available && !$phone_available)
    {{-- 邮箱/手机通道均未开启 --}}
    <div class="sso-forgot-disabled">
        系统未开启找回密码功能，请联系管理员
    </div>
    <div class="sso-link-row">
        <a href="{{ \App\Apps\CmsproSso\Services\ShareService::frontUrl('/login') . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : '') }}">返回登录</a>
        <span></span>
    </div>
    @else
    {{-- 邮箱/手机均开启时以 tab 形式切换，仅开启其一则直接显示对应表单 --}}
    @if($email_available && $phone_available)
    <div class="sso-tabs">
        <button type="button" class="sso-tab active" data-panel="panel-email">邮箱找回</button>
        <button type="button" class="sso-tab" data-panel="panel-phone">手机找回</button>
    </div>
    @endif

    @if($email_available)
    {{-- 邮箱通道：发送验证码 → 重置密码（复用系统 /api/user/password/* 接口） --}}
    <div id="panel-email" class="sso-panel" @if(!$email_available) style="display:none" @endif>
        <div id="stepEmail1" class="sso-step-panel active">
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

        <div id="stepEmail2" class="sso-step-panel">
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
                    <a href="javascript:void(0)" id="backEmailStep1" style="color: #94a3b8;">返回重新输入邮箱</a>
                    <span></span>
                </div>
            </form>
        </div>
    </div>
    @endif

    @if($phone_available)
    {{-- 手机通道：发送验证码 → 重置密码（SSO 手机找回接口） --}}
    <div id="panel-phone" class="sso-panel" @if(!$phone_available || $email_available) style="display:none" @endif>
        <div id="stepPhone1" class="sso-step-panel active">
            <form class="layui-form" lay-filter="phone-send-code-form">
                <div class="layui-form-item">
                    <input type="tel" name="phone" placeholder="请输入注册手机号" maxlength="11"
                           autocomplete="tel" class="layui-input" lay-verify="required|phone">
                </div>
                <div class="layui-form-item">
                    <button type="button" class="layui-btn layui-btn-fluid" id="sendPhoneCodeBtn" lay-submit lay-filter="phone-send-code-submit"
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

        <div id="stepPhone2" class="sso-step-panel">
            <form class="layui-form" lay-filter="phone-reset-form">
                <input type="hidden" name="phone" id="resetPhone">
                <div class="sso-email-tip">
                    验证码已发送到 <span id="showPhone" style="color: #2563eb; font-weight: bold;"></span>，请查收短信
                </div>
                <div class="layui-form-item">
                    <div class="sso-code-group">
                        <input type="text" name="code" placeholder="请输入6位验证码" maxlength="6"
                               autocomplete="off" class="layui-input" style="letter-spacing: 4px;" lay-verify="required">
                        <button type="button" id="resendPhoneCodeBtn" class="sso-send-btn">重新发送</button>
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
                    <button type="button" class="layui-btn layui-btn-fluid" id="resetPhoneBtn" lay-submit lay-filter="phone-reset-submit"
                            style="background: #2563eb; height: 44px; font-size: 15px;">
                        重置密码
                    </button>
                </div>
                <div class="sso-link-row">
                    <a href="javascript:void(0)" id="backPhoneStep1" style="color: #94a3b8;">返回重新输入手机号</a>
                    <span></span>
                </div>
            </form>
        </div>
    </div>
    @endif
    @endif
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

    // 通道 tab 切换（仅邮箱/手机均开启时渲染）
    $('.sso-tab').on('click', function(){
        $('.sso-tab').removeClass('active');
        $(this).addClass('active');
        $('.sso-panel').hide();
        $('#' + $(this).data('panel')).show();
    });

    // 验证码发送按钮倒计时（60 秒）
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

    @if($email_available)
    // ------------------------------------------------------------ 邮箱通道
    // 请求邮箱验证码（复用系统公开接口；子域名模式下需请求主站域名，避免被 catch-all 拦截）
    function requestEmailCode(email, done, fail) {
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
        requestEmailCode(email, function(){
            $('#resetEmail').val(email);
            $('#showEmail').text(email);
            $('#stepEmail1').removeClass('active');
            $('#stepEmail2').addClass('active');
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
        requestEmailCode(email, function(){
            startCountdown(btn);
        }, function(){
            btn.text('重新发送').removeClass('disabled');
        });
    });

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
    $('#backEmailStep1').on('click', function(){
        $('#stepEmail2').removeClass('active');
        $('#stepEmail1').addClass('active');
    });
    @endif

    @if($phone_available)
    // ------------------------------------------------------------ 手机通道
    // 发送手机找回验证码（SSO 应用接口）
    function requestPhoneCode(phone, done, fail) {
        $.ajax({
            url: window.SSO_API_BASE + '/auth/forgot/phone/code',
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify({ phone: phone }),
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
    form.on('submit(phone-send-code-submit)', function(data){
        var phone = data.field.phone;
        var btn = $('#sendPhoneCodeBtn');
        btn.text('发送中...');
        requestPhoneCode(phone, function(){
            $('#resetPhone').val(phone);
            $('#showPhone').text(phone);
            $('#stepPhone1').removeClass('active');
            $('#stepPhone2').addClass('active');
            startCountdown($('#resendPhoneCodeBtn'));
        }, function(){
            btn.text('发送验证码');
        });
        return false;
    });

    // 步骤二：重新发送
    $('#resendPhoneCodeBtn').on('click', function(){
        var btn = $(this);
        if (btn.hasClass('disabled')) return;
        btn.text('发送中...').addClass('disabled');
        var phone = $('#resetPhone').val();
        requestPhoneCode(phone, function(){
            startCountdown(btn);
        }, function(){
            btn.text('重新发送').removeClass('disabled');
        });
    });

    // 步骤二：重置密码
    form.on('submit(phone-reset-submit)', function(data){
        var field = data.field;
        if (field.password !== field.password_confirmation) {
            layer.msg('两次密码输入不一致', {icon: 2});
            return false;
        }
        if (field.password.length < 6) {
            layer.msg('新密码至少6个字符', {icon: 2});
            return false;
        }

        var btn = $('#resetPhoneBtn');
        btn.text('提交中...');
        $.ajax({
            url: window.SSO_API_BASE + '/auth/forgot/phone/reset',
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
    $('#backPhoneStep1').on('click', function(){
        $('#stepPhone2').removeClass('active');
        $('#stepPhone1').addClass('active');
    });
    @endif
});
</script>
@endsection
