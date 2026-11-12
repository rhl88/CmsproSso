{{--
    绑定中心共用组件：为任意已登录前台页面提供「账号绑定」能力，行为与前台登录后首页一致。

    用法（两个要点）：
      1. 在页面放一个带 class="sso-bind-center-open" 的元素作为入口按钮/链接；
      2. 在页面 layui.js 与 jQuery 均已加载之后 @include('cmspro.sso::Home.partials.bind-center')。

    内嵌模式：若页面存在 class="sso-bind-center-inline" 的容器（如框架账号页「账号绑定」Tab），
    则绑定中心 DOM 会被移入该容器直接展示并自动加载列表，此时无需 .sso-bind-center-open 入口。

    自包含：本组件渲染绑定中心弹层 DOM 与独立脚本（layui.use 独立回调，事件委托到 document），
    不依赖宿主页脚本；若宿主未定义 window.SSO_API_BASE，则默认 /api/cmspro/sso。
    不含退出登录逻辑。

    平台图标与前台登录页保持一致，统一使用应用自带 PNG 图标（public/apps/cmspro.sso/icon/），
    不再使用 layui 字体图标（避免支付宝/抖音/微博等平台错误借用 QQ 图标）。
--}}
<div id="sso-bind-center-modal" style="display: none; padding: 20px 24px;">
    <div id="sso-bind-center-list"></div>
</div>
<script>
layui.use(['jquery', 'layer'], function(){
    var $ = layui.jquery;
    var layer = layui.layer;

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
    });

    if (!window.SSO_API_BASE) {
        window.SSO_API_BASE = '/api/cmspro/sso';
    }

    var bindModalIdx = null;

    // 平台图标基路径（与登录页 sso-oauth-icon-btn 使用同一组图标）
    var ICON_BASE = '{{ asset('apps/cmspro.sso/icon') }}';

    // 绑定中心：平台元信息（icon 为 icon 目录下图片文件名，field 为已绑定状态下应展示的详情字段）
    var BIND_PLATFORMS = {
        phone:  { label: '手机号', icon: 'phone.png',  field: 'phone' },
        email:  { label: '邮箱',   icon: 'email.png',  field: 'email' },
        wechat: { label: '微信',   icon: 'wechat.png', field: 'nickname' },
        qq:     { label: 'QQ',     icon: 'qq.png',     field: 'nickname' },
        alipay: { label: '支付宝', icon: 'alipay.png', field: 'nickname' },
        douyin: { label: '抖音',   icon: 'douyin.png', field: 'nickname' },
        weibo:  { label: '微博',   icon: 'weibo.png',  field: 'nickname' },
        github: { label: 'GitHub', icon: 'github.png', field: 'nickname' }
    };

    // 入口：任意带 .sso-bind-center-open 的元素点击打开绑定中心（事件委托，兼容动态渲染）
    $(document).on('click', '.sso-bind-center-open', function(){
        openBindCenter();
    });

    function openBindCenter(){
        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/user/bindings',
            type: 'GET',
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    renderBindList(res.data);
                    bindModalIdx = layer.open({
                        type: 1,
                        title: '账号绑定',
                        area: ['420px', '460px'],
                        content: $('#sso-bind-center-modal')
                    });
                } else {
                    layer.msg(res.message || '查询失败', {icon: 2});
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                var msg = '请求失败';
                try { var res = JSON.parse(xhr.responseText); if (res.message) msg = res.message; } catch(e) {}
                layer.msg(msg, {icon: 2});
            }
        });
    }

    // 渲染绑定列表
    function renderBindList(data){
        var html = '';
        (data.platforms || []).forEach(function(platform){
            var meta = BIND_PLATFORMS[platform] || {label: platform, icon: ''};
            var item = data[platform] || {};
            var bound = !!item.bound;

            // 图标：与登录页一致的平台 PNG 图片；未知平台无图标则留空
            var iconHtml = meta.icon
                ? '<img src="' + ICON_BASE + '/' + meta.icon + '" alt="' + meta.label + '" style="width:22px; height:22px; object-fit:contain; margin-right:12px;">'
                : '<span style="width:26px; margin-right:12px;"></span>';

            // 已绑定：展示绑定详情（邮箱/手机号/微信名/QQ名），按平台对应的详情字段取值
            var field = meta.field || '';
            var detail = (bound && item[field]) ? '（' + escapeHtml(item[field]) + '）' : '';
            var statusHtml = bound
                ? '<span style="color:#28a745; font-size:13px;">已绑定' + detail + '</span>'
                : '<span style="color:#999; font-size:13px;">未绑定</span>';

            var actionHtml = bound
                ? '<a href="javascript:;" data-action="unbind" data-platform="' + platform + '"'
                    + (item.locked ? ' style="color:#ccc; cursor:not-allowed;" title="仅剩此一种登录方式，不可解绑"' : ' style="color:#dc3545;"')
                    + '>解绑</a>'
                : '<a href="javascript:;" data-action="bind" data-platform="' + platform + '" style="color:#2563eb;">绑定</a>';

            html += '<div style="display:flex; align-items:center; padding:12px 0; border-bottom:1px solid #f0f0f0;">'
                + iconHtml
                + '<div style="flex:1; text-align:left;">'
                + '<div style="font-size:14px; color:#333;">' + meta.label + '</div>'
                + '<div>' + statusHtml + '</div>'
                + '</div>'
                + actionHtml
                + '</div>';
        });
        $('#sso-bind-center-list').html(html);
    }

    // HTML 转义（防止第三方昵称注入）
    function escapeHtml(str){
        return String(str).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    // 绑定/解绑按钮点击（事件委托，支持列表重绘）
    $('#sso-bind-center-list').on('click', 'a[data-action]', function(){
        var action = $(this).data('action');
        var platform = $(this).data('platform');
        if (action === 'unbind') {
            doUnbind(platform);
        } else {
            doBind(platform);
        }
    });

    // 解绑
    function doUnbind(platform){
        var label = (BIND_PLATFORMS[platform] || {}).label || platform;
        layer.confirm('确定解绑' + label + '吗？解绑后无法使用该方式登录。', {icon: 3, title: '解绑确认'}, function(idx){
            layer.close(idx);
            var loadIdx = layer.load(2);
            $.ajax({
                url: window.SSO_API_BASE + '/user/unbind/' + platform,
                type: 'POST',
                success: function(res){
                    layer.close(loadIdx);
                    if (res.code === 0) {
                        layer.msg(res.message || '解绑成功', {icon: 1});
                        refreshBindList();
                    } else {
                        layer.msg(res.message || '解绑失败', {icon: 2});
                    }
                },
                error: function(xhr){
                    layer.close(loadIdx);
                    var msg = '请求失败';
                    try { var res = JSON.parse(xhr.responseText); if (res.message) msg = res.message; } catch(e) {}
                    layer.msg(msg, {icon: 2});
                }
            });
        });
    }

    // 绑定：手机走验证码弹窗，微信/QQ 走授权（PC 弹二维码、移动端跳转授权页）
    function doBind(platform){
        if (platform === 'phone') {
            openPhoneBindModal();
            return;
        }

        // 移动端（手机浏览器/微信内）走跳转授权；PC 端弹二维码扫码绑定
        var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

        var loadIdx = layer.load(2);
        $.ajax({
            url: window.SSO_API_BASE + '/auth/' + platform + '/bind-url',
            type: 'GET',
            data: { device: isMobile ? '' : 'pc' },
            success: function(res){
                layer.close(loadIdx);
                if (res.code === 0) {
                    if (bindModalIdx !== null) {
                        layer.close(bindModalIdx);
                        bindModalIdx = null;
                    }
                    // 展示形态由后端 display 决策：qrcode=内嵌二维码扫码，redirect=跳转授权
                    // （QQ 依后台 qq_mode 配置，微信 PC 固定扫码）
                    if (res.data.display === 'qrcode') {
                        openBindQrModal(res.data.url, res.data.state, platform);
                    } else {
                        window.location.href = res.data.url;
                    }
                } else {
                    layer.msg(res.message || '获取授权链接失败', {icon: 2});
                }
            },
            error: function(xhr){
                layer.close(loadIdx);
                var msg = '请求失败';
                try { var res = JSON.parse(xhr.responseText); if (res.message) msg = res.message; } catch(e) {}
                layer.msg(msg, {icon: 2});
            }
        });
    }

    // 微信/QQ 扫码绑定：弹出二维码层并轮询绑定状态
    function openBindQrModal(url, state, platform){
        platform = platform || 'wechat';
        var label = (BIND_PLATFORMS[platform] || {}).label || platform;
        var qrImg = window.SSO_API_BASE + '/qrcode?url=' + encodeURIComponent(url) + '&size=240';

        var idx = layer.open({
            type: 1,
            title: label + '扫码绑定',
            area: ['300px', '380px'],
            content: '<div style="padding: 20px; text-align: center;">'
                + '<img src="' + qrImg + '" style="width: 240px; height: 240px;" alt="' + label + '扫码绑定">'
                + '<p style="margin-top: 12px; color: #666; font-size: 13px;">请使用' + label + '扫一扫绑定</p>'
                + '</div>',
            closeBtn: 1,
            end: function(){
                clearInterval(pollTimer);
            }
        });

        // 每 2 秒轮询绑定状态，扫码确认后关闭弹层并刷新绑定列表
        var pollTimer = setInterval(function(){
            $.ajax({
                url: window.SSO_API_BASE + '/auth/' + platform + '/bind-status',
                type: 'GET',
                data: { state: state },
                success: function(res){
                    if (res.code === 0 && res.data.bound) {
                        clearInterval(pollTimer);
                        layer.close(idx);
                        layer.msg('绑定成功', {icon: 1});
                        refreshBindList();
                    }
                },
                error: function(xhr){
                    // 扫码确认后绑定失败（如 openid 已绑定其他账号）：停止轮询并提示
                    if (xhr.status === 422) {
                        clearInterval(pollTimer);
                        layer.close(idx);
                        var msg = '绑定失败';
                        try { var res = JSON.parse(xhr.responseText); if (res.message) msg = res.message; } catch(e) {}
                        layer.msg(msg, {icon: 2, time: 2500});
                        refreshBindList();
                    }
                }
            });
        }, 2000);
    }

    // 刷新绑定列表（解绑/绑定成功后调用）
    function refreshBindList(){
        $.ajax({
            url: window.SSO_API_BASE + '/user/bindings',
            type: 'GET',
            success: function(res){
                if (res.code === 0) {
                    renderBindList(res.data);
                }
            }
        });
    }

    // 内嵌模式：宿主页面存在 .sso-bind-center-inline 容器时，把绑定中心 DOM 内嵌到该容器中直接展示，
    // 并立即加载绑定列表；绑定手机、扫码绑定等二级交互仍复用上方弹层逻辑。
    var inlineWrap = $('.sso-bind-center-inline');
    if (inlineWrap.length) {
        $('#sso-bind-center-modal').appendTo(inlineWrap).css({ display: 'block', padding: '0' });
        refreshBindList();
    }

    // 手机验证码绑定弹窗
    function openPhoneBindModal(){
        var content = '<form class="layui-form" style="padding:20px 24px 0;">'
            + '<div class="layui-form-item">'
            + '<input type="text" id="sso-bind-phone" placeholder="请输入手机号" maxlength="11" class="layui-input" autocomplete="off">'
            + '</div>'
            + '<div class="layui-form-item" style="position: relative; margin-bottom: 5px;">'
            + '<input type="text" id="sso-bind-code" placeholder="请输入验证码" maxlength="6" class="layui-input" style="padding-right: 100px;" autocomplete="off">'
            + '<button type="button" class="layui-btn" id="sso-bind-send-code" style="position:absolute; right:0; top:0; height:38px; line-height:38px; padding:0 12px;">获取验证码</button>'
            + '</div>'
            + '</form>';

        var idx = layer.open({
            type: 1,
            title: '绑定手机号',
            area: ['360px', '200px'],
            content: content,
            btn: ['确认绑定'],
            yes: function(btnIdx){
                var phone = $.trim($('#sso-bind-phone').val());
                var code = $.trim($('#sso-bind-code').val());
                if (!/^1\d{10}$/.test(phone)) {
                    layer.msg('请输入正确的手机号', {icon: 2});
                    return;
                }
                if (code.length !== 6) {
                    layer.msg('请输入6位验证码', {icon: 2});
                    return;
                }

                var loadIdx = layer.load(2);
                $.ajax({
                    url: window.SSO_API_BASE + '/user/bind/phone',
                    type: 'POST',
                    data: { phone: phone, code: code },
                    success: function(res){
                        layer.close(loadIdx);
                        if (res.code === 0) {
                            layer.close(btnIdx);
                            layer.msg(res.message || '绑定成功', {icon: 1});
                            refreshBindList();
                        } else {
                            layer.msg(res.message || '绑定失败', {icon: 2});
                        }
                    },
                    error: function(xhr){
                        layer.close(loadIdx);
                        var msg = '请求失败';
                        try { var res = JSON.parse(xhr.responseText); if (res.message) msg = res.message; } catch(e) {}
                        layer.msg(msg, {icon: 2});
                    }
                });
            }
        });

        // 发送验证码（含 60 秒倒计时，复用登录发码接口）
        var countdown = 0;
        var timer = null;
        $('#sso-bind-send-code').on('click', function(){
            var phone = $.trim($('#sso-bind-phone').val());
            if (!/^1\d{10}$/.test(phone)) {
                layer.msg('请输入正确的手机号', {icon: 2});
                return;
            }
            if (countdown > 0) return;

            var loadIdx = layer.load(2);
            $.ajax({
                url: window.SSO_API_BASE + '/auth/phone/code',
                type: 'POST',
                data: { phone: phone, scene: 'bind' },
                success: function(res){
                    layer.close(loadIdx);
                    if (res.code === 0) {
                        layer.msg('验证码已发送', {icon: 1});
                        countdown = 60;
                        var $btn = $('#sso-bind-send-code');
                        $btn.text(countdown + ' 秒后重发').attr('disabled', true);
                        timer = setInterval(function(){
                            countdown--;
                            if (countdown <= 0) {
                                clearInterval(timer);
                                $btn.text('获取验证码').removeAttr('disabled');
                            } else {
                                $btn.text(countdown + ' 秒后重发');
                            }
                        }, 1000);
                    } else {
                        layer.msg(res.message || '发送失败', {icon: 2});
                    }
                },
                error: function(xhr){
                    layer.close(loadIdx);
                    var msg = '发送失败';
                    try { var res = JSON.parse(xhr.responseText); if (res.message) msg = res.message; } catch(e) {}
                    layer.msg(msg, {icon: 2});
                }
            });
        });
    }
});
</script>