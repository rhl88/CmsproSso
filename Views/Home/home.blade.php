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
        <button type="button" class="layui-btn layui-btn-primary sso-bind-center-open">
            <i class="layui-icon layui-icon-link"></i> 账号绑定
        </button>
        <button type="button" class="layui-btn" id="logout-btn" style="background: #2563eb;">
            退出登录
        </button>
    </div>
</div>
@endsection

@section('script')
{{-- 绑定中心（共用组件：渲染弹层与脚本，入口为上方 .sso-bind-center-open 按钮；须在 layui.js 加载后的 script 区引入） --}}
@include('cmspro.sso::Home.partials.bind-center')
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