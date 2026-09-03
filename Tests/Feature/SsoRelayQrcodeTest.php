<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproSso\Models\AuthCode;
use App\Apps\CmsproSso\Models\Client;
use App\Models\User;
use App\Models\UserThirdParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · PC 扫码中转登录测试
 *
 * 覆盖客户端 PC 端扫码中转闭环（不再整页跳服务端授权页）：
 *   服务端 relay-qrcode（创建扫码会话/门禁/参数校验）
 *        → 手机扫码走 authorize（会话结果写入/提示页跳转/一次性消费）
 *        → relay-status（waiting/confirmed/failed 轮询）
 *   客户端 relayLoginStatus（老用户直接登录/新用户跳分流页/授权失败透传）。
 */
class SsoRelayQrcodeTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. 服务端 relay-qrcode 创建扫码会话

    public function test_非服务端模式创建扫码会话返回404(): void
    {
        // 框架约定：abort(404)，JSON 请求返回 404 状态
        $this->postJson('/api/cmspro/sso/oauth/relay-qrcode')
            ->assertStatus(404);
    }

    public function test_无效客户端创建扫码会话返回400(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->createClient();

        // 不存在的 client_id
        $this->postJson('/api/cmspro/sso/oauth/relay-qrcode', [
            'client_id'    => 'sso_not_exists',
            'redirect_uri' => 'https://app.example.com/auth/relay/callback',
        ])->assertStatus(400);

        // 回调地址不在白名单
        $this->postJson('/api/cmspro/sso/oauth/relay-qrcode', [
            'client_id'    => $this->client->client_id,
            'redirect_uri' => 'https://evil.example.com/callback',
        ])->assertStatus(400);
    }

    public function test_平台未启用创建扫码会话返回400(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->createClient();

        $this->postJson('/api/cmspro/sso/oauth/relay-qrcode', [
            'client_id'    => $this->client->client_id,
            'redirect_uri' => 'https://app.example.com/auth/relay/callback',
            'platform'     => 'qq',
        ])->assertStatus(400)->assertJsonPath('message', '该登录方式未在服务端启用');
    }

    public function test_创建扫码会话返回授权页地址与会话ID(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->createClient();

        $response = $this->postJson('/api/cmspro/sso/oauth/relay-qrcode', [
            'client_id'    => $this->client->client_id,
            'redirect_uri' => 'https://app.example.com/auth/relay/callback',
            'platform'     => 'qq',
        ])->assertOk()->assertJsonPath('code', 0);

        $sessionId = (string) $response->json('data.session_id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $sessionId);
        $this->assertSame(600, $response->json('data.expires_in'));

        // 二维码内容为服务端 authorize 授权页地址（state 携带扫码会话）
        $qrcodeUrl = (string) $response->json('data.qrcode_url');
        $this->assertStringContainsString('/oauth/authorize?', $qrcodeUrl);
        parse_str((string) parse_url($qrcodeUrl, PHP_URL_QUERY), $query);
        $this->assertSame($this->client->client_id, $query['client_id']);
        $this->assertSame('https://app.example.com/auth/relay/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('qq', $query['platform']);
        $this->assertSame('relay_qr:' . $sessionId, $query['state']);

        // 会话已登记（保存回调地址，供 authorize 回调校验）
        $session = Cache::get('cmspro_sso_relay_qr:' . $sessionId);
        $this->assertSame('https://app.example.com/auth/relay/callback', $session['redirect_uri'] ?? null);
    }

    // ---------------------------------------------------------------- 2. 服务端 authorize 与扫码会话联动

    public function test_手机扫码授权写入会话结果并跳提示页(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $user = $this->createUser();
        $this->createClient();

        // 1. 创建扫码会话
        $sessionId = $this->createQrSession('qq');

        // 2. 手机端打开二维码内容（服务端 authorize，已登录）→ 跳提示页而非回跳客户端
        $authorizeQuery = [
            'client_id'     => $this->client->client_id,
            'redirect_uri'  => 'https://app.example.com/auth/relay/callback',
            'response_type' => 'code',
            'platform'      => 'qq',
            'state'         => 'relay_qr:' . $sessionId,
        ];
        $response = $this->actingAs($user, 'web')
            ->get('/sso/oauth/authorize?' . http_build_query($authorizeQuery))
            ->assertRedirect();

        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/auth/relay/pc-success', $location);
        $this->assertStringNotContainsString('app.example.com', $location);

        // 3. 授权码已签发落库
        $this->assertSame(1, AuthCode::count());
        $authCode = AuthCode::first();
        $this->assertSame($this->client->client_id, $authCode->client_id);
        $this->assertSame($user->id, (int) $authCode->user_id);
        $this->assertSame('qq', $authCode->platform);
        $this->assertSame('https://app.example.com/auth/relay/callback', $authCode->redirect_uri);

        // 4. PC 端轮询取回授权码（confirmed）
        $this->getJson('/api/cmspro/sso/oauth/relay-status?session_id=' . $sessionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.code', $authCode->code)
            ->assertJsonPath('data.platform', 'qq');

        // 5. 结果一次性消费，再次轮询回到等待态
        $this->getJson('/api/cmspro/sso/oauth/relay-status?session_id=' . $sessionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting');
    }

    public function test_扫码授权回调地址与会话不一致返回400(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $user = $this->createUser();
        $this->createClient();
        $sessionId = $this->createQrSession('qq');

        // 二维码内容被篡改回调地址（与会话登记不符）
        $this->actingAs($user, 'web')
            ->get('/sso/oauth/authorize?' . http_build_query([
                'client_id'     => $this->client->client_id,
                'redirect_uri'  => 'https://evil.example.com/callback',
                'response_type' => 'code',
                'platform'      => 'qq',
                'state'         => 'relay_qr:' . $sessionId,
            ]))
            ->assertStatus(400);

        // 未写入扫码结果
        $this->getJson('/api/cmspro/sso/oauth/relay-status?session_id=' . $sessionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting');
    }

    public function test_伪造扫码会话授权返回400(): void
    {
        $this->setConfig('quick_mode', 'server');
        $user = $this->createUser();
        $this->createClient();

        $this->actingAs($user, 'web')
            ->get('/sso/oauth/authorize?' . http_build_query([
                'client_id'     => $this->client->client_id,
                'redirect_uri'  => 'https://app.example.com/auth/relay/callback',
                'response_type' => 'code',
                'state'         => 'relay_qr:' . str_repeat('a', 32),
            ]))
            ->assertStatus(400);
    }

    public function test_扫码会话无效轮询返回400(): void
    {
        $this->setConfig('quick_mode', 'server');

        $this->getJson('/api/cmspro/sso/oauth/relay-status?session_id=' . str_repeat('b', 32))
            ->assertStatus(400)->assertJsonPath('message', '扫码会话无效或已过期');

        $this->getJson('/api/cmspro/sso/oauth/relay-status')
            ->assertStatus(400);
    }

    // ---------------------------------------------------------------- 3. 客户端扫码轮询登录

    public function test_客户端未配置扫码轮询返回400(): void
    {
        $this->getJson('/api/cmspro/sso/auth/relay/login-status?session_id=' . str_repeat('c', 32))
            ->assertStatus(400)->assertJsonPath('message', '快捷模式客户端未配置，请联系管理员');
    }

    public function test_缺少会话ID轮询返回400(): void
    {
        $this->configRelayClient();

        $this->getJson('/api/cmspro/sso/auth/relay/login-status')
            ->assertStatus(400)->assertJsonPath('message', '缺少 session_id');
    }

    public function test_扫码会话等待中轮询返回未登录(): void
    {
        $this->configRelayClient();

        // 服务端 relay-status 返回等待态（未扫码）
        Http::fake([
            'sso.example.com/api/cmspro/sso/oauth/relay-status*' => Http::response([
                'code' => 0,
                'data' => ['status' => 'waiting'],
            ]),
        ]);

        $this->getJson('/api/cmspro/sso/auth/relay/login-status?session_id=' . str_repeat('d', 32))
            ->assertOk()
            ->assertJsonPath('data.logged_in', false)
            ->assertJsonPath('data.need_setup', null);
    }

    public function test_扫码确认老用户轮询直接登录(): void
    {
        $this->configRelayClient();

        // 本地已有 relay 绑定账号（老用户）
        $user = $this->createLocalUser('qr_old_user_1', 'qr_pwd_123');
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'relay',
            'open_id'  => 'sso_888',
        ]);

        $this->fakeQrServerFlow('confirmed');

        $this->getJson('/api/cmspro/sso/auth/relay/login-status?session_id=' . str_repeat('e', 32))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.logged_in', true)
            ->assertJsonPath('data.is_new', false);

        $this->assertAuthenticated('web');
        $this->assertSame($user->id, (int) auth('web')->id());
    }

    public function test_扫码确认新用户轮询返回分流页地址(): void
    {
        $this->configRelayClient();

        $this->fakeQrServerFlow('confirmed');

        $response = $this->getJson('/api/cmspro/sso/auth/relay/login-status?session_id=' . str_repeat('f', 32))
            ->assertOk()
            ->assertJsonPath('data.logged_in', false)
            ->assertJsonPath('data.need_setup', true);

        // 分流页地址携带暂存 token，授权数据已暂存
        $setupUrl = (string) $response->json('data.setup_url');
        $this->assertStringContainsString('/auth/relay/complete?redirect=', $setupUrl);
        parse_str((string) parse_url($setupUrl, PHP_URL_QUERY), $query);
        $token = (string) ($query['token'] ?? '');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        $cached = Cache::get('cmspro_sso_oauth_data:' . $token);
        $this->assertSame('sso_888', $cached['openid']);
        $this->assertSame('relay', $cached['platform']);

        // 未建号、未登录
        $this->assertSame(0, UserThirdParty::where('platform', 'relay')->count());
        $this->assertGuest('web');
    }

    public function test_扫码授权失败轮询返回错误(): void
    {
        $this->configRelayClient();

        // 服务端返回 failed（用户拒绝授权等）
        Http::fake([
            'sso.example.com/api/cmspro/sso/oauth/relay-status*' => Http::response([
                'code' => 0,
                'data' => ['status' => 'failed', 'error' => '用户已取消授权'],
            ]),
        ]);

        $this->getJson('/api/cmspro/sso/auth/relay/login-status?session_id=' . str_repeat('1', 32))
            ->assertStatus(422)
            ->assertJsonPath('message', '用户已取消授权');
    }

    public function test_扫码确认邀请模式新用户跳邀请页地址(): void
    {
        $this->configRelayClient();
        $this->setConfig('invite_enabled', '1');

        $this->fakeQrServerFlow('confirmed');

        $response = $this->getJson('/api/cmspro/sso/auth/relay/login-status?session_id=' . str_repeat('2', 32))
            ->assertOk()
            ->assertJsonPath('data.need_setup', true);

        $setupUrl = (string) $response->json('data.setup_url');
        $this->assertStringContainsString('/auth/relay/complete-invite?redirect=', $setupUrl);
    }

    // ---------------------------------------------------------------- 4. 邀请码填写页 relay 平台识别

    public function test_中转邀请页relay平台识别正确(): void
    {
        $this->get('/sso/auth/relay/complete-invite?token=' . str_repeat('3', 32))
            ->assertOk()
            ->assertViewIs('cmspro.sso::Home.auth.complete-invite')
            ->assertViewHas('platform', 'relay');
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
     * 创建一个启用状态的客户端并保存明文密钥
     */
    protected function createClient(array $redirectUris = ['https://app.example.com/auth/relay/callback']): Client
    {
        $this->secret = Client::generateSecret();
        $this->client = Client::create([
            'client_id'     => Client::generateClientId(),
            'secret_hash'   => password_hash($this->secret, PASSWORD_DEFAULT),
            'name'          => '测试客户端站点',
            'redirect_uris' => implode("\n", $redirectUris),
            'status'        => Client::STATUS_ENABLED,
            'remark'        => '',
        ]);

        return $this->client;
    }

    /**
     * 创建服务端测试用户
     */
    protected function createUser(): User
    {
        return User::create([
            'username' => 'sso_server_user_' . uniqid(),
            'password' => 'password_hash_placeholder',
            'nickname' => '服务端测试用户',
            'status'   => 1,
        ]);
    }

    /**
     * 创建服务端扫码会话，返回会话 ID（服务端模式下直接调开放 API）
     */
    protected function createQrSession(string $platform = ''): string
    {
        $response = $this->postJson('/api/cmspro/sso/oauth/relay-qrcode', [
            'client_id'    => $this->client->client_id,
            'redirect_uri' => 'https://app.example.com/auth/relay/callback',
            'platform'     => $platform,
        ])->assertOk();

        return (string) $response->json('data.session_id');
    }

    /**
     * 模拟客户端扫码确认后的服务端接口链路（relay-status → token → userinfo）
     *
     * @param string $status relay-status 状态（confirmed/failed）
     */
    protected function fakeQrServerFlow(string $status): void
    {
        $relayStatus = $status === 'confirmed'
            ? ['status' => 'confirmed', 'code' => 'SERVER_QR_CODE_1', 'platform' => 'qq']
            : ['status' => 'failed', 'error' => '用户已取消授权'];

        Http::fake([
            'sso.example.com/api/cmspro/sso/oauth/relay-status*' => Http::response([
                'code' => 0,
                'data' => $relayStatus,
            ]),
            'sso.example.com/api/cmspro/sso/oauth/token' => Http::response([
                'code' => 0,
                'data' => ['access_token' => 'server_access_token_1', 'token_type' => 'Bearer', 'expires_in' => 3600],
            ]),
            'sso.example.com/api/cmspro/sso/oauth/userinfo' => Http::response([
                'code' => 0,
                'data' => ['id' => 888, 'username' => 'wx_888', 'nickname' => '服务端昵称', 'avatar' => 'https://a.b/x.png', 'platform' => 'qq'],
            ]),
        ]);
    }

    /**
     * 创建本地测试用户（密码为明文入参，自动哈希）
     */
    protected function createLocalUser(string $username, string $password): User
    {
        return User::create([
            'username' => $username,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
            'nickname' => $username,
            'status'   => 1,
        ]);
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
     * 构建测试所需的最小表结构（配置表 + 用户表 + 用户组 + 第三方绑定表 + 登录日志 + 快捷模式三表）
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

        if (!Schema::hasTable('app_cmspro_sso_clients')) {
            Schema::create('app_cmspro_sso_clients', function ($table) {
                $table->bigIncrements('id');
                $table->string('client_id', 32)->unique();
                $table->string('secret_hash', 255);
                $table->string('name', 100);
                $table->text('redirect_uris');
                $table->unsignedTinyInteger('status')->default(1);
                $table->string('remark', 255)->nullable();
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
            });
        }

        if (!Schema::hasTable('app_cmspro_sso_auth_codes')) {
            Schema::create('app_cmspro_sso_auth_codes', function ($table) {
                $table->bigIncrements('id');
                $table->string('code', 64)->unique();
                $table->string('client_id', 32);
                $table->unsignedBigInteger('user_id');
                $table->string('platform', 20)->default('');
                $table->string('redirect_uri', 500);
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
            });
        }

        if (!Schema::hasTable('app_cmspro_sso_access_tokens')) {
            Schema::create('app_cmspro_sso_access_tokens', function ($table) {
                $table->bigIncrements('id');
                $table->string('token_hash', 64)->unique();
                $table->string('client_id', 32);
                $table->unsignedBigInteger('user_id');
                $table->string('platform', 20)->default('');
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
            });
        }
    }
}
