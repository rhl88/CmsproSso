<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproSso\Models\AccessToken;
use App\Apps\CmsproSso\Models\AuthCode;
use App\Apps\CmsproSso\Models\Client;
use App\Apps\CmsproSso\Services\ShareService;
use App\Models\User;
use App\Models\UserThirdParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 快捷模式（社交登录纯跳板中转）测试
 *
 * 服务端仅作社交授权跳板，不建号、不绑定、不落任何用户数据：
 *   authorize（门禁/白名单/代跳社交授权页，已登录也不静默签发）
 *        → 社交回调 relayPassthroughCallback（暂存社交数据 + 签发无 user_id 授权码）
 *        → token（code 换令牌，令牌不关联服务端用户）
 *        → userinfo/revoke（凭 data_token 透传社交真实 openid 等数据）
 *   客户端 relay（回调以社交 openid 建号登录/state 校验）。
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

    // ---------------------------------------------------------------- 1. 服务端 authorize 授权端点（纯跳板代跳）

    public function test_非服务端模式授权端点返回404(): void
    {
        $this->createClient();

        // 框架约定：abort(404) 由 bootstrap/app.php 的 NotFoundHttpException 渲染器
        // 统一输出 errors.404 页面，响应为 HTTP 200 且 original 是已渲染字符串（非 View 实例），
        // 故按渲染结果断言，不能使用 assertViewIs('errors.404')
        $this->get('/sso/oauth/authorize?' . http_build_query($this->authorizeQuery()))
            ->assertStatus(200)
            ->assertSee('页面不存在');
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

    public function test_已登录仍跳转社交平台授权页不静默签发(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $user = $this->createUser();
        $this->createClient();

        // 纯跳板：无论服务端是否已登录，始终代跳社交平台授权页（不静默签发授权码）
        $response = $this->actingAs($user, 'web')
            ->get('/sso/oauth/authorize?' . http_build_query($this->authorizeQuery(['platform' => 'qq', 'state' => 'st_abc'])))
            ->assertRedirect();

        // 跳转 QQ 互联授权页，社交回调 state 携带中转上下文（客户端回调地址随 state 透传）
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://graph.qq.com/oauth2.0/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('101234567', $query['client_id']);
        $this->assertStringEndsWith('/sso/auth/qq/callback', $query['redirect_uri']);

        $oauthState = (string) $query['state'];
        $this->assertNotSame('st_abc', $oauthState);
        $decoded = json_decode(base64_decode(strtr($oauthState, '-_', '+/'), true), true);
        $this->assertIsArray($decoded);
        $this->assertStringStartsWith('relay:', (string) $decoded['ctx']);
        $this->assertSame('https://app.example.com/auth/relay/callback', $decoded['uri']);

        // 未签发授权码（服务端不落任何用户数据）
        $this->assertSame(0, AuthCode::count());
    }

    // ---------------------------------------------------------------- 2. 服务端 token 换取

    public function test_授权码正常换取访问令牌(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->createClient();
        $uri = 'https://app.example.com/auth/relay/callback';
        $this->createAuthCode('CODE_OK_1', 'qq', $uri, 'DATA_TOKEN_1');

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

        // 令牌仅存哈希、不关联用户、携带暂存键，授权码标记已消费
        $this->assertSame(1, AccessToken::where('token_hash', AccessToken::hashToken($token))->count());
        $tokenRecord = AccessToken::where('token_hash', AccessToken::hashToken($token))->first();
        $this->assertNull($tokenRecord->user_id);
        $this->assertSame('DATA_TOKEN_1', $tokenRecord->data_token);
        $this->assertNotNull(AuthCode::where('code', 'CODE_OK_1')->value('used_at'));
    }

    public function test_授权码换取后映射被消费(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->createClient();
        $uri = 'https://app.example.com/auth/relay/callback';
        $this->createAuthCode('CODE_MAP_1', 'qq', $uri, 'DATA_TOKEN_MAP_1');

        // code → data_token 映射随换取消费（一次一取）
        $this->post('/api/cmspro/sso/oauth/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->client->client_id,
            'client_secret' => $this->secret,
            'code'          => 'CODE_MAP_1',
            'redirect_uri'  => $uri,
        ])->assertOk();

        $this->assertFalse(Cache::has('cmspro_sso_oauth_grant:CODE_MAP_1'));
    }

    public function test_授权码重放被拒绝(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->createClient();
        $uri = 'https://app.example.com/auth/relay/callback';
        $this->createAuthCode('CODE_REPLAY_1', 'qq', $uri);

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
        $this->createClient();
        $uri = 'https://app.example.com/auth/relay/callback';
        $this->createAuthCode('CODE_EXPIRED_1', 'qq', $uri, 'DATA_TOKEN_E1', now()->addSeconds(-10));

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
        $this->createClient();
        $uri = 'https://app.example.com/auth/relay/callback';
        $this->createAuthCode('CODE_BADSECRET_1', 'qq', $uri);

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
        $this->createClient();
        $this->createAuthCode('CODE_URI_MISMATCH_1', 'qq', 'https://app.example.com/auth/relay/callback');

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

    // ---------------------------------------------------------------- 3. 服务端 userinfo / revoke（纯透传）

    public function test_有效令牌透传社交授权数据(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->createClient();
        $token = $this->exchangeToken('qq', 'DATA_TOKEN_UF');

        // 暂存社交真实数据（userinfoData 一次性消费）
        Cache::put('cmspro_sso_oauth_grant:data:DATA_TOKEN_UF', [
            'openid'        => 'qq_real_openid_1',
            'unionid'       => 'union_1',
            'nickname'      => '透传昵称',
            'avatar'        => 'https://a.b/x.png',
            'access_token'  => 'QQ_AT',
            'refresh_token' => 'QQ_RT',
            'platform'      => 'qq',
            'raw_data'      => ['nickname' => '透传昵称'],
        ], 600);

        $response = $this->get('/api/cmspro/sso/oauth/userinfo', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.openid', 'qq_real_openid_1')
            ->assertJsonPath('data.unionid', 'union_1')
            ->assertJsonPath('data.nickname', '透传昵称')
            ->assertJsonPath('data.platform', 'qq');

        // 不返回服务端用户字段（服务端不落用户数据）
        $this->assertNull($response->json('data.id'));
        $this->assertNull($response->json('data.username'));
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
        $this->createClient();
        $token = $this->exchangeToken('qq');

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

    // ---------------------------------------------------------------- 5. 服务端社交回调纯透传

    public function test_社交回调中转整页跳转签发无用户授权码(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->createClient();
        $this->fakeQqOauthCode('qq_real_openid_2', '中转用户', 'https://a.b/relay.png');

        // authorize 代跳 QQ 互联授权页（社交回调 state 为 Base64 中转上下文，携带客户端原始 state）
        $authorize = $this->get('/sso/oauth/authorize?' . http_build_query($this->authorizeQuery([
            'platform' => 'qq',
            'state'    => 'client_side_state_abc',
        ])));
        $authorizeLocation = (string) $authorize->headers->get('Location');
        parse_str((string) parse_url($authorizeLocation, PHP_URL_QUERY), $authorizeQuery);
        $oauthState = (string) $authorizeQuery['state'];

        // 模拟 QQ 互联授权确认后回调服务端（code + 中转 state）
        $response = $this->get('/sso/auth/qq/callback?' . http_build_query([
            'code'  => 'QQ_AUTH_CODE_1',
            'state' => $oauthState,
        ]))->assertRedirect();

        // 302 回客户端回调地址，携带签发的授权码，并原样带回客户端原始 state（客户端据此与会话暂存值比对）
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://app.example.com/auth/relay/callback?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $callbackQuery);
        $code = (string) $callbackQuery['code'];
        $this->assertNotSame('', $code);
        $this->assertSame('client_side_state_abc', (string) ($callbackQuery['state'] ?? ''));

        // 服务端不落任何用户数据：授权码不关联用户，不建服务端账号/绑定记录
        $authCode = AuthCode::where('code', $code)->first();
        $this->assertNotNull($authCode);
        $this->assertNull($authCode->user_id);
        $this->assertSame('qq', $authCode->platform);
        $this->assertSame(0, UserThirdParty::count());
        $this->assertSame(0, User::count());
    }

    public function test_社交回调中转PC扫码写入扫码会话(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->createClient();
        $this->fakeQqOauthCode('qq_real_openid_3', '扫码中转用户', 'https://a.b/qr.png');

        // 客户端创建扫码会话（服务端返回社交授权地址，state 携带 relay_qr:{session_id}）
        $relayQrcode = $this->postJson('/api/cmspro/sso/oauth/relay-qrcode', [
            'client_id'    => $this->client->client_id,
            'redirect_uri' => 'https://app.example.com/auth/relay/callback',
            'platform'     => 'qq',
        ])->assertOk()->assertJsonPath('code', 0);

        $sessionId = (string) $relayQrcode->json('data.session_id');
        $qrcodeUrl = (string) $relayQrcode->json('data.qrcode_url');
        $this->assertNotSame('', $sessionId);
        $this->assertStringStartsWith('https://graph.qq.com/oauth2.0/authorize?', $qrcodeUrl);
        parse_str((string) parse_url($qrcodeUrl, PHP_URL_QUERY), $qrQuery);
        $this->assertSame('relay_qr:' . $sessionId, (string) $qrQuery['state']);

        // 模拟手机扫码完成 QQ 授权（社交回调 state 为扫码会话标识）
        $this->get('/sso/auth/qq/callback?' . http_build_query([
            'code'  => 'QQ_AUTH_CODE_QR',
            'state' => 'relay_qr:' . $sessionId,
        ]))->assertRedirect(ShareService::frontUrl('/auth/relay/pc-success'));

        // 授权码写入扫码会话，客户端 PC 端轮询取回（一次性消费）
        $status = $this->getJson('/api/cmspro/sso/oauth/relay-status?session_id=' . $sessionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
        $code = (string) $status->json('data.code');
        $this->assertNotSame('', $code);
        $this->assertSame('qq', $status->json('data.platform'));

        // 服务端不落任何用户数据：授权码不关联用户，不建服务端账号/绑定记录
        $authCode = AuthCode::where('code', $code)->first();
        $this->assertNotNull($authCode);
        $this->assertNull($authCode->user_id);
        $this->assertSame(0, UserThirdParty::count());
        $this->assertSame(0, User::count());

        // 结果已被取走，再轮询返回 waiting
        $this->getJson('/api/cmspro/sso/oauth/relay-status?session_id=' . $sessionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting');
    }

    // ---------------------------------------------------------------- 6. 客户端 relay 回调（身份锚点为社交 openid）

    public function test_客户端中转回调以社交openid建号登录(): void
    {
        $this->setConfig('quick_mode', 'client');
        $this->setConfig('relay_server_url', 'https://sso.example.com');
        $this->setConfig('relay_client_id', 'sso_relay_client');
        $this->setConfig('relay_client_secret', 'relay_secret_plain');
        $this->createClient(['client_id' => 'sso_relay_client', 'client_secret' => 'relay_secret_plain']);
        $this->fakeQrServerFlow('qq_real_openid_client_1', '客户端中转用户');

        // 发起登录后，session 暂存 state 与跳转地址
        $state = 'client_state_relay_1';
        $response = $this->withSession([
            'cmspro_sso_relay_oauth_state'    => $state,
            'cmspro_sso_relay_oauth_redirect' => 'https://app.example.com/home',
        ])->get('/sso/auth/relay/callback?' . http_build_query([
            'code'  => 'RELAY_CODE_CLIENT_1',
            'state' => $state,
        ]))->assertRedirect();

        // 新用户跳完善资料分流页（邀请注册未开启），token 暂存社交授权数据
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(ShareService::frontUrl('/auth/relay/complete') . '?token=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $completeQuery);
        $completeToken = (string) $completeQuery['token'];

        // 客户端本地建号（身份锚点为社交真实 openid，platform=relay）
        $setup = $this->postJson('/api/cmspro/sso/auth/relay/setup', [
            'token'    => $completeToken,
            'nickname' => '中转昵称',
        ])->assertOk()->assertJsonPath('code', 0);

        // 客户端本地绑定记录：platform=relay，openid 为社交真实 openid
        $binding = UserThirdParty::query()->first();
        $this->assertNotNull($binding);
        $this->assertSame('relay', $binding->platform);
        $this->assertSame('qq_real_openid_client_1', $binding->open_id);
        $this->assertSame(1, User::count());
        $user = User::find($binding->user_id);
        $this->assertNotNull($user);
        $this->assertSame('中转昵称', $user->nickname);
        // 登录态已建立
        $this->assertAuthenticated('web');
    }

    // ---------------------------------------------------------------- 辅助方法

    /**
     * authorize 端点基础查询参数（默认回调地址为客户端白名单地址）
     */
    protected function authorizeQuery(array $overrides = []): array
    {
        return array_merge([
            'client_id'     => 'sso_test_client',
            'redirect_uri'  => 'https://app.example.com/auth/relay/callback',
            'response_type' => 'code',
            'state'         => 'st_test',
        ], $overrides);
    }

    /**
     * 创建测试数据表（纯透传结构：授权码/令牌 user_id 可空，令牌携带 data_token）
     */
    protected function createTables(): void
    {
        // 框架基础表（配置项/用户/第三方绑定/登录日志，参照 SsoRelayCompleteTest）
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

        // 应用 OAuth 表（纯透传结构：user_id 可空，令牌携带 data_token）
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

        Schema::create('app_cmspro_sso_auth_codes', function ($table) {
            $table->bigIncrements('id');
            $table->string('code', 64)->unique();
            $table->string('client_id', 32)->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('redirect_uri', 500);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('create_time')->nullable();
            $table->timestamp('update_time')->nullable();
        });

        Schema::create('app_cmspro_sso_access_tokens', function ($table) {
            $table->bigIncrements('id');
            $table->string('token_hash', 64)->unique();
            $table->string('client_id', 32)->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('data_token', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('create_time')->nullable();
            $table->timestamp('update_time')->nullable();
        });
    }

    /**
     * 创建 OAuth 客户端（密码存哈希，白名单含客户端中转回调地址）
     */
    protected function createClient(array $overrides = []): void
    {
        $this->secret = 'client_secret_plain';

        $this->client = Client::create(array_merge([
            'client_id'     => 'sso_test_client',
            'secret_hash'   => password_hash($this->secret, PASSWORD_DEFAULT),
            'name'          => '测试客户端',
            'redirect_uris' => "https://app.example.com/auth/relay/callback\nhttps://app.example.com/other",
            'status'        => Client::STATUS_ENABLED,
            'remark'        => '',
        ], $overrides));
    }

    /**
     * 创建本地用户
     */
    protected function createUser(): User
    {
        return User::create([
            'username' => 'user_' . bin2hex(random_bytes(4)),
            'password' => bcrypt('password123'),
            'status'   => 1,
        ]);
    }

    /**
     * 写入应用配置（框架 config_items 表，code 前缀 app_cmspro_sso_）
     */
    protected function setConfig(string $key, string $value): void
    {
        \Illuminate\Support\Facades\DB::table('config_items')->updateOrInsert(
            ['code' => 'app_cmspro_sso_' . $key],
            [
                'name'        => $key,
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
     * 直接创建授权码（含 code → data_token 映射，模拟社交回调签发结果）
     */
    protected function createAuthCode(string $code, string $platform, string $redirectUri, string $dataToken = 'DATA_TOKEN', ?\Carbon\Carbon $expiresAt = null): void
    {
        AuthCode::create([
            'code'         => $code,
            'client_id'    => 'sso_test_client',
            'user_id'      => null,
            'platform'     => $platform,
            'redirect_uri' => $redirectUri,
            'expires_at'   => $expiresAt ?? now()->addSeconds(300),
        ]);

        Cache::put('cmspro_sso_oauth_grant:' . $code, $dataToken, 300);
    }

    /**
     * 走 token 端点换取访问令牌
     *
     * @param string $dataToken 授权码关联的社交数据暂存键（后续 userinfo 取回用）
     */
    protected function exchangeToken(string $platform = 'qq', string $dataToken = 'DATA_TOKEN'): string
    {
        $uri = 'https://app.example.com/auth/relay/callback';
        $code = 'CODE_XT_' . uniqid();
        $this->createAuthCode($code, $platform, $uri, $dataToken);

        $response = $this->post('/api/cmspro/sso/oauth/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->client->client_id,
            'client_secret' => $this->secret,
            'code'          => $code,
            'redirect_uri'  => $uri,
        ])->assertOk();

        return (string) $response->json('data.access_token');
    }

    /**
     * 模拟 QQ 互联授权（token→me→get_user_info 三接口），社交回调用 code 换 openid
     */
    protected function fakeQqOauthCode(string $openid, string $nickname, string $avatar): void
    {
        Http::fake([
            'graph.qq.com/oauth2.0/token*' => Http::response('access_token=QQ_AT&expires_in=7776000', 200),
            'graph.qq.com/oauth2.0/me*' => Http::response('callback( {"client_id":"101234567","openid":"' . $openid . '"} );', 200),
            'graph.qq.com/user/get_user_info*' => Http::response([
                'ret'            => 0,
                'nickname'       => $nickname,
                'figureurl_qq_1' => 'https://a.b/s.png',
                'figureurl_qq_2' => $avatar,
            ], 200),
        ]);
    }

    /**
     * 模拟服务端开放 API（客户端 relayCallback 流程：token 换取 + userinfo 透传）
     */
    protected function fakeQrServerFlow(string $openid, string $nickname): void
    {
        Http::fake([
            'https://sso.example.com/api/cmspro/sso/oauth/token' => Http::response([
                'code'    => 0,
                'message' => 'ok',
                'data'    => [
                    'access_token' => 'SERVER_AT_1',
                    'token_type'   => 'Bearer',
                    'expires_in'   => 3600,
                ],
            ], 200),
            'https://sso.example.com/api/cmspro/sso/oauth/userinfo' => Http::response([
                'code'    => 0,
                'message' => 'ok',
                'data'    => [
                    'openid'        => $openid,
                    'unionid'       => '',
                    'nickname'      => $nickname,
                    'avatar'        => 'https://a.b/client.png',
                    'access_token'  => 'QQ_AT_1',
                    'refresh_token' => 'QQ_RT_1',
                    'platform'      => 'qq',
                    'raw_data'      => ['nickname' => $nickname],
                ],
            ], 200),
        ]);
    }
}
