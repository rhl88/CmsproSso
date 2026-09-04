<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproSso\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 子域名模式前台 API 全局注册测试
 *
 * 背景：站点为子域名模式（access_mode=domain，绑定 sso 子域）时，
 * 前台 /api/cmspro/sso/* 路由原先仅挂在绑定子域下，导致主站前台账号页
 * （/user/account）的账号绑定中心请求落到主站域无对应路由，返回框架 40401。
 *
 * 修复：子域名模式下同时全局注册前台 API，使绑定中心在主站域可用。
 * 本测试验证子域名模式下，主站域也能命中 /api/cmspro/sso/user/bindings。
 */
class SsoDomainModeRouteTest extends TestCase
{
    public function test_子域名模式下前台API在主站域全局可达(): void
    {
        $this->createConfigItemsTable();
        DB::table('config_items')->insert([
            ['code' => 'app_cmspro_sso_access_mode', 'value' => 'domain'],
            ['code' => 'app_cmspro_sso_access_domain', 'value' => 'sso.test'],
        ]);

        $this->app->register(ServiceProvider::class);

        // 主站域（不带 HOST）请求绑定中心接口 → 命中全局注册路由，而非框架 catch-all 404
        $route = Route::getRoutes()->match(Request::create('/api/cmspro/sso/user/bindings', 'GET'));

        $this->assertSame('api/cmspro/sso/user/bindings', trim((string) $route->uri(), '/'));
        $this->assertSame('bindings', $route->getActionMethod());
        $this->assertSame(\App\Apps\CmsproSso\Controllers\HomeApi\AuthController::class, $route->getControllerClass());
    }

    protected function createConfigItemsTable(): void
    {
        if (Schema::hasTable('config_items')) {
            return;
        }

        Schema::create('config_items', function ($table) {
            $table->bigIncrements('id');
            $table->string('code', 150)->default('')->index();
            $table->text('value')->nullable();
        });
    }
}