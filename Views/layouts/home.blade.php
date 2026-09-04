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
            border-bottom: 1px solid var(--border);
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
            gap: 8px;
        }
        .sso-header .brand i { font-size: 22px; }
        .sso-nav {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .sso-nav-item {
            position: relative;
            display: flex;
            align-items: center;
            gap: 6px;
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
            height: 0;
            margin-left: 4px;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-top: 4px solid currentColor;
        }
        .sso-nav-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            min-width: 160px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 8px 24px rgba(30, 41, 59, 0.12);
            padding: 6px;
            display: none;
            z-index: 1000;
        }
        .sso-nav-item.has-children:hover .sso-nav-dropdown {
            display: block;
        }
        .sso-nav-dropdown .sso-nav-link {
            display: block;
            padding: 8px 12px;
            border-radius: var(--radius);
            font-size: 14px;
        }
        .sso-main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 16px;
        }
        .sso-card {
            background: #fff;
            border-radius: var(--radius);
            box-shadow: 0 4px 24px rgba(30, 41, 59, 0.08);
            width: 100%;
            max-width: 420px;
            padding: 32px;
        }
        .sso-card-title {
            font-size: 20px;
            font-weight: 600;
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
            margin: 20px 0;
            color: #94a3b8;
            font-size: 13px;
        }
        .sso-divider::before,
        .sso-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }
        .sso-divider span { padding: 0 12px; }
        .sso-wechat-btn {
            width: 100%;
            height: 44px;
            border: 1px solid #07c160;
            color: #07c160;
            background: #fff;
            border-radius: var(--radius);
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .sso-wechat-btn:hover {
            background: #07c160;
            color: #fff;
        }
        .sso-wechat-btn i { font-size: 20px; }
        .sso-oauth-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        /* 每行最多 4 个登录按钮，超出自动换行；不足一行时按钮自动拉伸填满整行 */
        .sso-oauth-row > .sso-wechat-btn,
        .sso-oauth-row > .sso-qq-btn,
        .sso-oauth-row > .sso-phone-btn,
        .sso-oauth-row > .sso-email-btn,
        .sso-oauth-row > .sso-social-btn {
            flex: 1 1 calc(25% - 9px);
            width: auto;
            box-sizing: border-box;
        }
        .sso-phone-btn {
            flex: 1;
            height: 44px;
            border: 1px solid #2563eb;
            color: #2563eb;
            background: #fff;
            border-radius: var(--radius);
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .sso-phone-btn:hover {
            background: #2563eb;
            color: #fff;
        }
        .sso-phone-btn i { font-size: 20px; }
        .sso-qq-btn {
            flex: 1;
            height: 44px;
            border: 1px solid #12b7f5;
            color: #12b7f5;
            background: #fff;
            border-radius: var(--radius);
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .sso-qq-btn:hover {
            background: #12b7f5;
            color: #fff;
        }
        /* 真实 QQ 企鹅图标（官方 PNG，base64 内嵌本地化） */
        .sso-qq-icon {
            width: 20px;
            height: 20px;
            display: inline-block;
            background: url("data:image/svg+xml,%3csvg%20width='28'%20height='28'%20viewBox='0%200%2028%2028'%20fill='none'%20xmlns='http://www.w3.org/2000/svg'%3e%3cpath%20d='M14.4932%202.46387C16.916%202.6009%2018.7017%203.74896%2019.8652%205.4209C21.0745%207.15877%2021.5762%209.40181%2021.5762%2011.5527C21.5762%2011.7004%2021.57%2011.9224%2021.5664%2012.0879L22.4258%2014.2412L22.4316%2014.2549C22.6911%2014.9349%2022.953%2015.6598%2023.1484%2016.292C23.6099%2017.7845%2023.7849%2018.8975%2023.7988%2019.6826C23.8057%2020.0694%2023.7746%2020.4178%2023.6953%2020.707C23.6568%2020.8475%2023.5932%2021.0238%2023.4814%2021.1914C23.3905%2021.3279%2023.1326%2021.6584%2022.6523%2021.7168C22.199%2021.7719%2021.853%2021.554%2021.7275%2021.4697C21.5583%2021.356%2021.4077%2021.2163%2021.2881%2021.0928C21.2541%2021.0576%2021.2215%2021.0198%2021.1875%2020.9824C20.9864%2021.4281%2020.7309%2021.8679%2020.4199%2022.2871C20.5727%2022.3724%2020.7197%2022.4642%2020.8535%2022.5635C21.1995%2022.8203%2021.4572%2023.1628%2021.5498%2023.5781C21.6384%2023.9757%2021.5532%2024.3462%2021.3936%2024.6172C21.2255%2024.9021%2020.9798%2025.0453%2020.8574%2025.1074C20.7148%2025.1798%2020.5696%2025.2262%2020.4502%2025.2578C20.209%2025.3216%2019.9279%2025.3618%2019.6494%2025.3896C19.0833%2025.4462%2018.3699%2025.466%2017.6514%2025.4648C16.2862%2025.4627%2014.7842%2025.3817%2014%2025.3018C13.2155%2025.3816%2011.7142%2025.4627%2010.3496%2025.4648C9.63106%2025.466%208.91765%2025.4462%208.35156%2025.3896C8.07318%2025.3618%207.79198%2025.3216%207.55078%2025.2578C7.43138%2025.2262%207.28619%2025.1798%207.14355%2025.1074C7.02118%2025.0453%206.77546%2024.9021%206.60742%2024.6172C6.44779%2024.3462%206.36261%2023.9756%206.45117%2023.5781C6.54373%2023.1629%206.8015%2022.8203%207.14746%2022.5635L7.37012%2022.4111C7.4382%2022.3681%207.50855%2022.3271%207.58008%2022.2871C7.26906%2021.8678%207.01266%2021.4282%206.81152%2020.9824C6.77766%2021.0197%206.74577%2021.0578%206.71191%2021.0928C6.59233%2021.2163%206.44168%2021.356%206.27246%2021.4697C6.14703%2021.554%205.80103%2021.7719%205.34766%2021.7168C4.86739%2021.6584%204.60952%2021.3279%204.51855%2021.1914C4.40684%2021.0238%204.34323%2020.8475%204.30469%2020.707C4.2254%2020.4178%204.19431%2020.0695%204.20117%2019.6826C4.21514%2018.8975%204.39009%2017.7845%204.85156%2016.292C5.04693%2015.66%205.30895%2014.9358%205.56836%2014.2559L5.57422%2014.2422L6.43262%2012.0801C6.42912%2011.9159%206.42383%2011.6981%206.42383%2011.5527C6.42383%209.40182%206.92551%207.15876%208.13477%205.4209C9.37592%203.6375%2011.3249%202.45031%2014%202.4502L14.4932%202.46387Z'%20stroke='black'%20stroke-width='2.1'/%3e%3c/svg%3e") no-repeat center / contain;
        }
        .sso-email-btn {
            flex: 1;
            height: 44px;
            border: 1px solid #ff9800;
            color: #ff9800;
            background: #fff;
            border-radius: var(--radius);
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .sso-email-btn:hover {
            background: #ff9800;
            color: #fff;
        }
        .sso-email-btn i { font-size: 20px; }
        /* 通用社交登录按钮（支付宝/抖音/微博/GitHub，品牌色描边 + 文字徽标，无第三方图标依赖） */
        .sso-social-btn {
            flex: 1;
            height: 44px;
            border: 1px solid;
            background: #fff;
            border-radius: var(--radius);
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .sso-social-btn:hover { color: #fff; }
        .sso-alipay { border-color: #1677ff; color: #1677ff; }
        .sso-alipay:hover { background: #1677ff; }
        .sso-douyin { border-color: #000; color: #000; }
        .sso-douyin:hover { background: #000; }
        .sso-weibo { border-color: #e6162d; color: #e6162d; }
        .sso-weibo:hover { background: #e6162d; }
        .sso-github { border-color: #181717; color: #181717; }
        .sso-github:hover { background: #181717; }
        .sso-social-btn .sso-social-badge {
            width: 20px;
            height: 20px;
            border-radius: 5px;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            line-height: 1;
            flex-shrink: 0;
        }
        .sso-alipay .sso-social-badge { background: #1677ff; }
        .sso-douyin .sso-social-badge { background: #000; }
        .sso-weibo .sso-social-badge { background: #e6162d; }
        .sso-github .sso-social-badge { background: #181717; }
        /* 社交按钮品牌图标（内嵌真实 SVG 形状，按黑色渲染，白底与品牌色 hover 背景下均清晰可见） */
        .sso-social-btn .sso-social-icon {
            width: 20px;
            height: 20px;
            display: inline-block;
            background: no-repeat center / contain;
            flex-shrink: 0;
        }
        .sso-alipay .sso-social-icon { background-image: url("data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBzdGFuZGFsb25lPSJubyI/Pgo8IURPQ1RZUEUgc3ZnIFBVQkxJQyAiLS8vVzNDLy9EVEQgU1ZHIDIwMDEwOTA0Ly9FTiIgImh0dHA6Ly93d3cudzMub3JnL1RSLzIwMDEvUkVDLVNWRy0yMDAxMDkwNC9EVEQvc3ZnMTAuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgd2lkdGg9IjMyLjAwMDAwMHB0IiBoZWlnaHQ9IjMyLjAwMDAwMHB0IiB2aWV3Qm94PSIwIDAgMzIuMDAwMDAwIDMyLjAwMDAwMCIgcHJlc2VydmVBc3BlY3RSYXRpbz0ieE1pZFlNaWQgbWVldCI+CjxnIHRyYW5zZm9ybT0idHJhbnNsYXRlKDAuMDAwMDAwLDMyLjAwMDAwMCkgc2NhbGUoMC4xMDAwMDAsLTAuMTAwMDAwKSIgZmlsbD0iIzE2NzdmZiIgc3Ryb2tlPSJub25lIj4KPHBhdGggZD0iTTIwIDMwMCBjLTE4IC0xOCAtMjAgLTMzIC0yMCAtMTQwIDAgLTEwNyAyIC0xMjIgMjAgLTE0MCAxOCAtMTggMzMgLTIwIDE0MCAtMjAgMTA5IDAgMTIyIDIgMTQxIDIxIDI1IDI1IDIxIDI5IC00OCA1NyBsLTUyIDIyIC0zNyAtMjUgYy00NSAtMzEgLTk2IC0zMyAtMTI0IC01IC01NSA1NSAxMSAxMTUgMTAwIDkyIDM1IC05IDQyIC04IDUxIDkgOSAxOCA1IDQ5IC01OCA0OSAtNjcgMCAtNzkgMTQgLTE4IDIzIDI0IDMgMjEgNCAtMTIgNiAtNTQgMSAtNTcgMjEgLTMgMjEgMzMgMCA0MCAzIDQwIDIwIDAgMTMgNyAyMCAyMCAyMCAxMyAwIDIwIC03IDIwIC0yMCAwIC0xNyA3IC0yMCA0MCAtMjAgNTQgMCA1MSAtMjAgLTIgLTIyIC0zNyAtMSAtMzkgLTIgLTEwIC01IDM0IC01IDQwIC0xOSAyMSAtNTQgLTkgLTE3IC01IC0yMiAyNSAtMzQgNjQgLTI3IDY2IC0yNSA2NiA3MCAwIDEyMSAtNSAxMjUgLTE2MCAxMjUgLTEwNyAwIC0xMjIgLTIgLTE0MCAtMjB6Ii8+CjxwYXRoIGQ9Ik01MCAxMjUgYy0zNCAtNDEgNDUgLTY0IDkxIC0yNiBsMjQgMTkgLTI4IDExIGMtMzkgMTUgLTcyIDE0IC04NyAtNHoiLz4KPC9nPgo8L3N2Zz4="); }
        .sso-alipay:hover .sso-social-icon { background-image: url("data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBzdGFuZGFsb25lPSJubyI/Pgo8IURPQ1RZUEUgc3ZnIFBVQkxJQyAiLS8vVzNDLy9EVEQgU1ZHIDIwMDEwOTA0Ly9FTiIgImh0dHA6Ly93d3cudzMub3JnL1RSLzIwMDEvUkVDLVNWRy0yMDAxMDkwNC9EVEQvc3ZnMTAuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgd2lkdGg9IjMyLjAwMDAwMHB0IiBoZWlnaHQ9IjMyLjAwMDAwMHB0IiB2aWV3Qm94PSIwIDAgMzIuMDAwMDAwIDMyLjAwMDAwMCIgcHJlc2VydmVBc3BlY3RSYXRpbz0ieE1pZFlNaWQgbWVldCI+CjxnIHRyYW5zZm9ybT0idHJhbnNsYXRlKDAuMDAwMDAwLDMyLjAwMDAwMCkgc2NhbGUoMC4xMDAwMDAsLTAuMTAwMDAwKSIgZmlsbD0iI2ZmZmZmZiIgc3Ryb2tlPSJub25lIj4KPHBhdGggZD0iTTIwIDMwMCBjLTE4IC0xOCAtMjAgLTMzIC0yMCAtMTQwIDAgLTEwNyAyIC0xMjIgMjAgLTE0MCAxOCAtMTggMzMgLTIwIDE0MCAtMjAgMTA5IDAgMTIyIDIgMTQxIDIxIDI1IDI1IDIxIDI5IC00OCA1NyBsLTUyIDIyIC0zNyAtMjUgYy00NSAtMzEgLTk2IC0zMyAtMTI0IC01IC01NSA1NSAxMSAxMTUgMTAwIDkyIDM1IC05IDQyIC04IDUxIDkgOSAxOCA1IDQ5IC01OCA0OSAtNjcgMCAtNzkgMTQgLTE4IDIzIDI0IDMgMjEgNCAtMTIgNiAtNTQgMSAtNTcgMjEgLTMgMjEgMzMgMCA0MCAzIDQwIDIwIDAgMTMgNyAyMCAyMCAyMCAxMyAwIDIwIC03IDIwIC0yMCAwIC0xNyA3IC0yMCA0MCAtMjAgNTQgMCA1MSAtMjAgLTIgLTIyIC0zNyAtMSAtMzkgLTIgLTEwIC01IDM0IC01IDQwIC0xOSAyMSAtNTQgLTkgLTE3IC01IC0yMiAyNSAtMzQgNjQgLTI3IDY2IC0yNSA2NiA3MCAwIDEyMSAtNSAxMjUgLTE2MCAxMjUgLTEwNyAwIC0xMjIgLTIgLTE0MCAtMjB6Ii8+CjxwYXRoIGQ9Ik01MCAxMjUgYy0zNCAtNDEgNDUgLTY0IDkxIC0yNiBsMjQgMTkgLTI4IDExIGMtMzkgMTUgLTcyIDE0IC04NyAtNHoiLz4KPC9nPgo8L3N2Zz4="); }
        .sso-weibo .sso-social-icon { background-image: url("data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBzdGFuZGFsb25lPSJubyI/Pgo8IURPQ1RZUEUgc3ZnIFBVQkxJQyAiLS8vVzNDLy9EVEQgU1ZHIDIwMDEwOTA0Ly9FTiIKICJodHRwOi8vd3d3LnczLm9yZy9UUi8yMDAxL1JFQy1TVkctMjAwMTA5MDQvRFREL3N2ZzEwLmR0ZCI+CjxzdmcgdmVyc2lvbj0iMS4wIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciCiB3aWR0aD0iMzIuMDAwMDAwcHQiIGhlaWdodD0iMzIuMDAwMDAwcHQiIHZpZXdCb3g9IjAgMCAzMi4wMDAwMDAgMzIuMDAwMDAwIgogcHJlc2VydmVBc3BlY3RSYXRpbz0ieE1pZFlNaWQgbWVldCI+Cgo8ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgwLjAwMDAwMCwzMi4wMDAwMDApIHNjYWxlKDAuMTAwMDAwLC0wLjEwMDAwMCkiCmZpbGw9ImN1cnJlbnRDb2xvciIgc3Ryb2tlPSJub25lIj4KPHBhdGggZD0iTTI4MSAyNTQgYzAgLTExIDMgLTE0IDYgLTYgMyA3IDIgMTYgLTEgMTkgLTMgNCAtNiAtMiAtNSAtMTN6Ii8+CjxwYXRoIGQ9Ik0xMDUgMjQwIGMtMjggLTEyIC03NSAtNTUgLTc1IC02OSAwIC02IC00IC0xMSAtOSAtMTEgLTUgMCAtMTIgLTEyCi0xNSAtMjYgLTEzIC00OSAzNCAtOTEgMTA5IC05OCA0NyAtNSAxMjMgMjEgMTM3IDQ3IDE5IDM0IDIxIDYwIDQgNTQgMTEgLTQKMTMgLTEgLTkgMTEgNiAxNCA0IDE1IC05IDQgLTEzIC0xMSAtMTUgLTggLTExIDIzIDMgMzAgMSAzNSAtMTcgMzUgLTEyIDAKLTE5IC00IC0xNSAtOSAzIC01IDEgLTEzIC01IC0xNiAtNiAtNCAtMTAgLTMgLTkgMiAzIDIwIC0yIDI1IC0xNiAxMyAtMTMgLTExCi0xNSAtOCAtMTUgMTkgMCAzMiAtOSAzNyAtNDUgMjF6IG0yIC0yNSBjMCAtOCAtNCAtMTIgLTkgLTkgLTUgMyAtNiAxMCAtMyAxNQo5IDEzIDEyIDExIDEyIC02eiBtLTI3IC0xOCBjMCAtMiAtMTAgLTEyIC0yMiAtMjMgbC0yMyAtMTkgMTkgMjMgYzE4IDIxIDI2CjI3IDI2IDE5eiBtODggLTI3IGMxIDAgNiAwIDEyIDAgMTYgMCA0OSAtNDIgNDMgLTU2IC0zIC04IDEgLTIyIDggLTMyIDExIC0xNAoxMSAtMTUgLTEgLTUgLTEwIDEwIC0xOCA4IC0zOCAtNyAtMzggLTMwIC0xNTIgLTI1IC0xNTIgNyAwIDggLTUgMTEgLTEzIDcgLTYKLTQgLTkgLTQgLTUgMSA0IDQgMiAxNSAtNSAyMyAtOCAxMSAtOCAxNCAwIDkgNyAtNCAxNCAxIDE4IDEyIDMgMTEgMTkgMjUgMzUKMzIgMTYgOCAyNyAxNiAyNCAyMCAtMiA0IDEzIDMgMzQgLTIgMjAgLTQgMzggLTggNDAgLTl6Ii8+CjxwYXRoIGQ9Ik05MCAxMzIgYy00MyAtMzUgMCAtODQgNTIgLTU4IDI5IDE1IDM1IDQ2IDEyIDYzIC0yNCAxNyAtMzggMTYgLTY0Ci01eiBtNDMgLTE5IGMtNyAtMyAtMTMgLTExIC0xMyAtMTkgMCAtOCAtNyAtMTQgLTE1IC0xNCAtOCAwIC0xNSA2IC0xNSAxMyAwCjE5IDUgMjIgMzIgMjQgMTMgMCAxNyAtMSAxMSAtNHoiLz4KPHBhdGggZD0iTTI1MSAyMjQgYzAgLTExIDMgLTE0IDYgLTYgMyA3IDIgMTYgLTEgMTkgLTMgNCAtNiAtMiAtNSAtMTN6Ii8+CjxwYXRoIGQ9Ik0yOTUgMjMxIGMtMyAtNSAtMiAtMTIgMyAtMTUgNSAtMyA5IDEgOSA5IDAgMTcgLTMgMTkgLTEyIDZ6Ii8+CjxwYXRoIGQ9Ik0zMDEgMTg0IGMwIC0xMSAzIC0xNCA2IC02IDMgNyAyIDE2IC0xIDE5IC0zIDQgLTYgLTIgLTUgLTEzeiIvPgo8L2c+Cjwvc3ZnPg=="); }
        .sso-github .sso-social-icon { background-image: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNCIgaGVpZ2h0PSIyNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJjdXJyZW50Q29sb3IiPjxwYXRoIGQ9Ik0xMC4yMjYgMTcuMjg0Yy0yLjk2NS0uMzYtNS4wNTQtMi40OTMtNS4wNTQtNS4yNTYgMC0xLjEyMy40MDQtMi4zMzYgMS4wNzgtMy4xNDQtLjI5Mi0uNzQxLS4yNDctMi4zMTQuMDktMi45NjUuODk4LS4xMTIgMi4xMTEuMzYgMi44MyAxLjAxLjg1My0uMjY5IDEuNzUyLS40MDQgMi44NTMtLjQwNCAxLjEgMCAxLjk5OS4xMzUgMi44MDcuMzgyLjY5Ni0uNjI5IDEuOTMyLTEuMSAyLjgzLS45ODguMzE1LjYwNi4zNiAyLjE3OS4wNjcgMi45NDIuNzIuODU0IDEuMTAxIDIgMS4xMDEgMy4xNjcgMCAyLjc2My0yLjA4OSA0Ljg1Mi01LjA5OCA1LjIzNC43NjMuNDk0IDEuMjggMS41NzIgMS4yOCAyLjgwN3YyLjMzNmMwIC42NzQuNTYxIDEuMDU2IDEuMjM1Ljc4NiA0LjA2Ni0xLjU1IDcuMjU1LTUuNjE1IDcuMjU1LTEwLjY0NkMyMy41IDYuMTg4IDE4LjMzNCAxIDExLjk3OCAxIDUuNjIgMSAuNSA2LjE4OC41IDEyLjU0NWMwIDQuOTg2IDMuMTY3IDkuMTIgNy40MzUgMTAuNjY5LjYwNi4yMjUgMS4xOS0uMTggMS4xOS0uNzg2VjIwLjYzYTIuOSAyLjkgMCAwIDEtMS4wNzguMjI0Yy0xLjQ4MyAwLTIuMzU5LS44MDgtMi45ODctMi4zMTMtLjI0Ny0uNjA3LS41MTctLjk2Ni0xLjAzNC0xLjAzMy0uMjctLjAyMy0uMzU5LS4xMzUtLjM1OS0uMjcgMC0uMjcuNDUtLjQ3MS44OTgtLjQ3MS42NTIgMCAxLjIxMy40MDQgMS43OTcgMS4yMzUuNDUuNjUxLjkyMS45NDMgMS40ODMuOTQzLjU2MSAwIC45Mi0uMjAyIDEuNDM3LS43MTkuMzgyLS4zODEuNjc0LS43MTguOTQ0LS45NDMiPjwvcGF0aD48L3N2Zz4="); }
        .sso-link-row {
            display: flex;
            justify-content: space-between;
            margin-top: 12px;
            font-size: 13px;
        }
        .sso-link-row a { color: var(--primary); text-decoration: none; }
        .sso-link-row a:hover { text-decoration: underline; }
        .sso-tip {
            text-align: center;
            font-size: 13px;
            color: #94a3b8;
            margin-top: 16px;
        }
        /* 快捷登录双 Tab（完善资料 / 账号绑定） */
        .sso-tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }
        .sso-tab {
            flex: 1;
            padding: 10px 0;
            text-align: center;
            font-size: 14px;
            color: #666;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            transition: all 0.2s;
        }
        .sso-tab:hover { color: var(--primary); }
        .sso-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            font-weight: 600;
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