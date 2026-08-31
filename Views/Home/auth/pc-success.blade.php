@extends('cmspro.sso::layouts.home')

@php $pcFailed = request()->query('result') === 'failed'; @endphp

@section('title', $pcFailed ? '登录失败' : '扫码登录成功')

@section('content')
<div class="sso-card" style="text-align: center;">
    @if($pcFailed)
    <div style="font-size: 56px; color: #ff5722; margin-bottom: 16px;">
        <i class="layui-icon layui-icon-close-fill"></i>
    </div>
    <div class="sso-card-title" style="margin-bottom: 8px;">登录失败</div>
    <p style="color: #666; font-size: 14px; margin-bottom: 24px;">
        登录确认失败（如邀请码已被使用），请关闭本页面后在电脑上重新扫码登录。
    </p>
    @else
    <div style="font-size: 56px; color: #07c160; margin-bottom: 16px;">
        <i class="layui-icon layui-icon-ok-circle"></i>
    </div>
    <div class="sso-card-title" style="margin-bottom: 8px;">扫码登录成功</div>
    <p style="color: #666; font-size: 14px; margin-bottom: 24px;">
        手机端已确认登录，本页面将自动关闭，请在电脑上查看登录结果...
    </p>
    @endif
    <div class="sso-tip" id="close-fallback" style="display: none;">
        若本页面未自动关闭，请手动返回微信。
        <a href="{{ \App\Apps\CmsproSso\Services\ShareService::frontUrl('/home') }}" style="color: #2563eb;">在手机上继续访问</a>
    </div>
</div>
@endsection

@section('script')
<script>
layui.use([], function(){
    @if(!$pcFailed)
    // 关闭当前窗口：微信内优先使用 WeixinJSBridge，普通环境回退 window.close()
    function closeWindow() {
        if (typeof WeixinJSBridge !== 'undefined' && WeixinJSBridge.call) {
            WeixinJSBridge.call('closeWindow');
            return;
        }
        document.addEventListener('WeixinJSBridgeReady', function(){
            WeixinJSBridge.call('closeWindow');
        }, false);
        window.close();
    }

    // 留出 1.2 秒展示成功提示后自动关闭
    setTimeout(closeWindow, 1200);
    @endif

    // 兜底：3 秒后页面仍未关闭（失败态/非微信环境/关闭被拦截）时显示手动提示
    setTimeout(function(){
        var el = document.getElementById('close-fallback');
        if (el) el.style.display = 'block';
    }, 3000);
});
</script>
@endsection
