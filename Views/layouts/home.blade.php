<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '统一登录')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/layui/css/layui.css') }}">
    <style>
        :root {
            --primary: #2563eb;
            --text: #1e293b;
            --bg: #f8fafc;
            --border: #e2e8f0;
            --radius: 8px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .sso-header {
            background: #fff;
            border-bottom:  1px solid var(--border);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sso-header .brand {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap:  8px;
        }
        .sso-header .brand i { font-size: 22px; }
        .sso-nav {
            display: flex;
            align-items: center;
            gap:  4px;
        }
        .sso-nav-item {
            position: relative;
            display: flex;
            align-items: center;
            gap:  6px;
            padding: 8px 14px;
            color: var(--text);
            text-decoration: none;
            font-size: 14px;
            border-radius: var(--radius);
            transition: all 0.2s;
            white-space: nowrap;
        }
        .sso-nav-link:hover {
            background: #f1f5f9;
            color: var(--primary);
        }
        .sso-nav-item.has-children > .sso-nav-link::after {
            content: '';
            display: inline-block;
            width: 0;
            height:  0;
            margin-left: 4px;
            border-left:  4px solid transparent;
            border-right:  4px solid transparent;
            border-top:  4px solid currentColor;
        }
        .sso-nav-dropdown {
            position: absolute;
            top: 100%;
            left:  0;
            min-width: 160px;
            background: #fff;
            border:  1px solid var(--border);
            border-radius: var(--radius);
            box-shadow:  0 8px 24px rgba(30, 41, 59, 0.12);
            padding:  6px;
            display: none;
            z-index: 1000;
        }
        .sso-nav-item.has-children:hover .sso-nav-dropdown {
            display: block;
        }
        .sso-nav-dropdown .sso-nav-link {
            display: block;
            padding:  8px 12px;
            border-radius: var(--radius);
            font-size: 14px;
        }
        .sso-main {
            flex:  1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 16px;
        }
        .sso-card {
            background: #fff;
            border-radius: var(--radius);
            box-shadow:  0 4px 24px rgba(30, 41, 59, 0.08);
            width: 100%;
            max-width: 420px;
            padding: 32px;
        }
        .sso-card-title {
            font-size:  20px;
            font-weight:  600;
            text-align: center;
            margin-bottom: 24px;
        }
        .sso-footer {
            text-align: center;
            padding: 16px;
            font-size: 12px;
            color: #94a3b8;
        }
        .sso-divider {
            display: flex;
            align-items: center;
            margin:  20px 0;
            color: #94a3b8;
            font-size: 13px;
        }
        .sso-divider::before,
        .sso-divider::after {
            content: '';
            flex:  1;
            height:  1px;
            background: var(--border);
        }
        .sso-divider span { padding: 0 12px; }
        /* 圆形品牌图标按钮（图标本身自带品牌色，hover 加深阴影） */
        .sso-oauth-row {
            display: flex;
            flex-wrap: wrap;
            gap:  12px;
        }
        .sso-oauth-icon-btn {
            width:  40px;
            height:  40px;
            flex:  0 0 auto;
            border: none;
            background: transparent;
            border-radius: 50%;
            cursor: pointer;
            padding:  0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .sso-oauth-icon-btn img {
            width:  36px;
            height:  36px;
            border-radius: 50%;
            object-fit: cover;
            display: block;
        }
        .sso-oauth-icon-btn:hover {
            transform: scale(1.08);
            box-shadow:  0 2px 10px rgba(0, 0, 0, 0.15);
        }
        .sso-link-row {
            display: flex;
            justify-content: space-between;
            margin-top:  12px;
            font-size: 13px;
        }
        .sso-link-row a { color: var(--primary); text-decoration: none; }
        .sso-link-row a:hover { text-decoration: underline; }
        .sso-tip {
            text-align: center;
            font-size: 13px;
            color: #94a3b8;
            margin-top:  16px;
        }
        /* 快捷登录双 Tab（完善资料 / 账号绑定） */
        .sso-tabs {
            display: flex;
            margin-bottom:  20px;
            border-bottom:  1px solid var(--border);
        }
        .sso-tab {
            flex:  1;
            padding:  10px 0;
            text-align: center;
            font-size: 14px;
            color: #666;
            cursor: pointer;
            border-bottom:  2px solid transparent;
            margin-bottom: -1px;
            transition: all 0.2s;
        }
        .sso-tab:hover { color: var(--primary); }
        .sso-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            font-weight:  600;
        }
    </style>
    @yield('head')
</head>
<body>
<header class="sso-header">
    <nav class="sso-nav">
        @if(!empty($homeMenus))
            @foreach($homeMenus as $menu)
                @if(!empty($menu->children))
                    <div class="sso-nav-item has-children">
                        <a class="sso-nav-link" href="{{ menu_path($menu->path, $menu) }}">
                            @if(!empty($menu->icon))<i class="layui-icon {{ $menu->icon }}"></i>@endif
                            <span>{{ $menu->name }}</span>
                        </a>
                        <div class="sso-nav-dropdown">
                            @foreach($menu->children as $child)
                                <a class="sso-nav-link" href="{{ menu_path($child->path, $child) }}">{{ $child->name }}</a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <a class="sso-nav-item sso-nav-link" href="{{ menu_path($menu->path, $menu) }}">
                        @if(!empty($menu->icon))<i class="layui-icon {{ $menu->icon }}"></i>@endif
                        <span>{{ $menu->name }}</span>
                    </a>
                @endif
            @endforeach
        @endif
    </nav>
    <div class="brand">
        <i class="layui-icon layui-icon-username"></i>
        <span>@yield('brand', '统一登录')</span>
    </div>
</header>

<main class="sso-main">
    @yield('content')
</main>

<footer class="sso-footer">
    @yield('footer', '')
</footer>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script>
    // 前台页面基础路径（兼容三种访问模式，用于页面内链接）
    window.SSO_BASE = @json(\App\Apps\CmsproSso\Services\ShareService::frontUrl('/'));
    // 前台 API 基础路径（始终使用全局前缀，不随访问模式变化）
    window.SSO_API_BASE = '/api/cmspro/sso';
</script>
@yield('script')
</body>
</html>