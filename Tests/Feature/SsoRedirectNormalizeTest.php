<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 登录/注册页跳转地址规范化测试（覆盖系统托管场景）
 *
 * 开启「覆盖系统」后，托管系统登录/注册页被重定向到本应用时透传的 redirect 需二次处理，
 * 登录/注册成功后才能正确回到托管系统的最终目标页：
 *   嵌套托管登录/注册页完整 URL（中间件 fullUrl 透传）→ 提取内层 redirect；
 *   相对路径 → 补全主站域名成绝对地址（子域名模式下相对跳转会落到 SSO 子域）；
 *   绝对地址 → 同主域原样保留；外域丢弃，登录后走默认跳转。
 */
class SsoRedirectNormalizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 固定主站域名（mainSiteBase 取 config('app.url') 的 host），便于精确断言
        config(['app.url' => 'http://www.cmspro.com']);
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. 登录页 redirect 规范化

    public function test_登录页相对路径redirect补全主站域名(): void
    {
        $this->get('/sso/login?redirect=' . urlencode('/apps/cmspro.domainmanager'))
            ->assertOk()
            ->assertSee('var redirect = ' . json_encode('http://www.cmspro.com/apps/cmspro.domainmanager'), false);
    }

    public function test_登录页嵌套托管登录页redirect提取内层并规范化(): void
    {
        // 覆盖系统中间件以完整 URL（fullUrl）透传托管登录页地址
        $nested = 'http://www.cmspro.com/user/login?redirect=' . urlencode('/apps/cmspro.domainmanager');

        $this->get('/sso/login?redirect=' . urlencode($nested))
            ->assertOk()
            ->assertSee('var redirect = ' . json_encode('http://www.cmspro.com/apps/cmspro.domainmanager'), false);
    }

    public function test_登录页绝对地址redirect原样保留(): void
    {
        $this->get('/sso/login?redirect=' . urlencode('http://www.cmspro.com/apps/cmspro.domainmanager'))
            ->assertOk()
            ->assertSee('var redirect = ' . json_encode('http://www.cmspro.com/apps/cmspro.domainmanager'), false);
    }

    public function test_登录页嵌套无内层redirect时为空(): void
    {
        $nested = 'http://www.cmspro.com/user/login';

        $this->get('/sso/login?redirect=' . urlencode($nested))
            ->assertOk()
            ->assertSee('var redirect = "";', false);
    }

    public function test_登录页外域redirect丢弃(): void
    {
        $this->get('/sso/login?redirect=' . urlencode('http://evil.example.com/steal'))
            ->assertOk()
            ->assertSee('var redirect = "";', false);
    }

    public function test_登录页无redirect时为空(): void
    {
        $this->get('/sso/login')
            ->assertOk()
            ->assertSee('var redirect = "";', false);
    }

    // ---------------------------------------------------------------- 2. 注册页 redirect 规范化

    public function test_注册页相对路径redirect补全主站域名(): void
    {
        $this->get('/sso/register?redirect=' . urlencode('/apps/cmspro.domainmanager'))
            ->assertOk()
            ->assertSee('var redirect = ' . json_encode('http://www.cmspro.com/apps/cmspro.domainmanager'), false);
    }

    public function test_注册页嵌套托管注册页redirect提取内层并规范化(): void
    {
        $nested = 'http://www.cmspro.com/user/register?redirect=' . urlencode('/apps/cmspro.domainmanager');

        $this->get('/sso/register?redirect=' . urlencode($nested))
            ->assertOk()
            ->assertSee('var redirect = ' . json_encode('http://www.cmspro.com/apps/cmspro.domainmanager'), false);
    }

    public function test_注册页绝对地址redirect原样保留(): void
    {
        $this->get('/sso/register?redirect=' . urlencode('http://www.cmspro.com/apps/cmspro.domainmanager'))
            ->assertOk()
            ->assertSee('var redirect = ' . json_encode('http://www.cmspro.com/apps/cmspro.domainmanager'), false);
    }

    public function test_注册页外域redirect丢弃(): void
    {
        $this->get('/sso/register?redirect=' . urlencode('http://evil.example.com/steal'))
            ->assertOk()
            ->assertSee('var redirect = "";', false);
    }

    // ---------------------------------------------------------------- 3. 已登录访问直接跳转

    public function test_已登录访问登录页带嵌套地址直接跳转规范化目标(): void
    {
        $this->actingAs($this->createUser(), 'web');

        $nested = 'http://www.cmspro.com/user/login?redirect=' . urlencode('/apps/cmspro.domainmanager');

        $this->get('/sso/login?redirect=' . urlencode($nested))
            ->assertRedirect('http://www.cmspro.com/apps/cmspro.domainmanager');
    }

    public function test_已登录访问注册页带相对地址直接跳转主站目标(): void
    {
        $this->actingAs($this->createUser(), 'web');

        $this->get('/sso/register?redirect=' . urlencode('/apps/cmspro.domainmanager'))
            ->assertRedirect('http://www.cmspro.com/apps/cmspro.domainmanager');
    }

    // ---------------------------------------------------------------- 辅助

    protected function createUser(): User
    {
        return User::create([
            'username' => 'tester',
            'name'     => 'Tester',
            'email'    => 'tester@example.com',
            'password' => Hash::make('password'),
        ]);
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

        if (!Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->bigIncrements('id');
                $table->string('username', 50)->default('');
                $table->string('name', 50)->nullable();
                $table->string('email', 100)->nullable();
                $table->string('phone', 20)->nullable();
                $table->string('password', 255)->default('');
                $table->string('nickname', 50)->nullable();
                $table->string('avatar', 255)->nullable();
                $table->unsignedTinyInteger('gender')->default(0);
                $table->string('signature', 255)->nullable();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('remember_token', 100)->nullable();
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
            });
        }
    }
}
