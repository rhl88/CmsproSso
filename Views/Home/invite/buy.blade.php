@extends('cmspro.sso::layouts.home')

@section('title', '购买邀请码')

@section('head')
<style>
.sso-invite-price {
    font-size: 32px; font-weight: 700; color: #2563eb;
    text-align: center; margin: 4px 0 18px;
}
.sso-invite-price small { font-size: 14px; color: #888; font-weight: 400; margin-left: 4px; }
.sso-pay-channels { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-bottom: 18px; }
.sso-pay-channel {
    min-width: 110px; padding: 10px 14px; border: 1px solid #d2d2d2; border-radius: 6px;
    text-align: center; cursor: pointer; font-size: 14px; background: #fff;
    transition: all .15s;
}
.sso-pay-channel.active { border-color: #2563eb; color: #2563eb; background: #eff6ff; }
.sso-buy-btn { margin-top: 4px; }
.sso-qrcode-box { text-align: center; padding: 10px 0; }
#payQr { display: inline-block; min-width: 200px; min-height: 200px; }
.sso-code-result {
    font-size: 22px; letter-spacing: 2px; font-weight: 700; color: #16a34a;
    text-align: center; cursor: pointer; margin: 14px 0; word-break: break-all;
}
.sso-buy-tip { font-size: 12px; color: #999; text-align: center; margin-top: 10px; }
</style>
@endsection

@section('content')
<div class="sso-card">
    <div class="sso-card-title">购买邀请码</div>

    {{-- 第一步：选择渠道下单 --}}
    <div id="buyStep">
        <div class="sso-invite-price">¥{{ number_format($price, 2) }}<small>/ 个邀请码</small></div>
        <div class="sso-pay-channels" id="payChannels"></div>
        <div class="layui-form-item sso-buy-btn">
            <button type="button" id="buyBtn" class="layui-btn layui-btn-fluid"
                    style="background: #2563eb; height: 44px; font-size: 15px;" disabled>
                立即购买
            </button>
        </div>
        <div class="sso-buy-tip">支付成功后系统自动发放邀请码，请勿关闭页面</div>
    </div>

    {{-- 第二步：支付 --}}
    <div id="payStep" style="display:none;">
        <div class="sso-qrcode-box">
            <div id="payQr"></div>
            <div class="sso-buy-tip" id="payTip">正在创建订单...</div>
        </div>
    </div>

    {{-- 第三步：购买成功展示邀请码 --}}
    <div id="doneStep" style="display:none;">
        <div class="sso-buy-tip" style="font-size: 14px; color: #16a34a;">支付成功，您的邀请码：</div>
        <div class="sso-code-result" id="inviteCodeResult" title="点击复制"></div>
        <div class="layui-form-item">
            <button type="button" id="copyCodeBtn" class="layui-btn layui-btn-fluid"
                    style="background: #16a34a; height: 44px; font-size: 15px;">
                复制邀请码
            </button>
        </div>
        <div class="sso-buy-tip">请前往注册页填写该邀请码完成注册</div>
        <div class="layui-form-item" style="margin-top: 10px;">
            <a href="{{ \App\Apps\CmsproSso\Services\ShareService::frontUrl('/register') }}"
               class="layui-btn layui-btn-primary layui-btn-fluid" style="height: 44px; line-height: 42px;">
                去注册
            </a>
        </div>
    </div>

    <div class="sso-link-row">
        <a href="{{ \App\Apps\CmsproSso\Services\ShareService::frontUrl('/register') }}">返回注册</a>
        <span></span>
    </div>
</div>
@endsection

@section('script')
<script src="{{ asset('CmsProUi/component/qrcodejs/qrcode.min.js') }}"></script>
<script>
layui.use(['jquery', 'layer'], function(){
    var $ = layui.jquery;
    var layer = layui.layer;

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
    });

    var price = @json($price);
    var channels = [];
    var selectedChannel = '';
    var pollTimer = null;

    // 加载可用支付渠道
    $.ajax({
        url: window.SSO_API_BASE + '/invite/channels',
        type: 'GET',
        dataType: 'json',
        success: function(res){
            if (res.code === 0 && res.data.channels && res.data.channels.length) {
                channels = res.data.channels;
                renderChannels();
            } else {
                $('#payChannels').html('<div class="sso-buy-tip">暂无可用支付渠道，请联系管理员</div>');
            }
        },
        error: function(){
            $('#payChannels').html('<div class="sso-buy-tip">支付渠道加载失败，请刷新重试</div>');
        }
    });

    function renderChannels(){
        var html = '';
        $.each(channels, function(i, ch){
            html += '<div class="sso-pay-channel" data-code="' + ch.code + '">' + ch.name + '</div>';
        });
        $('#payChannels').html(html);
    }

    // 选择渠道
    $(document).on('click', '.sso-pay-channel', function(){
        $('.sso-pay-channel').removeClass('active');
        $(this).addClass('active');
        selectedChannel = $(this).data('code');
        $('#buyBtn').prop('disabled', false);
    });

    // 下单
    $('#buyBtn').on('click', function(){
        if (!selectedChannel) {
            layer.msg('请选择支付渠道', {icon: 2});
            return;
        }
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/invite/order',
            type: 'POST',
            data: { channel: selectedChannel },
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    showPay(res.data);
                } else {
                    layer.msg(res.message || '下单失败', {icon: 2});
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                layer.msg((xhr.responseJSON && xhr.responseJSON.message) || '下单失败，请稍后重试', {icon: 2});
            }
        });
    });

    // 展示支付方式：二维码 / 跳转链接
    function showPay(data){
        $('#buyStep').hide();
        $('#payStep').show();

        var qr = data.qr_code || '';
        var payUrl = data.pay_url || '';

        if (payUrl && payUrl.indexOf('<form') !== -1) {
            var formWin = window.open('', '_blank');
            if (formWin) {
                formWin.document.write(payUrl);
                formWin.document.close();
            }
            $('#payTip').text('支付页面已在新窗口打开，完成支付后请返回此页面等待发放');
        } else if (qr) {
            $('#payQr').html('');
            new QRCode(document.getElementById('payQr'), {
                text: qr,
                width: 200,
                height: 200,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
            $('#payTip').text('请扫码完成支付 ¥' + Number(data.amount).toFixed(2));
        } else if (payUrl) {
            window.open(payUrl, '_blank');
            $('#payTip').text('支付页面已在新窗口打开，完成支付后请返回此页面等待发放');
        } else {
            $('#payTip').text('未获取到支付信息，请返回重试');
            return;
        }

        startPolling(data.out_trade_no, data.query_token);
    }

    // 轮询支付状态
    function startPolling(tradeNo, token){
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(function(){
            $.ajax({
                url: window.SSO_API_BASE + '/invite/order/status',
                type: 'GET',
                dataType: 'json',
                data: { out_trade_no: tradeNo, query_token: token },
                success: function(res){
                    if (res.code === 0 && res.data.paid) {
                        clearInterval(pollTimer);
                        showDone(res.data.invite_code || '');
                    }
                }
            });
        }, 3000);
    }

    // 购买成功
    function showDone(code){
        $('#payStep').hide();
        $('#doneStep').show();
        if (code) {
            $('#inviteCodeResult').text(code).show();
            $('#copyCodeBtn').show();
        } else {
            // 极端情况下邀请码尚未发放完成，提示联系管理员
            $('#inviteCodeResult').text('邀请码发放中，请稍后刷新重试').hide();
            $('#copyCodeBtn').hide();
        }
    }

    // 复制邀请码
    function copyCode(code){
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(function(){
                layer.msg('已复制邀请码', {icon: 1, time: 1500});
            }).catch(function(){
                layer.msg('复制失败，请手动复制', {icon: 2});
            });
        } else {
            var input = document.createElement('input');
            input.value = code;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            layer.msg('已复制邀请码', {icon: 1, time: 1500});
        }
    }

    $('#copyCodeBtn').on('click', function(){
        var code = $.trim($('#inviteCodeResult').text());
        if (code) copyCode(code);
    });
    $('#inviteCodeResult').on('click', function(){
        var code = $.trim($(this).text());
        if (code) copyCode(code);
    });
});
</script>
@endsection
