{{--
    前台账号页「账号绑定」Tab 面板

    由本应用 ServiceProvider 注册的 View composer 注入到系统框架账号页视图
    （resources/views/user/account/index.blade.php）的 @stack('user_account_tab_contents') 扩展位，
    与 @stack('user_account_tab_titles') 注入的「账号绑定」标题一一对应，
    使框架账号页保持为唯一视图来源（框架后续更新可正常透传）。

    面板内直接内嵌绑定列表：容器 .sso-bind-center-inline 由共用绑定中心组件
    cmspro.sso::Home.partials.bind-center（经 user_account_scripts 扩展位注入）在脚本执行时填充。
--}}
<div class="layui-tab-item">
    <div style="color: #666; font-size: 13px; margin-bottom: 14px;">
        可绑定手机号、邮箱及各快捷登录方式（微信/QQ 等），绑定后可用对应方式快捷登录。
    </div>
    <div class="sso-bind-center-inline"></div>
</div>
