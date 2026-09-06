<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Models\User;
use App\Models\UserThirdParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 独立站社交新用户分流页账号绑定测试
 *
 * 覆盖：独立站 complete 页双 Tab 渲染（凭 token、未登录）、凭授权句柄绑定既有账号成功、
 * 密码错误计数与锁定、token 无效/平台不匹配、openid 冲突、非法平台拦截、停用账号拒绝。
 *
 * 背景：社交新用户回调后不再自动建号，授权数据暂存 Cache 跳分流页；
 * 绑定接口凭 token + 账号密码完成绑定登录，不再要求当前登录态持有平台绑定。
 */
class SsoBindExistingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. complete 页双 Tab 渲染

    public function test_独立站微信分流页凭token渲染双Tab(): void
    {
        $token = $this->putOauthData();

        // 未登录（分流页前提），凭 token 访问
        $this->get('/sso/auth/wechat/complete?token=' . $token)
            ->assertOk()
            ->assertSee('完善资料')
            ->assertSee('账号绑定')
            ->assertSee('id="setup-nickname"', false)
            ->assertSee('id="bind-account"', false);
    }

    // ---------------------------------------------------------------- 2. 账号绑定既有账号

    public function test_微信新用户凭token绑定既有账号成功(): void
    {
        // 既有账号（社交身份将绑定到该账号），无任何登录态
        $existing = $this->createLocalUser('bind_old_1', 'old_pwd_123');
        $token = $this->putOauthData();

        $response = $this->postJson('/api/cmspro/sso/auth/wechat/bind-existing', [
            'token'    => $token,
            'account'  => 'bind_old_1',
            'password' => 'old_pwd_123',
        ])->assertOk()->assertJsonPath('code', 0);

        // 绑定关系建立到既有账号，登录态切换为既有账号
        $binding = UserThirdParty::where('platform', 'wechat')->where('open_id', 'wx_888')->first();
        $this->assertNotNull($binding);
        $this->assertSame($existing->id, (int) $binding->user_id);
        $this->assertAuthenticated('web');
        $this->assertSame($existing->id, (int) auth('web')->id());

        // token 已消费，未创建新账号
        $this->assertNull(Cache::get('cmspro_sso_oauth_data:' . $token));
        $this->assertSame(1, User::count());
        $this->assertArrayHasKey('redirect', (array) $response->json('data'));
    }

    public function test_绑定密码错误返回400(): void
    {
        $this->createLocalUser('bind_old_2', 'right_pwd_123');
        $token = $this->putOauthData();

        $this->postJson('/api/cmspro/sso/auth/wechat/bind-existing', [
            'token'    => $token,
            'account'  => 'bind_old_2',
            'password' => 'wrong_pwd',
        ])->assertStatus(400)->assertJsonPath('message', '账号或密码错误');

        // 未绑定、token 保留（用户可切换到完善资料 Tab 重新操作）
        $this->assertSame(0, UserThirdParty::where('platform', 'wechat')->count());
        $this->assertGuest('web');
        $this->assertNotNull(Cache::get('cmspro_sso_oauth_data:' . $token));
    }

    public function test_绑定失败5次后锁定(): void
    {
        $this->createLocalUser('bind_old_3', 'right_pwd_123');
        $token = $this->putOauthData();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/cmspro/sso/auth/wechat/bind-existing', [
                'token'    => $token,
                'account'  => 'bind_old_3',
                'password' => 'wrong_pwd',
            ]);
        }

        // 第 6 次即使密码正确也锁定（失败锁定策略与账号密码登录一致）
        $this->postJson('/api/cmspro/sso/auth/wechat/bind-existing', [
            'token'    => $token,
            'account'  => 'bind_old_3',
            'password' => 'right_pwd_123',
        ])->assertStatus(429);
    }

    public function test_token无效返回400(): void
    {
        $this->createLocalUser('bind_old_4', 'old_pwd_123');

        $this->postJson('/api/cmspro/sso/auth/wechat/bind-existing', [
            'token'    => str_repeat('a', 32),
            'account'  => 'bind_old_4',
            'password' => 'old_pwd_123',
        ])->assertStatus(400)->assertJsonPath('message', '登录会话已过期，请重新登录');

        $this->assertGuest('web');
    }

    public function test_token平台不匹配返回400(): void
    {
        // wechat 授权数据提交到 qq 绑定接口：平台校验失败按会话过期处理
        $token = $this->putOauthData();
        $this->createLocalUser('bind_old_5', 'old_pwd_123');

        $this->postJson('/api/cmspro/sso/auth/qq/bind-existing', [
            'token'    => $token,
            'account'  => 'bind_old_5',
            'password' => 'old_pwd_123',
        ])->assertStatus(400)->assertJsonPath('message', '登录会话已过期，请重新登录');
    }

    public function test_openid已被其他账号绑定时拒绝(): void
    {
        // openid 已被他人占用（并发场景），凭 token 绑定其他账号应被拒绝
        $owner = $this->createLocalUser('bind_old_6', 'bind_pwd_678');
        UserThirdParty::create([
            'user_id'  => $owner->id,
            'platform' => 'wechat',
            'open_id'  => 'wx_888',
        ]);
        $other = $this->createLocalUser('bind_old_7', 'bind_pwd_789');
        $token = $this->putOauthData();

        $this->postJson('/api/cmspro/sso/auth/wechat/bind-existing', [
            'token'    => $token,
            'account'  => 'bind_old_7',
            'password' => 'bind_pwd_789',
        ])->assertStatus(400)->assertJsonPath('message', '该平台身份已绑定其他账号，请直接登录');

        // 绑定关系未被改动，token 已消费（不留下可重试的冲突会话）
        $this->assertSame($owner->id, (int) UserThirdParty::where('platform', 'wechat')->where('open_id', 'wx_888')->value('user_id'));
        $this->assertNull(Cache::get('cmspro_sso_oauth_data:' . $token));
        $this->assertGuest('web');
    }

    public function test_非法平台被拒绝(): void
    {
        $this->postJson('/api/cmspro/sso/auth/phone/bind-existing', [
            'token'    => str_repeat('a', 32),
            'account'  => 'x',
            'password' => 'y',
        ])->assertStatus(400)->assertJsonPath('message', '不支持的绑定平台');
    }

    public function test_停用账号绑定被拒绝(): void
    {
        $this->createLocalUser('bind_old_8', 'bind_pwd_890', ['status' => 0]);
        $token = $this->putOauthData();

        $this->postJson('/api/cmspro/sso/auth/wechat/bind-existing', [
            'token'    => $token,
            'account'  => 'bind_old_8',
            'password' => 'bind_pwd_890',
        ])->assertStatus(400)->assertJsonPath('message', '账号或密码错误');

        $this->assertGuest('web');
    }

    // ---------------------------------------------------------------- 辅助方法

    /**
     * 直接写入一条微信暂存授权数据（模拟回调暂存），返回 token
     */
    protected function putOauthData(): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put('cmspro_sso_oauth_data:' . $token, [
            'openid'        => 'wx_888',
            'unionid'       => null,
            'nickname'      => '微信昵称',
            'avatar'        => 'https://a.b/wx.png',
            'access_token'  => '',
            'refresh_token' => '',
            'platform'      => 'wechat',
            'raw_data'      => ['id' => 888],
        ], 600);

        return $token;
    }

    protected function createLocalUser(string $username, string $password, array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => $username,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
            'nickname' => $username,
            'status'   => 1,
        ], $overrides));
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
