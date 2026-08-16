@extends('cmspro.sso::layouts.home')

@section('title', '登录后首页')

@section('content')
<div class="sso-card" style="text-align: center;">
    <div style="font-size: 48px; color: #2563eb; margin-bottom: 16px;">
        <i class="layui-icon layui-icon-username"></i>
    </div>
    <div class="sso-card-title" style="margin-bottom: 8px;">欢迎，{{ $user->nickname ?? $user->username }}</div>
    <p style="color: #666; font-size: 14px; margin-bottom: 24px;">
        您已通过统一登录成功登录当前系统
    </p>
    <div style="display: flex; gap: 12px; justify-content: center;">
        <button type="button" class="layui-btn" id="logout-btn" style="background: #2563eb;">
            退出登录
        </button>
    </div>
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