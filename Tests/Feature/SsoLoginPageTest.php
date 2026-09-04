<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 前台登录页社交入口渲染测试
 *
 * 覆盖支付宝/抖音/微博/GitHub 登录入口按钮的渲染规则：
 *   后台开启对应平台开关 → 登录页显示对应入口；开关未开 → 不显示；
 *   快捷模式中转客户端同样按启用状态显示，点击由服务端代跳中转（oauthLoginUrl 内部自动中转）。
 */
class SsoLoginPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. 独立站模式（默认 standalone）

    public function test_默认全关不渲染其他登录方式区块(): void
    {
        $this->get('/sso/login')
            ->assertOk()
            ->assertDontSee('其他登录方式')
            ->assertDontSee('id="alipay-login-btn"', false);
    }

    public function test_独立站开启四平台后台登录页显示对应入口(): void
    {
        foreach (['alipay', 'douyin', 'weibo', 'github'] as $platform) {
            $this->setConfig($platform . '_enabled', '1');
        }

        $this->get('/sso/login')
            ->assertOk()
            ->assertSee('其他登录方式')
            ->assertSee('id="alipay-login-btn"', false)
            ->assertSee('支付宝')
            ->assertSee('id="douyin-login-btn"', false)
            ->assertSee('抖音')
            ->assertSee('id="weibo-login-btn"', false)
            ->assertSee('微博')
            ->assertSee('id="github-login-btn"', false)
            ->assertSee('GitHub');
    }

    public function test_独立站仅开启指定平台只显示对应入口(): void
    {
        $this->setConfig('weibo_enabled', '1');

        $this->get('/sso/login')
            ->assertOk()
            ->assertSee('id="weibo-login-btn"', false)
            ->assertDontSee('id="alipay-login-btn"', false)
            ->assertDontSee('id="douyin-login-btn"', false)
            ->assertDontSee('id="github-login-btn"', false);
    }

    // ---------------------------------------------------------------- 2. 快捷模式中转客户端

    public function test_中转客户端模式下按启用状态显示平台入口(): void
    {
        // 一键进入中转客户端配置
        $this->configRelayClient();

        // 中转客户端同样按后台启用状态显示支付宝/抖音/微博/GitHub 入口，点击由服务端代跳中转
        foreach (['alipay', 'douyin', 'weibo', 'github'] as $platform) {
            $this->setConfig($platform . '_enabled', '1');
        }

        $this->get('/sso/login')
            ->assertOk()
            ->assertSee('id="alipay-login-btn"', false)
            ->assertSee('id="douyin-login-btn"', false)
            ->assertSee('id="weibo-login-btn"', false)
            ->assertSee('id="github-login-btn"', false);
    }

    // ---------------------------------------------------------------- 辅助

    protected function configRelayClient(): void
    {
        $this->setConfig('quick_mode', 'client');
        $this->setConfig('relay_server_url', 'https://sso.example.com');
        $this->setConfig('relay_client_id', 'sso_test_client');
        $this->setConfig('relay_client_secret', 'test_secret');
    }

    protected function setConfig(string $name, string $value): void
    {
        DB::table('config_items')->updateOrInsert(
            ['code' => 'app_cmspro_sso_' . $name],
            [
                'name'        => $name,
                'value'       => $value,
                'group_id'    => 0,
                'type'        => 'text',
                'sort'        => 0,
                'status'      => 1,
                'create_time' => now(),
                'update_time' => now(),
            ]
        );
    }

    protected function createTables(): void
    {
        if (!Schema::hasTable('config_groups')) {
            Schema::create('config_groups', function ($table) {
                $table->unsignedBigInteger('id', true);
                $table->string('name', 50);
                $table->string('code', 50)->unique();
                $table->string('app_id', 50)->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
            });
        }

        if (!Schema::hasTable('config_items')) {
            Schema::create('config_items', function ($table) {
                $table->unsignedBigInteger('id', true);
                $table->unsignedBigInteger('group_id')->default(0);
                $table->string('name', 100);
                $table->string('code', 150);
                $table->text('value')->nullable();
                $table->string('type', 20)->default('text');
                $table->text('options')->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->unsignedTinyInteger('status')->default(1);
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
            });
        }
    }
}