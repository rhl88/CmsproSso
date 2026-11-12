<?php

namespace App\Apps\CmsproSso\Tests\Feature;

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
 * 统一登录应用 · PC 扫码中转登录测试（纯跳板透传）
 *
 * 覆盖客户端 PC 端扫码中转闭环：
 *   服务端 relay-qrcode（创建扫码会话/门禁/参数校验，二维码内容为社交平台授权地址）
 *        → 手机扫码社交回调 relay_qr 透传（会话结果写入/提示页跳转/一次性消费，
 *          服务端不建号不绑定，授权码不关联服务端用户）
 *        → relay-status（waiting/confirmed/failed 轮询，一次性消费）
 *   客户端 relayLoginStatus（以社交真实 openid 为身份锚点：老用户直接登录/
 *   新用户跳分流页/授权失败透传）。
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

    public function test_创建扫码会话返回社交授权地址与会话ID(): void
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

        // 二维码内容为服务端 QQ 互联授权地址（手机扫码直接打开授权确认页，无需先登录服务端）
        $qrcodeUrl = (string) $response->json('data.qrcode_url');
        $this->assertStringStartsWith('https://graph.qq.com/oauth2.0/authorize?', $qrcodeUrl);
        parse_str((string) parse_url($qrcodeUrl, PHP_URL_QUERY), $query);
        $this->assertSame('101234567', $query['client_id']);
        $this->assertSame('relay_qr:' . $sessionId, $query['state']);
        // 回调地址指向服务端 QQ 社交回调端点（host 依环境而定，仅校验路径）
        $this->assertStringEndsWith('/sso/auth/qq/callback', $query['redirect_uri']);

        // 会话已登记（保存客户端 ID 与回调地址，供社交回调签发授权码）
        $session = Cache::get('cmspro_sso_relay_qr:' . $sessionId);
        $this->assertSame($this->client->client_id, $session['client_id'] ?? null);
        $this->assertSame('https://app.example.com/auth/relay/callback', $session['redirect_uri'] ?? null);
    }

    public function test_创建扫码会话微信返回微信授权地址(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->setConfig('wechat_enabled', '1');
        $this->setConfig('wechat_mp_app_id', 'wx_test_app_id');
        $this->setConfig('wechat_mp_app_secret', 'test_secret');
        $this->createClient();

        $response = $this->postJson('/api/cmspro/sso/oauth/relay-qrcode', [
            'client_id'    => $this->client->client_id,
            'redirect_uri' => 'https://app.example.com/auth/relay/callback',
            'platform'     => 'wechat',
        ])->assertOk()->assertJsonPath('code', 0);

        $sessionId = (string) $response->json('data.session_id');
        $qrcodeUrl = (string) $response->json('data.qrcode_url');
        $this->assertStringStartsWith('https://open.weixin.qq.com/connect/oauth2/authorize?', $qrcodeUrl);
        parse_str((string) parse_url($qrcodeUrl, PHP_URL_QUERY), $query);
        $this->assertSame('wx_test_app_id', $query['appid']);
        $this->assertSame('snsapi_userinfo', $query['scope']);
        $this->assertSame('relay_qr:' . $sessionId, $query['state']);
        $this->assertStringEndsWith('/sso/auth/wechat/callback', $query['redirect_uri']);
    }

    public function test_创建扫码会话平台无效返回400(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->createClient();

        // 未知平台（如 github，服务端未实现）
        $this->postJson('/api/cmspro/sso/oauth/relay-qrcode', [
            'client_id'    => $this->client->client_id,
            'redirect_uri' => 'https://app.example.com/auth/relay/callback',
            'platform'     => 'github',
        ])->assertStatus(400)->assertJsonPath('message', '该登录方式未在服务端启用');

        // 空 platform（未指定登录平台，构建授权地址时无法选定平台）
        $this->postJson('/api/cmspro/sso/oauth/relay-qrcode', [
            'client_id'    => $this->client->client_id,
            'redirect_uri' => 'https://app.example.com/auth/relay/callback',
        ])->assertStatus(400)->assertJsonPath('message', '无效的平台');
    }

    // ---------------------------------------------------------------- 2. 服务端社交回调与扫码会话联动（纯透传）

    public function test_手机扫码社交授权写入会话结果并跳提示页(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->createClient();
        $sessionId = $this->createQrSession('qq');

        // 1. 手机扫码授权完成，QQ 互联回调服务端社交回调端点（code + state=relay_qr:{session_id}）
        $this->fakeQqOauthCode('qq_real_openid_qr_1', '扫码用户', 'https://a.b/qr.png');
        $response = $this->get('/sso/auth/qq/callback?' . http_build_query([
            'code'  => 'QQ_AUTH_CODE_1',
            'state' => 'relay_qr:' . $sessionId,
        ]))->assertRedirect();

        // 2. 手机端跳提示页（非回跳客户端）
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(ShareService::frontUrl('/auth/relay/pc-success'), $location);
        $this->assertStringNotContainsString('app.example.com', $location);

        // 3. 服务端不落任何用户数据：不建服务端账号/绑定记录（纯跳板透传）
        $this->assertSame(0, UserThirdParty::count());
        $this->assertSame(0, User::count());

        // 4. 授权码已签发（不关联服务端用户，归属客户端 + 回调地址为客户端 relay callback）
        $this->assertSame(1, AuthCode::count());
        $authCode = AuthCode::first();
        $this->assertNull($authCode->user_id);
        $this->assertSame($this->client->client_id, $authCode->client_id);
        $this->assertSame('https://app.example.com/auth/relay/callback', $authCode->redirect_uri);
        $this->assertSame('qq', $authCode->platform);

        // 5. PC 端轮询取回授权码（confirmed，一次性消费）
        $this->getJson('/api/cmspro/sso/oauth/relay-status?session_id=' . $sessionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.code', $authCode->code)
            ->assertJsonPath('data.platform', 'qq');

        // 6. 结果已被取走，再次轮询回到等待态
        $this->getJson('/api/cmspro/sso/oauth/relay-status?session_id=' . $sessionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting');
    }

    public function test_手机扫码社交授权会话无效不写结果(): void
    {
        $this->setConfig('quick_mode', 'server');
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->createClient();

        // 伪造会话 ID（服务端未登记，无法取到会话内容）
        $this->fakeQqOauthCode('qq_real_openid_qr_2', '扫码用户', 'https://a.b/qr.png');
        $response = $this->get('/sso/auth/qq/callback?' . http_build_query([
            'code'  => 'QQ_AUTH_CODE_1',
            'state' => 'relay_qr:' . str_repeat('a', 32),
        ]))->assertRedirect();

        $this->assertStringContainsString('pc-success?result=failed', (string) $response->headers->get('Location'));

        // 未签发授权码、未落任何用户数据
        $this->assertSame(0, AuthCode::count());
        $this->assertSame(0, UserThirdParty::count());
    }

    public function test_扫码会话无效轮询返回400(): void
    {
        $this->setConfig('quick_mode', 'server');

        $this->getJson('/api/cmspro/sso/oauth/relay-status?session_id=' . str_repeat('b', 32))
            ->assertStatus(400)->assertJsonPath('message', '扫码会话无效或已过期');

        $this->getJson('/api/cmspro/sso/oauth/relay-status')
            ->assertStatus(400);
    }

    // ---------------------------------------------------------------- 3. 客户端扫码轮询登录（身份锚点为社交 openid）

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

        // 本地已有 relay 绑定账号（老用户，绑定 openid 为社交平台真实 openid）
        $user = $this->createLocalUser('qr_old_user_1', 'qr_pwd_123');
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'relay',
            'open_id'  => 'qq_real_openid_client_1',
        ]);

        $this->fakeQrServerFlow('qq_real_openid_client_1', '扫码老用户');

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

        $this->fakeQrServerFlow('qq_real_openid_client_2', '扫码新用户');

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

        // 暂存数据为社交平台真实身份（服务端纯跳板透传，不做任何数据保存）
        $cached = Cache::get('cmspro_sso_oauth_data:' . $token);
        $this->assertSame('qq_real_openid_client_2', $cached['openid']);
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

        $this->fakeQrServerFlow('qq_real_openid_client_3', '邀请新用户');

        $response = $this->getJson('/api/cmspro/sso/auth/relay/login-status?session_id=' . str_repeat('2', 32))
            ->assertOk()
            ->assertJsonPath('data.need_setup', true);

        $setupUrl = (string) $response->json('data.setup_url');
        $this->assertStringContainsString('/auth/relay/complete-invite?redirect=', $setupUrl);
    }

    // ---------------------------------------------------------------- 4. 邀请码填写页 relay 平台识别

    public function test_中转邀请页relay平台识别正确(): void
    {
        // 开发模式（APP_DEV_MODE=true）下 DevViewPathBanner 中间件重写响应体，
        // original 变为已渲染字符串，assertViewIs/assertViewHas 不可用，
        // 故按视图渲染出的平台变量断言平台识别结果
        $this->get('/sso/auth/relay/complete-invite?token=' . str_repeat('3', 32))
            ->assertOk()
            ->assertSee('var platform = "relay";', false);
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
     * 模拟 QQ 互联授权链路（code 换 access_token → 换 openid → 获取用户信息）
     */
    protected function fakeQqOauthCode(string $openid, string $nickname, string $avatar): void
    {
        Http::fake([
            'graph.qq.com/oauth2.0/token*' => Http::response(
                'access_token=QQ_TEST_AT&expires_in=7776000&refresh_token=QQ_TEST_RT',
                200
            ),
            'graph.qq.com/oauth2.0/me*' => Http::response(
                'callback( {"client_id":"101234567","openid":"' . $openid . '"} );',
                200
            ),
            'graph.qq.com/user/get_user_info*' => Http::response([
                'ret'            => 0,
                'nickname'       => $nickname,
                'figureurl_qq_2' => $avatar,
            ]),
        ]);
    }

    /**
     * 模拟客户端扫码确认后的服务端接口链路（relay-status → token → userinfo）
     *
     * 服务端纯跳板透传：userinfo 返回社交平台真实 openid 等数据，无服务端用户字段。
     *
     * @param string $openid 服务端透传的社交平台真实 openid
     * @param string $nickname 服务端透传的社交昵称
     */
    protected function fakeQrServerFlow(string $openid, string $nickname): void
    {
        Http::fake([
            'sso.example.com/api/cmspro/sso/oauth/relay-status*' => Http::response([
                'code' => 0,
                'data' => ['status' => 'confirmed', 'code' => 'SERVER_QR_CODE_1', 'platform' => 'qq'],
            ]),
            'sso.example.com/api/cmspro/sso/oauth/token' => Http::response([
                'code' => 0,
                'data' => ['access_token' => 'server_access_token_1', 'token_type' => 'Bearer', 'expires_in' => 3600],
            ]),
            'sso.example.com/api/cmspro/sso/oauth/userinfo' => Http::response([
                'code' => 0,
                'data' => [
                    'openid'        => $openid,
                    'unionid'       => '',
                    'nickname'      => $nickname,
                    'avatar'        => 'https://a.b/x.png',
                    'access_token'  => 'QQ_AT_1',
                    'refresh_token' => 'QQ_RT_1',
                    'platform'      => 'qq',
                    'raw_data'      => ['nickname' => $nickname],
                ],
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
     * 构建测试所需的最小表结构（配置表 + 用户表 + 第三方绑定表 + 登录日志 + OAuth 三表）
     *
     * 纯透传结构：授权码/令牌 user_id 可空，令牌携带 data_token。
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
}
