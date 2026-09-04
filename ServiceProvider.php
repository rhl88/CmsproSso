<?php

namespace App\Apps\CmsproSso;

use App\Apps\CmsproSso\Controllers\User\AccountController;
use App\Models\AdminMenu;
use App\Models\ConfigItem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
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
        $this->registerHomeMenuComposer();
        $this->registerHooks();
        $this->registerMiddleware();
        $this->registerEventListeners();
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

        // 前台账号管理页覆盖（应用注入账号绑定，参照 CmsproNotepad 覆盖 dashboard 模式；不修改框架文件）
        Route::prefix('user')
            ->middleware(['web', 'auth:web'])
            ->group(function () {
                Route::get('/account', [AccountController::class, 'index'])->name('user.account');
            });
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

                // 子域名模式：快捷模式服务端开放 API（跨站服务器调用，无 session/CSRF）
                Route::domain($domain)
                    ->prefix('api/cmspro/sso/oauth')
                    ->middleware(['throttle:120,1'])
                    ->namespace('App\Apps\CmsproSso\Controllers\HomeApi')
                    ->group(base_path('app/Apps/CmsproSso/Routes/oauth-api.php'));
            }

            // 子域名模式下也全局注册前台 API，使主站前台账号页(/user/account)的账号绑定中心可跨域访问。
            // 绑定中心请求走主站域、用主站 web session 认证（auth:web），故仅需全局注册 home-api 前台接口；
            // 服务端开放接口（oauth-api.php）仍仅在子域开放，不暴露到主站。
            Route::prefix('api/cmspro/sso')
                ->middleware(['web', 'throttle:60,1'])
                ->namespace('App\Apps\CmsproSso\Controllers\HomeApi')
                ->group(base_path('app/Apps/CmsproSso/Routes/home-api.php'));
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

            // 快捷模式服务端开放 API（跨站服务器调用，无 session/CSRF，模式门禁在控制器内校验）
            Route::prefix('api/cmspro/sso/oauth')
                ->middleware(['throttle:120,1'])
                ->namespace('App\Apps\CmsproSso\Controllers\HomeApi')
                ->group(base_path('app/Apps/CmsproSso/Routes/oauth-api.php'));
        }
    }

    protected function loadViews(): void
    {
        $this->loadViewsFrom(
            base_path('app/Apps/CmsproSso/Views'),
            'cmspro.sso'
        );
    }

    /**
     * 向前台布局注入系统前台导航菜单
     *
     * 查询 terminal_type='home' 的菜单并构建树形结构，
     * 供 layouts/home.blade.php 头部左侧渲染导航使用。
     */
    protected function registerHomeMenuComposer(): void
    {
        // 参照官网应用（Cmsprohome）方式：直接查询含 app_id 的菜单模型对象，
        // 使 menu_path() 能识别域名绑定应用，将子域名菜单渲染为其绑定域名 URL
        View::composer('cmspro.sso::layouts.home', function ($view) {
            try {
                $menus = AdminMenu::where('status', 1)
                    ->where('visible', 1)
                    ->where('terminal_type', 'home')
                    ->orderBy('sort', 'asc')
                    ->get(['id', 'parent_id', 'name', 'path', 'icon', 'sort', 'visible', 'app_id', 'terminal_type']);

                $view->with('homeMenus', $this->buildHomeMenuTree($menus));
            } catch (\Throwable) {
                $view->with('homeMenus', collect());
            }
        });
    }

    /**
     * 构建前台导航菜单树
     *
     * 节点为 AdminMenu 模型对象，子菜单挂载在 children 动态属性上。
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, AdminMenu>  $menus
     * @return array<int, AdminMenu>
     */
    protected function buildHomeMenuTree($menus): array
    {
        $grouped = collect($menus)->groupBy('parent_id');

        $build = function (int $parentId) use (&$build, $grouped): array {
            return $grouped->get($parentId, collect())
                ->map(function (AdminMenu $menu) use (&$build) {
                    $menu->children = $build((int) $menu->id);

                    return $menu;
                })
                ->all();
        };

        return $build(0);
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
     * 注册事件监听器：Onlinepay 支付成功后发放邀请码
     */
    protected function registerEventListeners(): void
    {
        \Illuminate\Support\Facades\Event::listen(
            \App\Apps\Onlinepay\Events\OrderPaid::class,
            \App\Apps\CmsproSso\Listeners\HandleInviteOrderPaid::class
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
