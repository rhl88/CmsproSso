@extends('cmspro.sso::layouts.home')

@section('title', '注册账号')

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
                    layer.msg(res.message || '注册失败', {icon: 2});
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
</script>
@endsection