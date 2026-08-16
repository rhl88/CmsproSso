<?php

namespace App\Apps\CmsproSso\Middleware;

use App\Apps\CmsproSso\Services\SettingService;
use App\Apps\CmsproSso\Services\ShareService;
use Closure;
use Illuminate\Http\Request;

/**
 * 覆盖系统登录/注册中间件
 *
 * 开启「覆盖系统」配置后，将系统默认登录页 /user/login 与注册页 /user/register
 * 重定向到本应用统一登录/注册页，使系统各处硬编码的登录入口统一进入 SSO 流程。
 * 关闭配置后直接放行，恢复系统默认登录/注册页。
 */
class OverrideSystemAuthMiddleware
{
    public function __construct(
        protected SettingService $settingService
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        // 未开启覆盖系统则放行
        if ($this->settingService->get('override_system', '0') !== '1') {
            return $next($request);
        }

        // 仅拦截 GET 页面请求（POST 为系统登录/注册表单提交，不重定向）
        if (!$request->isMethod('GET')) {
            return $next($request);
        }

        $path = $request->path();

        // 已登录用户访问登录/注册页时由系统自行跳转，无需处理
        if (auth('web')->check()) {
            return $next($request);
        }

        // 登录页：重定向到统一登录页，携带 redirect 参数以便登录后返回
        if ($path === 'user/login') {
            $redirect = $request->fullUrl();
            return redirect(ShareService::frontUrl('/login?redirect=' . urlencode($redirect)));
        }

        // 注册页：重定向到统一注册页
        if ($path === 'user/register') {
            $redirect = $request->fullUrl();
            return redirect(ShareService::frontUrl('/register?redirect=' . urlencode($redirect)));
        }

        return $next($request);
    }
}