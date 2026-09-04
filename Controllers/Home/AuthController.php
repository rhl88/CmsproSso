<?php

namespace App\Apps\CmsproSso\Controllers\Home;

use App\Apps\CmsproSso\Services\SettingService;
use App\Apps\CmsproSso\Services\ShareService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class AuthController extends Controller
{
    public function __construct(
        protected SettingService $settingService
    ) {
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
        ];

        return view('cmspro.sso::Home.auth.login', [
            'configs' => $configs,
            'redirect' => (string) $request->input('redirect', ''),
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
            'redirect'    => (string) $request->input('redirect', ''),
            'mailConfigured' => mail_configured(),
            'invite'      => $this->inviteViewData(),
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
     */
    public function forgotPasswordForm(Request $request)
    {
        // 未配置邮箱时邮件验证码不可用，跳转登录页
        if (! mail_configured()) {
            return redirect(ShareService::frontUrl('/login'));
        }

        return view('cmspro.sso::Home.auth.forgot-password', [
            'login_title' => $this->settingService->get('login_title', '统一登录'),
            'redirect'    => (string) $request->input('redirect', ''),
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
     * 首次社交登录完善资料页（微信/QQ/relay 通用）
     *
     * relay 平台带 token（快捷登录新用户分流：完善资料 / 账号绑定双 Tab），
     * token 为授权数据暂存句柄，透传给视图供提交接口使用。
     */
    public function completeForm(Request $request)
    {
        $user = auth('web')->user();
        $platform = $this->platformFromPath($request->path());
        $token = (string) $request->input('token', '');

        // relay 分流页无需登录态（凭 token 完成建号/绑定），其他平台需已登录
        if ($platform !== 'relay' && !$user) {
            return redirect(ShareService::frontUrl('/login'));
        }

        // relay 新用户：凭 token 取回暂存授权数据，回填服务端昵称（无本地账号可取）
        $oauthNickname = '';
        if ($platform === 'relay' && $token !== '') {
            $oauthData = Cache::get('cmspro_sso_oauth_data:' . $token);
            if (is_array($oauthData) && (string) ($oauthData['platform'] ?? '') === 'relay') {
                $oauthNickname = (string) ($oauthData['nickname'] ?? '');
            }
        }

        return view('cmspro.sso::Home.auth.complete', [
            'user'     => $user,
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
        $redirect = trim((string) $request->input('redirect', ''));
        if ($redirect !== '' && $this->isSafeRedirect($redirect)) {
            return $redirect;
        }

        return $this->settingService->getDefaultRedirect();
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