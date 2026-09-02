<?php

namespace App\Apps\CmsproSso\Services;

/**
 * 前台 URL 生成服务（双模式兼容）
 *
 * 根据 access_mode 自动生成前台页面 URL：
 * - path 模式：/sso/xxx
 * - domain 模式：http://sso.example.com/xxx
 */
class ShareService
{
    /**
     * 生成前台页面 URL
     */
    public static function frontUrl(string $path): string
    {
        // 从数据库读取实际配置（数据库优先，回退 Config/sso.php 默认值）
        $accessMode = app(SettingService::class)->get('access_mode', 'path');

        if ($accessMode === 'domain') {
            $domain = app(SettingService::class)->get('access_domain', '');
            $firstDomain = trim(explode(',', $domain)[0] ?? '');
            if ($firstDomain !== '') {
                $scheme = request()->isSecure() ? 'https' : 'http';
                return $scheme . '://' . $firstDomain . '/' . ltrim($path, '/');
            }
        }

        // path 模式
        $prefix = trim(app(SettingService::class)->get('access_path', 'sso'), '/');
        $urlPath = '/' . ($prefix !== '' ? $prefix . '/' : '') . ltrim($path, '/');

        // 当前请求位于绑定的子域名（如论坛 bbs.cmspro.com）时，SSO 的 /sso 前缀实际
        // 挂于主域名下，需生成主站绝对URL，否则会跳转到子域名下不存在的路径（404）。
        // 复用框架 main_url 的主站域名推导逻辑（config('app.url')），主站场景保持原有行为。
        $currentHost = request()->getHost();
        if (in_array($currentHost, \App\Providers\AppServiceProvider::getBoundDomains())) {
            $scheme = request()->isSecure() ? 'https' : 'http';
            $mainHost = parse_url(config('app.url'), PHP_URL_HOST);
            return rtrim($scheme . '://' . $mainHost, '/') . $urlPath;
        }

        return url($urlPath);
    }
}
