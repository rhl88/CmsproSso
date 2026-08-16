@extends('cmspro.sso::layouts.home')

@section('title', '扫码登录成功')

@section('content')
<div class="sso-card" style="text-align: center;">
    <div style="font-size: 56px; color: #07c160; margin-bottom: 16px;">
        <i class="layui-icon layui-icon-ok-circle"></i>
    </div>
    <div class="sso-card-title" style="margin-bottom: 8px;">扫码登录成功</div>
    <p style="color: #666; font-size: 14px; margin-bottom: 24px;">
        手机端已确认登录，本页面即将自动跳转...
    </p>
    <div class="sso-tip">
        <a href="{{ \App\Apps\CmsproSso\Services\ShareService::frontUrl('/login') }}" style="color: #2563eb;">返回登录页</a>
    </div>
</div>
@endsection

@section('script')
<script>
layui.use(['jquery', 'layer'], function(){
    var $ = layui.jquery;
    var layer = layui.layer;

    // 从 URL 读取 state（PC 端轮询登录状态用）
    var params = new URLSearchParams(window.location.search);
    var state = params.get('state') || '';

    if (state) {
        // 每 2 秒轮询登录状态
        var pollTimer = setInterval(function(){
            $.ajax({
                url: window.SSO_API_BASE + '/auth/wechat/login-status',
                type: 'GET',
                data: { state: state },
                success: function(res){
                    if (res.code === 0 && res.data.logged_in) {
                        clearInterval(pollTimer);
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