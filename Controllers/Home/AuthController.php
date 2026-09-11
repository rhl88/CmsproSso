<?php

namespace App\Apps\CmsproSso\Controllers\Home;

use App\Apps\CmsproSso\Services\SettingService;
use App\Apps\CmsproSso\Services\ShareService;
use App\Apps\CmsproSso\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    // Cache 键前缀：社交新用户授权数据暂存（与 HomeApi\AuthController::CACHE_OAUTH_DATA_PREFIX 同值）
    protected const OAUTH_DATA_PREFIX = 'cmspro_sso_oauth_data:';

    public function __construct(
        protected SettingService $settingService,
        protected SmsService $smsService
    ) {
    }

    /**
     * 找回密码通道可用性：邮箱（邮箱登录开关 + 系统 SMTP 已配置）、手机（手机登录开关 + 短信服务已配置）
     */
    protected function forgotChannels(): array
    {
        return [
            'email' => $this->settingService->isEmailLoginEnabled() && mail_configured(),
            'phone' => $this->settingService->isPhoneLoginEnabled() && $this->smsService->isConfigured(),
        ];
    }

    /**
     * 登录页
     */
    public function loginForm(Request $request)
    {
        // 已登录则直接跳转
        if (auth('web')->check()) {
            return redirect($this->resolveRedirect($request));
        }

        $configs = [
            'login_title'     => $this->settingService->get('login_title', '统一登录'),
            'wechat_enabled'  => $this->settingService->get('wechat_enabled', '0'),
            'wechat_configured' => $this->settingService->isWechatConfigured() ? '1' : '0',
            'qq_enabled'      => $this->settingService->get('qq_enabled', '0'),
            'qq_mode'         => $this->settingService->get('qq_mode', 'web'),
            'qq_configured'   => $this->settingService->isQqConfigured() ? '1' : '0',
            'phone_login_enabled' => $this->settingService->isPhoneLoginEnabled() ? '1' : '0',
            'email_login_enabled' => $this->settingService->isEmailLoginEnabled() ? '1' : '0',
            'alipay_enabled'  => $this->settingService->get('alipay_enabled', '0'),
            'douyin_enabled'  => $this->settingService->get('douyin_enabled', '0'),
            'weibo_enabled'   => $this->settingService->get('weibo_enabled', '0'),
            'github_enabled'  => $this->settingService->get('github_enabled', '0'),
            // 快捷模式中转客户端：社交登录均由服务端代跳（oauthLoginUrl 内部自动中转），入口按后台启用状态显示
            'is_relay_client' => $this->settingService->isRelayClient() ? '1' : '0',
            'allow_register'  => $this->settingService->get('allow_register', '1'),
            'invite_enabled'  => $this->settingService->get('invite_enabled', '0'),
            // 登录验证码：后台开启且验证码应用就绪、启用位置包含 login 时渲染
            'captcha_enabled' => $this->settingService->isCaptchaSceneEnabled('login') ? '1' : '0',
            'captcha_driver'  => $this->settingService->getCaptchaDriver(),
        ];

        return view('cmspro.sso::Home.auth.login', [
            'configs' => $configs,
            'redirect' => $this->normalizeRedirect((string) $request->input('redirect', '')),
        ]);
    }

    /**
     * 注册页
     */
    public function registerForm(Request $request)
    {
        // 已登录则直接跳转
        if (auth('web')->check()) {
            return redirect($this->resolveRedirect($request));
        }

        // 未开启注册则跳转登录页
        if ($this->settingService->get('allow_register', '1') !== '1') {
            return redirect(ShareService::frontUrl('/login'));
        }

        return view('cmspro.sso::Home.auth.register', [
            'login_title' => $this->settingService->get('login_title', '统一登录'),
            'redirect'    => $this->normalizeRedirect((string) $request->input('redirect', '')),
            'mailConfigured' => mail_configured(),
            'invite'      => $this->inviteViewData(),
            // 注册验证码：后台开启且验证码应用就绪、启用位置包含 register 时渲染
            'captcha_enabled' => $this->settingService->isCaptchaSceneEnabled('register') ? '1' : '0',
            'captcha_driver'  => $this->settingService->getCaptchaDriver(),
        ]);
    }

    /**
     * 注册页邀请码展示配置
     */
    protected function inviteViewData(): array
    {
        $enabled = $this->settingService->get('invite_enabled', '0') === '1';
        if (!$enabled) {
            return ['enabled' => false];
        }

        $mode = $this->settingService->get('invite_grant_mode', 'online_buy');
        $price = $this->settingService->get('invite_price', '');
        $price = is_numeric($price) ? round((float) $price, 2) : 0.0;

        return [
            'enabled' => true,
            'mode'    => $mode,
            'price'   => $price,
            // 在线购买入口是否可用（费用必须大于 0）
            'buyable' => $mode === 'online_buy' && $price > 0,
            'url'     => $this->settingService->get('invite_url', ''),
            'text'    => $this->settingService->get('invite_text', ''),
        ];
    }

    /**
     * 找回密码页
     *
     * 邮箱/手机通道均不可用时仍渲染页面，显示「系统未开启找回密码功能」提示（不静默重定向）。
     */
    public function forgotPasswordForm(Request $request)
    {
        $channels = $this->forgotChannels();

        return view('cmspro.sso::Home.auth.forgot-password', [
            'login_title'      => $this->settingService->get('login_title', '统一登录'),
            'redirect'         => (string) $request->input('redirect', ''),
            'email_available'  => $channels['email'],
            'phone_available'  => $channels['phone'],
        ]);
    }

    /**
     * 微信授权回调（页面转发给 HomeApi 处理）
     */
    public function wechatCallback(Request $request)
    {
        return app(\App\Apps\CmsproSso\Controllers\HomeApi\AuthController::class)
            ->wechatCallback($request);
    }

    /**
     * QQ 授权回调（页面转发给 HomeApi 处理）
     */
    public function qqCallback(Request $request)
    {
        return app(\App\Apps\CmsproSso\Controllers\HomeApi\AuthController::class)
            ->qqCallback($request);
    }

    /**
     * 支付宝授权回调（页面转发给 HomeApi 处理）
     */
    public function alipayCallback(Request $request)
    {
        return app(\App\Apps\CmsproSso\Controllers\HomeApi\AuthController::class)
            ->alipayCallback($request);
    }

    /**
     * 抖音授权回调（页面转发给 HomeApi 处理）
     */
    public function douyinCallback(Request $request)
    {
        return app(\App\Apps\CmsproSso\Controllers\HomeApi\AuthController::class)
            ->douyinCallback($request);
    }

    /**
     * 微博授权回调（页面转发给 HomeApi 处理）
     */
    public function weiboCallback(Request $request)
    {
        return app(\App\Apps\CmsproSso\Controllers\HomeApi\AuthController::class)
            ->weiboCallback($request);
    }

    /**
     * GitHub 授权回调（页面转发给 HomeApi 处理）
     */
    public function githubCallback(Request $request)
    {
        return app(\App\Apps\CmsproSso\Controllers\HomeApi\AuthController::class)
            ->githubCallback($request);
    }

    /**
     * 快捷模式中转回调（页面转发给 HomeApi 处理）
     */
    public function relayCallback(Request $request)
    {
        return app(\App\Apps\CmsproSso\Controllers\HomeApi\AuthController::class)
            ->relayCallback($request);
    }

    /**
     * PC 扫码登录成功提示页
     */
    public function pcSuccessForm()
    {
        return view('cmspro.sso::Home.auth.pc-success');
    }

    /**
     * 社交新用户邀请码填写页（邀请注册开启时，授权回调发现新用户后跳转；微信/QQ/relay 通用）
     *
     * 平台由请求路径解析（/auth/wechat/complete-invite 或 /auth/qq/complete-invite），
     * 用于提交邀请码时调用对应平台接口。
     */
    public function completeInviteForm(Request $request)
    {
        $token = (string) $request->input('token', '');
        $platform = $this->platformFromPath($request->path());

        return view('cmspro.sso::Home.auth.complete-invite', [
            'token'    => $token,
            'platform' => $platform,
        ]);
    }

    /**
     * 社交新用户分流页（完善资料 / 账号绑定，relay 与独立站社交平台通用）
     *
     * OAuth 回调发现新用户后携带 token 跳转至此（未登录），凭 token 完成
     * 「完善资料（创建账号）」或「账号绑定（登录已有账号）」；已登录用户无需分流。
     */
    public function completeForm(Request $request)
    {
        $platform = $this->platformFromPath($request->path());
        $token = (string) $request->input('token', '');

        // 已登录用户无需分流，直接跳转默认页
        if (auth('web')->check()) {
            return redirect($this->resolveRedirect($request));
        }

        // 分流页凭 token 完成建号/绑定，无 token 回登录页
        if ($token === '') {
            return redirect(ShareService::frontUrl('/login'));
        }

        // 进页即校验暂存授权数据：token 无效/过期/平台不匹配时页面无法完成任何操作，
        // 直接回登录页提示重新登录，避免用户填完表单提交后才报错
        $oauthData = Cache::get(self::OAUTH_DATA_PREFIX . $token);
        if (!is_array($oauthData) || (string) ($oauthData['platform'] ?? '') !== $platform) {
            // 诊断日志：定位进页误拦（token 过期/伪造/平台解析不一致）
            Log::warning('SSO分流: 分流页进页校验失败', [
                'token'  => $token,
                'expect' => $platform,
                'exists' => is_array($oauthData),
                'actual' => is_array($oauthData) ? (string) ($oauthData['platform'] ?? '') : null,
            ]);

            return redirect(ShareService::frontUrl('/login'))->with('error', '登录会话已过期，请重新登录');
        }
        $oauthNickname = (string) ($oauthData['nickname'] ?? '');

        return view('cmspro.sso::Home.auth.complete', [
            'platform' => $platform,
            'redirect' => (string) $request->input('redirect', ''),
            'token'    => $token,
            'oauthNickname' => $oauthNickname,
        ]);
    }

    /**
     * 登录后的首页（演示受保护页面）
     */
    public function home()
    {
        $user = auth('web')->user();

        return view('cmspro.sso::Home.home', [
            'user' => $user,
        ]);
    }

    /**
     * 从请求路径解析社交平台标识（complete-invite / complete 页通用）
     *
     * 路径形如 /auth/{platform}/complete-invite 或 /auth/{platform}/complete，
     * 优先精确匹配已知平台，避免子串误判（如 github 含 'qq' 吗？不含，但 alipay 含 'ali' 等）；
     * relay 与未知平台保持原样返回，由调用方兜底。
     */
    protected function platformFromPath(string $path): string
    {
        // 规范化前导斜杠：domain 模式下 Laravel path() 返回的路径不带前导斜杠（如 auth/qq/complete），
        // 不补齐会让下方正则匹配失败、平台误判，导致分流页进页校验误报「登录会话已过期」
        $path = '/' . ltrim($path, '/');

        foreach (['alipay', 'douyin', 'weibo', 'github', 'wechat', 'qq'] as $platform) {
            if (preg_match('#/auth/' . $platform . '/#', $path)) {
                return $platform;
            }
        }

        return str_contains($path, 'relay') ? 'relay' : 'wechat';
    }

    /**
     * 解析登录成功后的跳转地址
     */
    protected function resolveRedirect(Request $request): string
    {
        $redirect = $this->normalizeRedirect((string) $request->input('redirect', ''));
        if ($redirect !== '') {
            return $redirect;
        }

        return $this->settingService->getDefaultRedirect();
    }

    /**
     * 规范化登录/注册跳转地址（覆盖系统托管场景）
     *
     * 开启「覆盖系统」后，托管系统登录/注册页被重定向到本应用时透传的 redirect 有两类，
     * 登录/注册成功后需还原为托管系统的最终目标页：
     * 1. 嵌套地址：中间件以完整 URL 透传（如 http://主域/user/login?redirect=/apps/xxx），
     *    提取其内层 redirect 作为真实目标，避免登录后跳回托管登录页多绕一层；
     * 2. 相对路径（如 /apps/xxx）：补全主站域名成绝对地址，避免子域名模式下
     *    登录后相对跳转落到 SSO 子域名（sso.xxx/apps/xxx）404。
     * 绝对地址仅保留同主域站内地址（防开放重定向），其余返回空串走默认跳转。
     */
    protected function normalizeRedirect(string $redirect): string
    {
        $redirect = trim($redirect);
        if ($redirect === '') {
            return '';
        }

        // 嵌套：地址指向托管系统登录/注册页时，取其内层 redirect 继续（parse_str 自动解码）
        $parts = parse_url($redirect);
        if (isset($parts['path'])) {
            $path = '/' . ltrim((string) $parts['path'], '/');
            if (in_array($path, ['/user/login', '/user/register'], true)) {
                parse_str((string) ($parts['query'] ?? ''), $query);
                return $this->normalizeRedirect((string) ($query['redirect'] ?? ''));
            }
        }

        // 相对路径：补全主站域名（站内地址，无需再校验）
        if (str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            return $this->mainSiteBase() . $redirect;
        }

        // 绝对地址：仅保留同主域站内地址
        return $this->isSafeRedirect($redirect) ? $redirect : '';
    }

    /**
     * 主站根地址（scheme + 主域名）
     *
     * host 取 config('app.url')，取不到时回退当前请求 host；scheme 随当前请求
     */
    protected function mainSiteBase(): string
    {
        $scheme = request()->isSecure() ? 'https' : 'http';
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: request()->getHost();

        return $scheme . '://' . $host;
    }

    /**
     * 校验跳转地址是否为站内安全地址（相对路径或同主域绝对地址，防止开放重定向）
     */
    protected function isSafeRedirect(string $url): bool
    {
        // 相对路径（站内）
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        // 绝对地址需校验 host 与当前站点同主域（含兄弟子域，如 sso → bbs）
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === '') {
            return false;
        }

        return $this->sameSiteHost($host, request()->getHost());
    }

    /**
     * 判断两个 host 是否同主域（完全相同或共享注册主域）
     */
    protected function sameSiteHost(string $host1, string $host2): bool
    {
        $host1 = strtolower(trim($host1, '.'));
        $host2 = strtolower(trim($host2, '.'));

        if ($host1 === $host2) {
            return true;
        }

        $domain = $this->registrableDomain($host1);

        return $domain !== '' && $domain === $this->registrableDomain($host2);
    }

    /**
     * 取 host 的注册主域（如 bbs.cmspro.cn → cmspro.cn）
     *
     * 对常见二级后缀（com.cn/net.cn 等）取三段，其余取两段；无点号 host 返回空串
     */
    protected function registrableDomain(string $host): string
    {
        $parts = explode('.', $host);
        $count = count($parts);
        if ($count < 2) {
            return '';
        }

        $secondLevelSuffixes = ['com', 'net', 'org', 'gov', 'edu', 'ac', 'co'];
        if ($count >= 3 && in_array($parts[$count - 2], $secondLevelSuffixes, true)) {
            return implode('.', array_slice($parts, -3));
        }

        return implode('.', array_slice($parts, -2));
    }
}