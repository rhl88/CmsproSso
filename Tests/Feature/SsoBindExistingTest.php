<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Models\User;
use App\Models\UserThirdParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 独立站完善资料页账号绑定测试
 *
 * 覆盖：独立站 complete 页双 Tab 渲染（完善资料/账号绑定）、绑定既有账号成功（绑定转移+切换登录）、
 * 密码错误计数、未绑定平台/未登录/非法平台拦截、目标账号即当前账号时直接切换。
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

    public function test_独立站微信完善资料页渲染双Tab(): void
    {
        // 模拟首次微信登录已自动建号并登录
        $user = $this->createLocalUser('wx_new_1', 'random_secret');
        $this->actingAs($user, 'web');

        $this->get('/sso/auth/wechat/complete')
            ->assertOk()
            ->assertSee('完善资料')
            ->assertSee('账号绑定')
            ->assertSee('id="setup-nickname"', false)
            ->assertSee('id="bind-account"', false);
    }

    // ---------------------------------------------------------------- 2. 账号绑定既有账号

    public function test_微信首次登录绑定既有账号成功(): void
    {
        // 当前首次微信登录已自动建号并绑定（open_id=wx_888）
        $current = $this->createLocalUser('wx_new_2', 'random_secret_2');
        UserThirdParty::create([
            'user_id'  => $current->id,
            'platform' => 'wechat',
            'open_id'  => 'wx_888',
        ]);
        $this->actingAs($current, 'web');

        // 目标既有账号
        $existing = $this->createLocalUser('bind_old_1', 'old_pwd_123');

        $response = $this->postJson('/api/cmspro/sso/auth/wechat/bind-existing', [
            'account'  => 'bind_old_1',
            'password' => 'old_pwd_123',
        ])->assertOk()->assertJsonPath('code', 0);

        // 绑定关系已转移给既有账号，登录态切换为既有账号
        $binding = UserThirdParty::where('platform', 'wechat')->where('open_id', 'wx_888')->first();
        $this->assertNotNull($binding);
        $this->assertSame($existing->id, (int) $binding->user_id);
        $this->assertAuthenticated('web');
        $this->assertSame($existing->id, (int) auth('web')->id());
    }

    public function test_绑定密码错误返回400(): void
    {
        $current = $this->createLocalUser('wx_new_3', 'random_secret_3');
        UserThirdParty::create([
            'user_id'  => $current->id,
            'platform' => 'wechat',
            'open_id'  => 'wx_999',
        ]);
        $this->actingAs($current, 'web');

        $this->createLocalUser('bind_old_2', 'right_pwd_123');

        $this->postJson('/api/cmspro/sso/auth/wechat/bind-existing', [
            'account'  => 'bind_old_2',
            'password' => 'wrong_pwd',
        ])->assertStatus(400)->assertJsonPath('message', '账号或密码错误');

        // 绑定关系未被改动，仍挂当前账号
        $this->assertSame(
            $current->id,
            (int) UserThirdParty::where('platform', 'wechat')->where('open_id', 'wx_999')->value('user_id')
        );
    }

    public function test_未绑定当前平台返回400(): void
    {
        $current = $this->createLocalUser('wx_new_4', 'random_secret_4');
        // 当前账号未绑定该平台（正常首次登录必有绑定，此场景防御）
        $this->actingAs($current, 'web');

        $this->createLocalUser('bind_old_3', 'old_pwd_123');

        $this->postJson('/api/cmspro/sso/auth/wechat/bind-existing', [
            'account'  => 'bind_old_3',
            'password' => 'old_pwd_123',
        ])->assertStatus(400)->assertJsonPath('message', '当前账号未绑定该平台，无法完成绑定');
    }

    public function test_未登录返回401(): void
    {
        $this->postJson('/api/cmspro/sso/auth/wechat/bind-existing', [
            'account'  => 'whatever',
            'password' => 'whatever_pwd',
        ])->assertStatus(401);
    }

    public function test_非法平台被拒绝(): void
    {
        $current = $this->createLocalUser('wx_new_5', 'random_secret_5');
        $this->actingAs($current, 'web');

        $this->postJson('/api/cmspro/sso/auth/phone/bind-existing', [
            'account'  => 'x',
            'password' => 'y',
        ])->assertStatus(400)->assertJsonPath('message', '不支持的绑定平台');
    }

    public function test_目标账号即当前账号直接切换登录(): void
    {
        $current = $this->createLocalUser('wx_new_6', 'random_secret_6');
        UserThirdParty::create([
            'user_id'  => $current->id,
            'platform' => 'wechat',
            'open_id'  => 'wx_777',
        ]);
        $this->actingAs($current, 'web');

        $this->postJson('/api/cmspro/sso/auth/wechat/bind-existing', [
            'account'  => 'wx_new_6',
            'password' => 'random_secret_6',
        ])->assertOk()->assertJsonPath('code', 0);

        $this->assertAuthenticated('web');
        $this->assertSame($current->id, (int) auth('web')->id());
    }

    // ---------------------------------------------------------------- 辅助方法

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