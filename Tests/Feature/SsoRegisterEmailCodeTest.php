<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 注册邮箱验证码 + 找回密码页测试
 *
 * 覆盖：已配置邮箱时注册必须带有效验证码（错误/未填拒绝、正确通过并落库邮箱、验证码一次性失效）、
 *       未配置邮箱时注册跳过验证码、找回密码页可访问性（未配置时重定向登录页）。
 */
class SsoRegisterEmailCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);

        UserGroup::firstOrCreate(
            ['code' => 'default'],
            ['name' => '默认组', 'is_default' => 1, 'status' => 1]
        );
        // 模拟后台已配置邮箱（SMTP 用户名已填写）
        config(['mail.mailers.smtp.username' => 'smtp@test.com']);
    }

    public function test_未填验证码注册被拒(): void
    {
        $this->postJson('/api/cmspro/sso/auth/register', [
            'username' => 'ssotest_user1',
            'password' => '123456',
            'password_confirmation' => '123456',
            'email' => 'ssouser1@test.com',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['username' => 'ssotest_user1']);
    }

    public function test_错误验证码注册被拒(): void
    {
        Cache::put('user_register_code:ssouser2@test.com', '111111', now()->addMinutes(10));

        $this->postJson('/api/cmspro/sso/auth/register', [
            'username' => 'ssotest_user2',
            'password' => '123456',
            'password_confirmation' => '123456',
            'email' => 'ssouser2@test.com',
            'email_code' => '999999',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['username' => 'ssotest_user2']);
    }

    public function test_正确验证码注册成功(): void
    {
        Cache::put('user_register_code:ssouser3@test.com', '654321', now()->addMinutes(10));

        $this->postJson('/api/cmspro/sso/auth/register', [
            'username' => 'ssotest_user3',
            'password' => '123456',
            'password_confirmation' => '123456',
            'email' => 'ssouser3@test.com',
            'email_code' => '654321',
        ])->assertOk()->assertJsonPath('code', 0);

        $this->assertDatabaseHas('users', ['username' => 'ssotest_user3', 'email' => 'ssouser3@test.com']);
        // 验证码一次性失效
        $this->assertNull(Cache::get('user_register_code:ssouser3@test.com'));
    }

    public function test_未配置邮箱时注册无需验证码(): void
    {
        config(['mail.mailers.smtp.username' => null]);

        $this->postJson('/api/cmspro/sso/auth/register', [
            'username' => 'ssotest_user4',
            'password' => '123456',
            'password_confirmation' => '123456',
        ])->assertOk()->assertJsonPath('code', 0);

        $this->assertDatabaseHas('users', ['username' => 'ssotest_user4']);
    }

    public function test_找回密码页可访问(): void
    {
        $this->get('/sso/forgot-password')->assertOk();
    }

    public function test_未配置邮箱时找回密码页重定向登录页(): void
    {
        config(['mail.mailers.smtp.username' => null]);

        $this->get('/sso/forgot-password')->assertRedirect('/sso/login');
    }

    /**
     * 子域名模式下，SSO 页面跨域调用主站验证码/重置接口需 CORS 放行
     * 验证：绑定域名 Origin 的预检与实际请求均返回正确的 CORS 响应头
     */
    public function test_绑定子域名跨域请求验证码接口被CORS放行(): void
    {
        // 通过反射模拟已绑定的 SSO 子域名
        $prop = new \ReflectionProperty(\App\Providers\AppServiceProvider::class, 'boundDomains');
        $prop->setAccessible(true);
        $prop->setValue(null, ['sso.test.com']);

        $origin = 'http://sso.test.com';

        // 预检请求（浏览器跨域 POST application/json 前置触发）
        // 页面 $.ajaxSetup 全局携带 X-CSRF-TOKEN，预检会要求服务端放行该头
        $this->call('OPTIONS', 'api/user/register/email-code', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, x-csrf-token',
        ])->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        // 找回密码两个接口的预检同样放行
        $this->call('OPTIONS', 'api/user/password/email-code', [], [], [], [
            'HTTP_ORIGIN' => $origin,
        ])->assertStatus(204)->assertHeader('Access-Control-Allow-Origin', $origin);

        $this->call('OPTIONS', 'api/user/password/reset-by-code', [], [], [], [
            'HTTP_ORIGIN' => $origin,
        ])->assertStatus(204)->assertHeader('Access-Control-Allow-Origin', $origin);
    }

    public function test_未绑定域名Origin不放行CORS(): void
    {
        $prop = new \ReflectionProperty(\App\Providers\AppServiceProvider::class, 'boundDomains');
        $prop->setAccessible(true);
        $prop->setValue(null, ['sso.test.com']);

        $response = $this->call('OPTIONS', 'api/user/register/email-code', [], [], [], [
            'HTTP_ORIGIN' => 'http://evil.example.com',
        ]);

        $this->assertNotEquals('http://evil.example.com', $response->headers->get('Access-Control-Allow-Origin'));
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

        if (!Schema::hasTable('app_cmspro_sso_login_logs')) {
            Schema::create('app_cmspro_sso_login_logs', function ($table) {
                $table->unsignedBigInteger('id', true);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('platform', 30)->default('');
                $table->string('ip', 50)->default('');
                $table->string('user_agent', 500)->default('');
                $table->unsignedTinyInteger('status')->default(1);
                $table->string('message', 255)->default('');
                $table->timestamp('create_time')->nullable();
            });
        }
    }
}
