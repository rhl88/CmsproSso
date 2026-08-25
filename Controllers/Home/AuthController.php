<?php

namespace App\Apps\CmsproSso\Controllers\Home;

use App\Apps\CmsproSso\Services\SettingService;
use App\Apps\CmsproSso\Services\ShareService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

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
            'allow_register'  => $this->settingService->get('allow_register', '1'),
            'wechat_configured' => $this->settingService->isWechatConfigured() ? '1' : '0',
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
        ]);
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
     * PC 扫码登录成功提示页
     */
    public function pcSuccessForm()
    {
        return view('cmspro.sso::Home.auth.pc-success');
    }

    /**
     * 首次微信登录完善资料页
     */
    public function completeForm(Request $request)
    {
        $user = auth('web')->user();
        if (!$user) {
            return redirect(ShareService::frontUrl('/login'));
        }

        return view('cmspro.sso::Home.auth.complete', [
            'user' => $user,
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
     * 解析登录成功后的跳转地址
     */
    protected function resolveRedirect(Request $request): string
    {
        $redirect = trim((string) $request->input('redirect', ''));
        if ($redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            return $redirect;
        }

        return $this->settingService->getDefaultRedirect();
    }
}