<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Models\User;
use App\Models\UserThirdParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * 统一登录应用 ·「记住登录状态」端到端测试
 *
 * 覆盖场景：
 * 1. 密码登录勾选 remember → 下发 remember cookie + 写入 remember_token
 * 2. 密码登录不勾选 remember → 无 remember cookie + remember_token 为空
 * 3. 手机验证码登录勾选 remember → 下发 remember cookie + 写入 remember_token
 * 4. OAuth 微信回调（老用户）+ login-url 带 remember → session 传递偏好 → 下发 remember cookie
 */
class SsoRememberLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. 密码登录

    public function test_密码登录勾选remember下发cookie并写入token(): void
    {
        $user = $this->createLocalUser('remember_user', 'secret123');

        $response = $this->postJson('/api/cmspro/sso/auth/login', [
            'account'  => 'remember_user',
            'password' => 'secret123',
            'remember'  => 1,
        ])->assertOk()->assertJsonPath('code', 0);

        $cookie = $this->extractRememberCookie($response);
        $this->assertNotNull($cookie, '应下发 remember_web_* cookie');

        $user->refresh();
        $this->assertNotNull($user->remember_token, 'users.remember_token 应已写入');
    }

    public function test_密码登录不勾选remember不下发cookie且token为空(): void
    {
        $user = $this->createLocalUser('plain_user', 'secret123');

        $response = $this->postJson('/api/cmspro/sso/auth/login', [
            'account'  => 'plain_user',
            'password' => 'secret123',
        ])->assertOk()->assertJsonPath('code', 0);

        $cookie = $this->extractRememberCookie($response);
        $this->assertNull($cookie, '不应下发 remember cookie');

        $user->refresh();
        $this->assertNull($user->remember_token, 'users.remember_token 应为空');
    }

    // ---------------------------------------------------------------- 2. 手机验证码登录

    public function test_手机验证码登录勾选remember下发cookie并写入token(): void
    {
        $this->setConfig('phone_login_enabled', '1');

        $user = $this->createLocalUser('phone_user', 'secret123', ['phone' => '13800138001']);
        $phone = '13800138001';
        $code = '123456';
        Cache::put('cmspro_sso_phone_code:' . $phone, $code, now()->addMinutes(5));

        $response = $this->postJson('/api/cmspro/sso/auth/phone/login', [
            'phone'   => $phone,
            'code'    => $code,
            'remember' => 1,
        ])->assertOk()->assertJsonPath('code', 0);

        $cookie = $this->extractRememberCookie($response);
        $this->assertNotNull($cookie, '应下发 remember_web_* cookie');

        $user->refresh();
        $this->assertNotNull($user->remember_token, 'users.remember_token 应已写入');
    }

    // ---------------------------------------------------------------- 3. OAuth 微信回调（老用户）

    public function test_OAuth微信回调老用户带remember下发cookie(): void
    {
        $this->configWechatProxy();

        $user = $this->createLocalUser('wx_remember_user', 'secret123');
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'wechat',
            'open_id'  => 'wx_remember_888',
        ]);

        // 发起微信登录 URL 请求，带 remember=1（session 存入 sso_remember）
        $urlResponse = $this->getJson('/api/cmspro/sso/auth/wechat/login-url?' . http_build_query([
            'remember' => 1,
        ]))->assertOk();

        $state = (string) $urlResponse->json('data.state');

        // 模拟微信外挂代理回调
        $response = $this->get('/sso/auth/wechat/callback?' . http_build_query([
            'openid'   => 'wx_remember_888',
            'state'    => $state,
            'nickname' => '微信昵称',
        ]));

        $response->assertRedirect();

        // OAuth 回调建立登录态后应下发 remember cookie
        $cookie = $this->extractRememberCookie($response);
        $this->assertNotNull($cookie, 'OAuth 回调应下发 remember_web_* cookie');

        $user->refresh();
        $this->assertNotNull($user->remember_token, 'users.remember_token 应已写入');
    }

    public function test_OAuth微信回调老用户不带remember不下发cookie(): void
    {
        $this->configWechatProxy();

        $user = $this->createLocalUser('wx_plain_user', 'secret123');
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'wechat',
            'open_id'  => 'wx_plain_888',
        ]);

        // 发起微信登录 URL 请求，不带 remember（session 存入 sso_remember=false）
        $urlResponse = $this->getJson('/api/cmspro/sso/auth/wechat/login-url')->assertOk();

        $state = (string) $urlResponse->json('data.state');

        $response = $this->get('/sso/auth/wechat/callback?' . http_build_query([
            'openid'   => 'wx_plain_888',
            'state'    => $state,
            'nickname' => '微信昵称',
        ]));

        $response->assertRedirect();

        $cookie = $this->extractRememberCookie($response);
        $this->assertNull($cookie, '不勾选 remember 时不应下发 cookie');

        $user->refresh();
        $this->assertNull($user->remember_token, 'users.remember_token 应为空');
    }

    // ---------------------------------------------------------------- 辅助方法

    /**
     * 从响应中提取 remember cookie
     */
    protected function extractRememberCookie($response): ?Cookie
    {
        return collect($response->headers->getCookies())
            ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
    }

    protected function configWechatProxy(): void
    {
        $this->setConfig('wechat_enabled', '1');
        $this->setConfig('wechat_mp_app_id', 'wx_test_app_id');
        $this->setConfig('wechat_mp_app_secret', 'wx_test_secret');
        $this->setConfig('wechat_oauth_enabled', '1');
        $this->setConfig('wechat_oauth_url', 'https://proxy.example.com/wx');
    }

    protected function createLocalUser(string $username, string $password, array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => $username,
            'password' => Hash::make($password),
            'nickname' => $username,
            'status'   => 1,
        ], $overrides));
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

        if (!Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function ($table) {
                $table->bigIncrements('id');
                $table->string('name', 50);
                $table->unsignedTinyInteger('is_default')->default(0);
                $table->unsignedTinyInteger('status')->default(1);
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
            });
        }

        if (!Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->bigIncrements('id');
                $table->string('username', 50)->unique();
                $table->string('password');
                $table->string('nickname', 100)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('phone', 20)->nullable();
                $table->timestamp('phone_verified_at')->nullable();
                $table->string('avatar', 500)->nullable();
                $table->unsignedBigInteger('group_id')->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->unsignedInteger('login_count')->default(0);
                $table->string('remember_token', 100)->nullable();
                $table->timestamp('last_login_at')->nullable();
                $table->string('last_login_ip', 45)->nullable();
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
            });
        }

        if (!Schema::hasTable('user_third_party')) {
            Schema::create('user_third_party', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->index();
                $table->string('platform', 32)->default('wechat');
                $table->string('open_id', 128);
                $table->string('union_id', 128)->nullable();
                $table->string('nickname', 100)->nullable();
                $table->string('avatar', 500)->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->text('raw_data')->nullable();
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
                $table->unique(['platform', 'open_id']);
            });
        }

        if (!Schema::hasTable('app_cmspro_sso_login_logs')) {
            Schema::create('app_cmspro_sso_login_logs', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('platform', 20)->default('password');
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->string('message', 255)->nullable();
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
            });
        }
    }
}
