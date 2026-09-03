<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproSso\Models\AccessToken;
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
 * 统一登录应用 · 快捷模式（社交登录中转）测试
 *
 * 覆盖服务端 OAuth2 授权码流程与客户端中转登录闭环：
 *   服务端 authorize（模式门禁/白名单/登录跳转/授权码签发）
 *        → token（正常换取/重放/过期/凭证/回调一致性拒绝）
 *        → userinfo/revoke（令牌校验与吊销）
 *   客户端 relay（回调建号登录/state 校验/本地社交配置失效）。
 */
class SsoOAuthRelayTest extends TestCase
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

    // ---------------------------------------------------------------- 1. 服务端 authorize 授权端点

    public function test_非服务端模式授权端点返回404(): void
    {
        $this->createClient();

        // 框架约定：abort(404) 渲染 errors.404 视图
        $this->get('/sso/oauth/authorize?' . http_build_query($this->authorizeQuery()))
            ->assertViewIs('errors.404');
    }

    public function test_客户端ID无效或已禁用返回400(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->createClient();

        // 不存在的 client_id
        $this->get('/sso/oauth/authorize?' . http_build_query($this->authorizeQuery(['client_id' => 'sso_not_exists'])))
            ->assertStatus(400);
    }

    public function test_回调地址不在白名单返回400(): void
    {
        $this->setConfig('quick_mode', 'server');
        $user = $this->createUser();
        $this->createClient();

        $query = $this->authorizeQuery(['redirect_uri' => 'https://evil.example.com/callback']);

        $this->actingAs($user, 'web')
            ->get('/sso/oauth/authorize?' . http_build_query($query))
            ->assertStatus(400);
    }

    public function test_response_type非code返回400(): void
    {
        $this->setConfig('quick_mode', 'server');
        $user = $this->createUser();
        $this->createClient();

        $query = $this->authorizeQuery(['response_type' => 'token']);

        $this->actingAs($user, 'web')
            ->get('/sso/oauth/authorize?' . http_build_query($query))
            ->assertStatus(400);
    }

    public function test_未登录跳转统一登录页(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->createClient();

        $response = $this->get('/sso/oauth/authorize?' . http_build_query($this->authorizeQuery()));

        $response->assertRedirect();
        $this->assertStringContainsString('/login', (string) $response->headers->get('Location'));
        // 未签发授权码
        $this->assertSame(0, AuthCode::count());
    }

    public function test_已登录签发授权码并回跳客户端(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $user = $this->createUser();
        $this->createClient();

        $response = $this->actingAs($user, 'web')
            ->get('/sso/oauth/authorize?' . http_build_query($this->authorizeQuery(['platform' => 'qq', 'state' => 'st_abc'])))
            ->assertRedirect();

        // 回跳客户端回调地址并携带 code + state
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://app.example.com/auth/relay/callback?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame('st_abc', $params['state'] ?? '');
        $this->assertNotEmpty($params['code'] ?? '');

        // 授权码落库（归属、平台、回调地址正确，未消费）
        $authCode = AuthCode::where('code', $params['code'])->first();
        $this->assertNotNull($authCode);
        $this->assertSame($this->client->client_id, $authCode->client_id);
        $this->assertSame($user->id, (int) $authCode->user_id);
        $this->assertSame('qq', $authCode->platform);
        $this->assertNull($authCode->used_at);
    }

    // ---------------------------------------------------------------- 2. 服务端 token 换取

    public function test_授权码正常换取访问令牌(): void
    {
        $this->setConfig('quick_mode', 'server');
        $user = $this->createUser();
        $this->createClient();
        $uri = 'https://app.example.com/auth/relay/callback';
        $this->createAuthCode('CODE_OK_1', $user->id, 'qq', $uri);

        $response = $this->post('/api/cmspro/sso/oauth/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->client->client_id,
            'client_secret' => $this->secret,
            'code'          => 'CODE_OK_1',
            'redirect_uri'  => $uri,
        ])->assertOk()->assertJsonPath('code', 0);

        $token = (string) $response->json('data.access_token');
        $this->assertNotEmpty($token);
        $this->assertSame('Bearer', $response->json('data.token_type'));
        $this->assertSame(3600, $response->json('data.expires_in'));

        // 令牌仅存哈希，授权码标记已消费
        $this->assertSame(1, AccessToken::where('token_hash', AccessToken::hashToken($token))->count());
        $this->assertNotNull(AuthCode::where('code', 'CODE_OK_1')->value('used_at'));
    }

    public function test_授权码重放被拒绝(): void
    {
        $this->setConfig('quick_mode', 'server');
        $user = $this->createUser();
        $this->createClient();
        $uri = 'https://app.example.com/auth/relay/callback';
        $this->createAuthCode('CODE_REPLAY_1', $user->id, 'qq', $uri);

        $payload = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->client->client_id,
            'client_secret' => $this->secret,
            'code'          => 'CODE_REPLAY_1',
            'redirect_uri'  => $uri,
        ];

        $this->post('/api/cmspro/sso/oauth/token', $payload)->assertOk();
        $this->post('/api/cmspro/sso/oauth/token', $payload)
            ->assertStatus(400)
            ->assertJsonPath('message', '授权码已被使用');
    }

    public function test_过期授权码被拒绝(): void
    {
        $this->setConfig('quick_mode', 'server');
        $user = $this->createUser();
        $this->createClient();
        $uri = 'https://app.example.com/auth/relay/callback';
        $this->createAuthCode('CODE_EXPIRED_1', $user->id, 'qq', $uri, now()->addSeconds(-10));

        $this->post('/api/cmspro/sso/oauth/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->client->client_id,
            'client_secret' => $this->secret,
            'code'          => 'CODE_EXPIRED_1',
            'redirect_uri'  => $uri,
        ])->assertStatus(400)->assertJsonPath('message', '授权码已过期');
    }

    public function test_客户端密钥错误被拒绝(): void
    {
        $this->setConfig('quick_mode', 'server');
        $user = $this->createUser();
        $this->createClient();
        $uri = 'https://app.example.com/auth/relay/callback';
        $this->createAuthCode('CODE_BADSECRET_1', $user->id, 'qq', $uri);

        $this->post('/api/cmspro/sso/oauth/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->client->client_id,
            'client_secret' => 'wrong_secret',
            'code'          => 'CODE_BADSECRET_1',
            'redirect_uri'  => $uri,
        ])->assertStatus(400)->assertJsonPath('message', '客户端凭证无效');
    }

    public function test_回调地址与授权请求不一致被拒绝(): void
    {
        $this->setConfig('quick_mode', 'server');
        $user = $this->createUser();
        $this->createClient();
        $this->createAuthCode('CODE_URI_MISMATCH_1', $user->id, 'qq', 'https://app.example.com/auth/relay/callback');

        $this->post('/api/cmspro/sso/oauth/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->client->client_id,
            'client_secret' => $this->secret,
            'code'          => 'CODE_URI_MISMATCH_1',
            'redirect_uri'  => 'https://app.example.com/other-callback',
        ])->assertStatus(400)->assertJsonPath('message', '回调地址与授权请求不一致');
    }

    public function test_grant_type不支持被拒绝(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->createClient();

        $this->post('/api/cmspro/sso/oauth/token', [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->client->client_id,
            'client_secret' => $this->secret,
            'code'          => 'ANY',
            'redirect_uri'  => 'https://app.example.com/auth/relay/callback',
        ])->assertStatus(400);
    }

    // ---------------------------------------------------------------- 3. 服务端 userinfo / revoke

    public function test_有效令牌获取用户信息(): void
    {
        $this->setConfig('quick_mode', 'server');
        $user = $this->createUser();
        $this->createClient();
        $token = $this->exchangeToken($user->id);

        $this->get('/api/cmspro/sso/oauth/userinfo', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.platform', 'qq');
    }

    public function test_无效令牌返回401(): void
    {
        $this->setConfig('quick_mode', 'server');

        $this->get('/api/cmspro/sso/oauth/userinfo', ['Authorization' => 'Bearer invalid_token'])
            ->assertStatus(401);
    }

    public function test_吊销后令牌失效(): void
    {
        $this->setConfig('quick_mode', 'server');
        $user = $this->createUser();
        $this->createClient();
        $token = $this->exchangeToken($user->id);

        $this->post('/api/cmspro/sso/oauth/revoke', ['token' => $token])->assertOk();

        $this->get('/api/cmspro/sso/oauth/userinfo', ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(401);
    }

    // ---------------------------------------------------------------- 4. 服务端 platforms

    public function test_platforms无效客户端返回400(): void
    {
        $this->setConfig('quick_mode', 'server');

        $this->getJson('/api/cmspro/sso/oauth/platforms?client_id=sso_not_exists')
            ->assertStatus(400);
    }

    public function test_platforms仅返回已启用且已配置的平台(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        // 微信启用但未配置凭证，不应返回
        $this->setConfig('wechat_enabled', '1');
        $this->createClient();

        $response = $this->getJson('/api/cmspro/sso/oauth/platforms?client_id=' . $this->client->client_id)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $platforms = array_column($response->json('data.platforms'), 'platform');
        $this->assertSame(['qq'], $platforms);
    }

    // ---------------------------------------------------------------- 5. 客户端中转登录

    public function test_非客户端模式中转回调返回404(): void
    {
        // 框架约定：abort(404) 渲染 errors.404 视图
        $this->get('/sso/auth/relay/callback?code=x&state=y')->assertViewIs('errors.404');
    }

    public function test_中转回调state校验失败回登录页(): void
    {
        $this->setConfig('quick_mode', 'client');
        $this->setConfig('relay_server_url', 'https://sso.example.com');
        $this->setConfig('relay_client_id', 'sso_test_client');
        $this->setConfig('relay_client_secret', 'test_secret');

        $response = $this->get('/sso/auth/relay/callback?' . http_build_query([
            'code'  => 'ANY_CODE',
            'state' => 'forged_state',
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('/login', (string) $response->headers->get('Location'));
        $this->assertSame(0, UserThirdParty::where('platform', 'relay')->count());
    }

    public function test_中转回调建号登录完整流程(): void
    {
        $this->setConfig('quick_mode', 'client');
        $this->setConfig('relay_server_url', 'https://sso.example.com');
        $this->setConfig('relay_client_id', 'sso_test_client');
        $this->setConfig('relay_client_secret', 'test_secret');

        // 服务端 token / userinfo 接口模拟
        Http::fake([
            'sso.example.com/api/cmspro/sso/oauth/token' => Http::response([
                'code' => 0,
                'data' => ['access_token' => 'server_access_token_1', 'token_type' => 'Bearer', 'expires_in' => 3600],
            ]),
            'sso.example.com/api/cmspro/sso/oauth/userinfo' => Http::response([
                'code' => 0,
                'data' => ['id' => 777, 'username' => 'wx_777', 'nickname' => '服务端昵称', 'avatar' => 'https://a.b/x.png', 'platform' => 'wechat'],
            ]),
        ]);

        // 1. 获取中转授权地址（state 存 session）
        $response = $this->getJson('/api/cmspro/sso/auth/relay/login-url')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $url = (string) $response->json('data.url');
        $this->assertStringStartsWith('https://sso.example.com/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('sso_test_client', $query['client_id']);
        $state = (string) $query['state'];
        $response->assertSessionHas('cmspro_sso_relay_oauth_state', $state);

        // 2. 服务端授权后回调（服务端用户 id=777）
        $callback = $this->get('/sso/auth/relay/callback?' . http_build_query([
            'code'  => 'SERVER_CODE_1',
            'state' => $state,
        ]));
        $callback->assertRedirect();

        // 3. 新用户不再直接建号，跳分流页（完善资料 / 账号绑定），授权数据暂存 Cache
        $location = (string) $callback->headers->get('Location');
        $this->assertStringContainsString('/auth/relay/complete?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $next);
        $this->assertNotEmpty($next['token'] ?? '');

        $cached = Cache::get('cmspro_sso_oauth_data:' . $next['token']);
        $this->assertIsArray($cached);
        $this->assertSame('sso_777', $cached['openid']);
        $this->assertSame('relay', $cached['platform']);

        // 未建号、未登录，state 已消费
        $this->assertSame(0, UserThirdParty::where('platform', 'relay')->count());
        $this->assertGuest('web');
        $response->assertSessionMissing('cmspro_sso_relay_oauth_state');
    }

    public function test_客户端模式微信登录委托服务端中转(): void
    {
        $this->setConfig('quick_mode', 'client');
        $this->setConfig('relay_server_url', 'https://sso.example.com');
        $this->setConfig('relay_client_id', 'sso_test_client');
        $this->setConfig('relay_client_secret', 'test_secret');
        $this->setConfig('wechat_enabled', '1');
        $this->setConfig('wechat_mp_app_id', 'wx_test_app_id');
        $this->setConfig('wechat_mp_app_secret', 'wx_test_app_secret');

        // 服务端 platforms 接口模拟（含微信平台）
        Http::fake([
            'sso.example.com/api/cmspro/sso/oauth/platforms*' => Http::response([
                'code' => 0,
                'data' => ['platforms' => [['platform' => 'wechat', 'label' => '微信']]],
            ]),
        ]);

        // 客户端模式下点击微信登录：返回服务端中转授权地址（而非本地授权）
        $response = $this->getJson('/api/cmspro/sso/auth/wechat/login-url')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.mode', 'relay');

        $url = (string) $response->json('data.url');
        $this->assertStringStartsWith('https://sso.example.com/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('sso_test_client', $query['client_id']);
        $this->assertSame('wechat', $query['platform']);
        $response->assertSessionHas('cmspro_sso_relay_oauth_state', (string) $query['state']);
    }

    // ---------------------------------------------------------------- 辅助方法

    /**
     * authorize 端点基础合法参数
     */
    protected function authorizeQuery(array $overrides = []): array
    {
        return array_merge([
            'client_id'     => $this->client->client_id,
            'redirect_uri'  => 'https://app.example.com/auth/relay/callback',
            'response_type' => 'code',
            'state'         => 'st_default',
        ], $overrides);
    }

    /**
     * 直接签发一条授权码（绕过 authorize，用于 token 端点测试）
     */
    protected function createAuthCode(string $code, int $userId, string $platform, string $redirectUri, ?\Carbon\Carbon $expiresAt = null): void
    {
        AuthCode::create([
            'code'         => $code,
            'client_id'    => $this->client->client_id,
            'user_id'      => $userId,
            'platform'     => $platform,
            'redirect_uri' => $redirectUri,
            'expires_at'   => $expiresAt ?? now()->addMinutes(5),
        ]);
    }

    /**
     * 走完整换取流程，返回明文访问令牌
     */
    protected function exchangeToken(int $userId): string
    {
        $uri = 'https://app.example.com/auth/relay/callback';
        $this->createAuthCode('CODE_EXCHANGE_' . $userId, $userId, 'qq', $uri);

        $response = $this->post('/api/cmspro/sso/oauth/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->client->client_id,
            'client_secret' => $this->secret,
            'code'          => 'CODE_EXCHANGE_' . $userId,
            'redirect_uri'  => $uri,
        ])->assertOk();

        return (string) $response->json('data.access_token');
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
