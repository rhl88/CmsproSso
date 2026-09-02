@extends('cmspro.sso::layouts.home')

@section('title', '填写邀请码')

@section('content')
<div class="sso-card">
    <div class="sso-card-title">填写邀请码</div>

    <p style="color: #666; font-size: 14px; margin-bottom: 20px; text-align: center;">
        本站开启邀请注册，请输入邀请码完成登录
    </p>

    <div class="layui-form-item">
        <input type="text" id="sso-invite-input" placeholder="请输入邀请码"
               maxlength="32" class="layui-input" autocomplete="off" style="height: 44px; font-size: 15px;">
    </div>
    <div class="layui-form-item">
        <button type="button" class="layui-btn layui-btn-fluid" id="sso-invite-submit"
                style="background: #2563eb; height: 44px; font-size: 15px;">
            完成登录
        </button>
    </div>

    <div class="sso-tip" id="invite-error" style="display: none; color: #ff5722;"></div>
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

    var token = @json($token);
    // 平台标识（wechat/qq），决定提交邀请码调用的接口
    var platform = @json($platform ?? 'wechat');
    // 登录页携带的跳转地址（提交邀请码成功后继续透传）
    var redirect = @json($redirect ?? '');

    function submitInvite() {
        var code = $.trim($('#sso-invite-input').val());
        if (code === '') {
            showMsg('请输入邀请码');
            return;
        }

        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/' + platform + '/invite',
            type: 'POST',
            data: { token: token, invite_code: code.toUpperCase(), redirect: redirect },
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.msg('登录成功', {icon: 1, time: 1000}, function(){
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
                // 会话过期时提示重新扫码，其他错误允许重新输入
                showMsg(msg);
            }
        });
    }

    function showMsg(msg) {
        var el = $('#invite-error');
        el.text(msg).show();
    }

    $('#sso-invite-submit').on('click', submitInvite);
    $('#sso-invite-input').on('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            submitInvite();
        }
    });
});
</script>
@endsection
