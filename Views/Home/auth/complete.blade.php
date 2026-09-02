@extends('cmspro.sso::layouts.home')

@php $platform = $platform ?? 'wechat'; $label = $platform === 'qq' ? 'QQ' : '微信'; @endphp

@section('title', '完善资料')

@section('content')
<div class="sso-card">
    <div class="sso-card-title">完善资料</div>
    <p style="text-align: center; color: #666; font-size: 13px; margin-bottom: 20px;">
        首次{{ $label }}登录，请完善以下信息
    </p>

    <form class="layui-form" lay-filter="complete-form">
        <div class="layui-form-item">
            <input type="text" name="nickname" placeholder="昵称"
                   value="{{ $user->nickname ?? '' }}" class="layui-input">
        </div>
        <div class="layui-form-item">
            <input type="text" name="phone" placeholder="手机号（可选）"
                   value="{{ $user->phone ?? '' }}" class="layui-input">
        </div>
        <div class="layui-form-item">
            <button type="button" class="layui-btn layui-btn-fluid" lay-submit lay-filter="complete-submit"
                    style="background: #2563eb; height: 44px; font-size: 15px;">
                保存并进入
            </button>
        </div>
    </form>
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

    // 平台标识（wechat/qq），决定完善资料调用的接口
    var platform = @json($platform);
    // 登录页携带的跳转地址（完善资料成功后继续透传）
    var redirect = @json($redirect ?? '');

    // 回车提交完善资料表单
    $('form[lay-filter="complete-form"]').on('keydown', function(e){
        if (e.key === 'Enter') {
            e.preventDefault();
            $('button[lay-filter="complete-submit"]').trigger('click');
        }
    });

    form.on('submit(complete-submit)', function(data){
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/' + platform + '/complete',
            type: 'POST',
            data: $.extend({}, data.field, { redirect: redirect }),
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.msg('保存成功', {icon: 1, time: 1000}, function(){
                        window.location.href = res.data.redirect || '/';
                    });
                } else {
                    layer.msg(res.message || '保存失败', {icon: 2});
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