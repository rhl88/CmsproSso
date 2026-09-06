<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Models\User;
use App\Models\UserThirdParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 快捷登录新用户分流测试
 *
 * 覆盖：relay 回调新用户跳分流页（带 token）、完善资料建号、账号绑定已有账号、
 * 密码错误/锁定、openid 冲突、会话过期、微信/QQ 老用户直达逻辑不受影响。
 */
class SsoRelayCompleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. 回调分流

    public function test_中转回调新用户跳转分流页带token(): void
    {
        $this->configRelayClient();

        $this->fakeServer();

        [$state] = $this->fetchRelayState('https://bbs.example.com/target');
        $callback = $this->get('/sso/auth/relay/callback?' . http_build_query([
            'code'  => 'SERVER_CODE_NEW_1',
            'state' => $state,
        ]));
        $callback->assertRedirect();

        $location = (string) $callback->headers->get('Location');
        $this->assertStringContainsString('/auth/relay/complete?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['token'] ?? '');
        $this->assertSame('https://bbs.example.com/target', $query['redirect'] ?? '');

        // 授权数据已暂存，未建号
        $this->assertNotNull(Cache::get('cmspro_sso_oauth_data:' . $query['token']));
        $this->assertSame(0, UserThirdParty::where('platform', 'relay')->count());
        $this->assertGuest('web');
    }

    public function test_中转回调老用户直接登录不跳分流页(): void
    {
        $this->configRelayClient();

        $user = $this->createLocalUser('old_user_1', 'secret_pwd_1');
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'relay',
            'open_id'  => 'sso_888',
        ]);

        $this->fakeServer();

        [$state] = $this->fetchRelayState();

        $callback = $this->get('/sso/auth/relay/callback?' . http_build_query([
            'code'  => 'SERVER_CODE_OLD_1',
            'state' => $state,
        ]));
        $callback->assertRedirect();

        // 老用户直接建立登录态，跳转默认页（非分流页）
        $location = (string) $callback->headers->get('Location');
        $this->assertStringNotContainsString('/auth/relay/complete?', $location);
        $this->assertAuthenticated('web');
        $this->assertSame($user->id, (int) auth('web')->id());
    }

    public function test_中转回调新用户邀请模式仍跳邀请页(): void
    {
        $this->configRelayClient();
        $this->setConfig('invite_enabled', '1');

        $this->fakeServer();

        [$state] = $this->fetchRelayState();

        $callback = $this->get('/sso/auth/relay/callback?' . http_build_query([
            'code'  => 'SERVER_CODE_INVITE_1',
            'state' => $state,
        ]));
        $callback->assertRedirect();

        $this->assertStringContainsString('/auth/relay/complete-invite?', (string) $callback->headers->get('Location'));
        $this->assertGuest('web');
        $this->assertSame(0, UserThirdParty::where('platform', 'relay')->count());
    }

    // ---------------------------------------------------------------- 2. 完善资料（新建账号）

    public function test_分流页回填服务端授权昵称(): void
    {
        $this->configRelayClient();

        // 暂存授权数据（服务端昵称「服务端昵称」），模拟回调后跳转分流页
        $token = $this->putOauthData();

        $response = $this->get('/sso/auth/relay/complete?token=' . $token);
        $response->assertOk();

        // 页面昵称输入框回填服务端授权昵称
        $content = (string) $response->getContent();
        $this->assertStringContainsString('value="服务端昵称"', $content);
    }

    public function test_分流页token无效时不回填昵称(): void
    {
        $this->configRelayClient();

        $response = $this->get('/sso/auth/relay/complete?token=' . str_repeat('b', 32));
        $response->assertOk();

        // 授权数据不存在，昵称输入框为空值
        $content = (string) $response->getContent();
        $this->assertStringContainsString('id="setup-nickname"', $content);
        $this->assertStringNotContainsString('value="服务端昵称"', $content);
    }

    public function test_分流页完善资料创建账号并登录(): void
    {
        $this->configRelayClient();

        $token = $this->putOauthData();

        $response = $this->postJson('/api/cmspro/sso/auth/relay/setup', [
            'token'    => $token,
            'nickname' => '分流昵称',
            'phone'    => '13800138000',
        ])->assertOk()->assertJsonPath('code', 0);

        // 账号已创建并绑定 relay 身份
        $binding = UserThirdParty::where('platform', 'relay')->where('open_id', 'sso_888')->first();
        $this->assertNotNull($binding);
        $user = User::find($binding->user_id);
        $this->assertNotNull($user);
        $this->assertSame('分流昵称', $user->nickname);
        $this->assertSame('13800138000', $user->phone);

        // 已建立登录态，token 已消费
        $this->assertAuthenticated('web');
        $this->assertSame($user->id, (int) auth('web')->id());
        $this->assertNull(Cache::get('cmspro_sso_oauth_data:' . $token));
    }

    public function test_分流页完善资料token无效返回400(): void
    {
        $this->configRelayClient();

        $this->postJson('/api/cmspro/sso/auth/relay/setup', [
            'token' => str_repeat('a', 32),
        ])->assertStatus(400)->assertJsonPath('message', '登录会话已过期，请重新登录');
    }

    // ---------------------------------------------------------------- 3. 账号绑定

    public function test_分流页绑定已有账号成功(): void
    {
        $this->configRelayClient();

        $existing = $this->createLocalUser('bind_user_1', 'bind_pwd_123');
        $token = $this->putOauthData();

        $response = $this->postJson('/api/cmspro/sso/auth/relay/bind', [
            'token'    => $token,
            'account'  => 'bind_user_1',
            'password' => 'bind_pwd_123',
        ])->assertOk()->assertJsonPath('code', 0);

        // 绑定关系建立到已有账号
        $binding = UserThirdParty::where('platform', 'relay')->where('open_id', 'sso_888')->first();
        $this->assertNotNull($binding);
        $this->assertSame($existing->id, (int) $binding->user_id);

        // 登录态切换为已有账号，token 已消费，未创建新账号
        $this->assertAuthenticated('web');
        $this->assertSame($existing->id, (int) auth('web')->id());
        $this->assertNull(Cache::get('cmspro_sso_oauth_data:' . $token));
        $this->assertSame(1, User::count());
    }

    public function test_分流页绑定密码错误返回400(): void
    {
        $this->configRelayClient();

        $this->createLocalUser('bind_user_2', 'right_pwd_123');
        $token = $this->putOauthData();

        $this->postJson('/api/cmspro/sso/auth/relay/bind', [
            'token'    => $token,
            'account'  => 'bind_user_2',
            'password' => 'wrong_pwd',
        ])->assertStatus(400)->assertJsonPath('message', '账号或密码错误');

        // 未绑定、token 保留（用户可切换到完善资料 Tab 重新操作）
        $this->assertSame(0, UserThirdParty::where('platform', 'relay')->count());
        $this->assertGuest('web');
    }

    public function test_分流页绑定失败5次后锁定(): void
    {
        $this->configRelayClient();

        $this->createLocalUser('bind_user_3', 'right_pwd_123');
        $token = $this->putOauthData();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/cmspro/sso/auth/relay/bind', [
                'token'    => $token,
                'account'  => 'bind_user_3',
                'password' => 'wrong_pwd',
            ]);
        }

        // 第 5 次失败后账号锁定
        $this->postJson('/api/cmspro/sso/auth/relay/bind', [
            'token'    => $token,
            'account'  => 'bind_user_3',
            'password' => 'right_pwd_123',
        ])->assertStatus(429);
    }

    public function test_分流页绑定openid已被占用返回400(): void
    {
        $this->configRelayClient();

        $owner = $this->createLocalUser('bind_user_4', 'bind_pwd_456');
        UserThirdParty::create([
            'user_id'  => $owner->id,
            'platform' => 'relay',
            'open_id'  => 'sso_888',
        ]);
        $other = $this->createLocalUser('bind_user_5', 'bind_pwd_567');
        $token = $this->putOauthData();

        $this->postJson('/api/cmspro/sso/auth/relay/bind', [
            'token'    => $token,
            'account'  => 'bind_user_5',
            'password' => 'bind_pwd_567',
        ])->assertStatus(400)->assertJsonPath('message', '该平台身份已绑定其他账号，请直接登录');

        // 绑定关系未被改动，token 已消费
        $this->assertSame($owner->id, (int) UserThirdParty::where('platform', 'relay')->where('open_id', 'sso_888')->value('user_id'));
        $this->assertNull(Cache::get('cmspro_sso_oauth_data:' . $token));
    }

    public function test_分流页绑定停用账号被拒绝(): void
    {
        $this->configRelayClient();

        $this->createLocalUser('bind_user_6', 'bind_pwd_678', ['status' => 0]);
        $token = $this->putOauthData();

        $this->postJson('/api/cmspro/sso/auth/relay/bind', [
            'token'    => $token,
            'account'  => 'bind_user_6',
            'password' => 'bind_pwd_678',
        ])->assertStatus(400)->assertJsonPath('message', '账号或密码错误');

        $this->assertGuest('web');
    }

    // ---------------------------------------------------------------- 辅助方法

    /**
     * 写入快捷模式客户端配置
     */
    protected function configRelayClient(): void
    {
        $this->setConfig('quick_mode', 'client');
        $this->setConfig('relay_server_url', 'https://sso.example.com');
        $this->setConfig('relay_client_id', 'sso_test_client');
        $this->setConfig('relay_client_secret', 'test_secret');
    }

    /**
     * 模拟服务端 token / userinfo 接口（纯跳板透传：返回社交真实 openid，无服务端账号概念）
     */
    protected function fakeServer(): void
    {
        Http::fake([
            'sso.example.com/api/cmspro/sso/oauth/token' => Http::response([
                'code' => 0,
                'data' => ['access_token' => 'server_access_token_1', 'token_type' => 'Bearer', 'expires_in' => 3600],
            ]),
            'sso.example.com/api/cmspro/sso/oauth/userinfo' => Http::response([
                'code' => 0,
                'data' => [
                    'openid'        => 'sso_888',
                    'unionid'       => '',
                    'nickname'      => '服务端昵称',
                    'avatar'        => 'https://a.b/x.png',
                    'access_token'  => 'SOCIAL_AT_1',
                    'refresh_token' => 'SOCIAL_RT_1',
                    'platform'      => 'wechat',
                    'raw_data'      => ['nickname' => '服务端昵称'],
                ],
            ]),
        ]);
    }

    /**
     * 走 login-url 拿到 state（session 已存校验值），返回 [state, state]
     */
    protected function fetchRelayState(string $redirect = ''): array
    {
        $response = $this->getJson('/api/cmspro/sso/auth/relay/login-url?' . http_build_query(array_filter([
            'redirect' => $redirect,
        ])), [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host'  => 'bbs.example.com',
        ])->assertOk();

        $url = (string) $response->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [(string) $query['state']];
    }

    /**
     * 直接写入一条暂存的授权数据，返回 token
     */
    protected function putOauthData(): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put('cmspro_sso_oauth_data:' . $token, [
            'openid'        => 'sso_888',
            'unionid'       => null,
            'nickname'      => '服务端昵称',
            'avatar'        => 'https://a.b/x.png',
            'access_token'  => '',
            'refresh_token' => '',
            'platform'      => 'relay',
            'raw_data'      => ['id' => 888],
        ], 600);

        return $token;
    }

    /**
     * 创建本地测试用户（密码为明文入参，自动哈希）
     */
    protected function createLocalUser(string $username, string $password, array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => $username,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
            'nickname' => $username,
            'status'   => 1,
        ], $overrides));
    }

    /**
     * 写入配置项
     */
    protected function setConfig(string $name, string $value): void
    {
        \Illuminate\Support\Facades\DB::table('config_items')->updateOrInsert(
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

    /**
     * 构建测试所需的最小表结构
     */
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
