<?php

namespace App\Apps\CmsproSso\Middleware;

use App\Apps\CmsproSso\Services\ShareService;
use Closure;
use Illuminate\Http\Request;

/**
 * 统一登录前台认证中间件
 *
 * 用于保护需要登录才能访问的前台页面。未登录时：
 * - JSON/AJAX 请求返回 401（由前端统一跳转登录页）
 * - 页面请求重定向到统一登录页（而非后台登录页）
 */
class FrontAuthMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (!auth('web')->check()) {
            // JSON/AJAX 请求返回 401，跳转交给前端处理
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'code' => 40003,
                    'message' => '登录已过期，请重新登录',
                    'data' => null,
                ], 401);
            }

            // 页面请求重定向到统一登录页，携带 redirect 参数以便登录后返回
            $redirect = $request->fullUrl();
            return redirect()->guest(ShareService::frontUrl('/login?redirect=' . urlencode($redirect)));
        }

        return $next($request);
    }
}
