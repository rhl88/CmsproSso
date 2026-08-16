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
        return url('/' . ($prefix !== '' ? $prefix . '/' : '') . ltrim($path, '/'));
    }
}
