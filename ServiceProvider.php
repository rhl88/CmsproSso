<?php

namespace App\Apps\CmsproSso;

use App\Models\ConfigItem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            base_path('app/Apps/CmsproSso/Config/sso.php'),
            'apps.cmspro.sso'
        );
    }

    public function boot(): void
    {
        // 注册前台认证中间件（未登录跳转统一登录页，而非后台）
        $this->app['router']->aliasMiddleware(
            'cmspro.sso.auth',
            \App\Apps\CmsproSso\Middleware\FrontAuthMiddleware::class
        );

        $this->registerRoutes();
        $this->loadViews();
        $this->registerHooks();
        $this->registerMiddleware();
    }

    protected function registerRoutes(): void
    {
        // 后台管理路由
        Route::prefix('admin/cmspro/sso')
            ->namespace('App\Apps\CmsproSso\Controllers\Admin')
            ->middleware(['web', 'auth:admin'])
            ->group(base_path('app/Apps/CmsproSso/Routes/admin.php'));

        // 前台统一登录路由（三模式）
        $this->registerHomeRoutes();
    }

    /**
     * 根据访问模式注册前台路由
     *
     * 两种模式：
     * 1. path（路径前缀模式）：Route::prefix($path)，如 /sso/。API 保持 /api/cmspro/sso。
     * 2. domain（子域名模式）：Route::domain($domain)，前台页面和 API 均在子域名下注册。
     *    子域名模式下其他全局路由（/admin、/user 等）会被 catch-all 路由隔离返回 404。
     */
    protected function registerHomeRoutes(): void
    {
        $accessMode = $this->getConfigValue('access_mode', 'path');

        if ($accessMode === 'domain') {
            // 子域名模式：前台页面和 API 均在子域名下注册
            $domainConfig = $this->getConfigValue('access_domain', '');
            if (empty($domainConfig)) {
                return; // 未配置域名则不注册前台路由
            }

            // 支持逗号分隔的多域名
            $domains = array_filter(array_map('trim', explode(',', $domainConfig)));

            foreach ($domains as $domain) {
                // 子域名模式：前台页面路由
                Route::domain($domain)
                    ->middleware(['web'])
                    ->namespace('App\Apps\CmsproSso\Controllers\Home')
                    ->group(base_path('app/Apps/CmsproSso/Routes/home.php'));

                // 子域名模式：前台 API 路由（必须在 domain 内注册，否则被 catch-all 拦截）
                Route::domain($domain)
                    ->prefix('api/cmspro/sso')
                    ->middleware(['web', 'throttle:60,1'])
                    ->namespace('App\Apps\CmsproSso\Controllers\HomeApi')
                    ->group(base_path('app/Apps/CmsproSso/Routes/home-api.php'));
            }
        } elseif ($accessMode === 'path') {
            // 路径前缀模式：前台页面路由加 prefix
            $path = $this->getConfigValue('access_path', 'sso');
            Route::prefix($path)
                ->middleware(['web'])
                ->namespace('App\Apps\CmsproSso\Controllers\Home')
                ->group(base_path('app/Apps/CmsproSso/Routes/home.php'));

            // API 路由保持全局前缀（不跟随 path，前端 JS 用绝对路径调用）
            Route::prefix('api/cmspro/sso')
                ->middleware(['web', 'throttle:60,1'])
                ->namespace('App\Apps\CmsproSso\Controllers\HomeApi')
                ->group(base_path('app/Apps/CmsproSso/Routes/home-api.php'));
        }
    }

    protected function loadViews(): void
    {
        $this->loadViewsFrom(
            base_path('app/Apps/CmsproSso/Views'),
            'cmspro.sso'
        );
    }

    protected function registerHooks(): void
    {
        $manifest = json_decode(
            file_get_contents(base_path('app/Apps/CmsproSso/manifest.json')),
            true
        );

        if (isset($manifest['hooks'])) {
            $hooksClass = "App\\Apps\\CmsproSso\\{$manifest['hooks']}";
            if (class_exists($hooksClass)) {
                $instance = new $hooksClass;
                $instance->register(app(\App\Services\HookManager::class));
            }
        }
    }

    /**
     * 注册覆盖系统登录/注册中间件到 web 路由组
     *
     * 开启「覆盖系统」配置后，/user/login、/user/register 将被重定向到本应用登录/注册页。
     */
    protected function registerMiddleware(): void
    {
        $this->app['router']->pushMiddlewareToGroup(
            'web',
            \App\Apps\CmsproSso\Middleware\OverrideSystemAuthMiddleware::class
        );
    }

    /**
     * 从数据库读取配置值
     */
    protected function getConfigValue(string $name, string $default = ''): string
    {
        try {
            $value = ConfigItem::where('code', 'app_cmspro_sso_' . $name)->value('value');
            return $value ?? $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
