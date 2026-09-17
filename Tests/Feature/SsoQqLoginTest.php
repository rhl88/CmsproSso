<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproSso\Models\InviteCode;
use App\Models\User;
use App\Models\UserThirdParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · QQ 授权登录闭环测试
 *
 * 覆盖：
 *   1. 登录 URL 生成与配置校验（未配置 AppID/AppKey 拒绝）；
 *   2. 原生回调：code 换取 access_token + openid + 用户信息（Http::fake 模拟外部请求）；
 *   3. 新用户首次登录自动建号并绑定 user_third_party；
 *   4. 老用户回调直接登录，不重复建号；
 *   5. 邀请注册开启时，回调新用户暂存授权数据，凭 token 提交邀请码建号；
 *   6. PC 扫码模式：state 存 Cache，回调写结果缓存，login-status 轮询建立登录态。
 */
class SsoQqLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. 登录 URL

    public function test_未配置QQ互联时获取授权URL被拒绝(): void
    {
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '');
        $this->setConfig('qq_app_key', '');

        $this->getJson('/api/cmspro/sso/auth/qq/login-url')
            ->assertStatus(400)
            ->assertJsonPath('message', 'QQ用户登录未配置，请联系管理员');
    }

    public function test_QQ登录未启用时获取授权URL被拒绝(): void
    {
        $this->setConfig('qq_enabled', '0');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        $this->getJson('/api/cmspro/sso/auth/qq/login-url')
            ->assertStatus(400)
            ->assertJsonPath('message', 'QQ用户登录未启用');
    }

    public function test_QQ授权URL生成成功(): void
    {
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        $response = $this->getJson('/api/cmspro/sso/auth/qq/login-url?device=pc&redirect=%2Fhome')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $url = $response->json('data.url');
        $this->assertIsString($url);
        $this->assertStringContainsString('https://graph.qq.com/oauth2.0/authorize', $url);
        $this->assertStringContainsString('client_id=101234567', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('scope=get_user_info', $url);

        // state 已写入 Cache（PC 扫码模式）
        $state = (string) $response->json('data.state');
        $this->assertNotSame('', $state);
        $this->assertTrue(Cache::has('cmspro_sso_login_state:' . $state));
    }

    // ---------------------------------------------------------------- 2. 原生回调

    public function test_QQ原生回调新用户自动建号并绑定(): void
    {
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        // 模拟 QQ 互联外部接口：token 换 access_token，me 换 openid，user/get_user_info 换昵称头像
        $this->fakeQqApis('QQOPENID_NEW_001');

        $state = 'qq_state_native_001';
        $response = $this->withSession([
            'cmspro_sso_qq_oauth_state' => $state,
        ])->get('/sso/auth/qq/callback?' . http_build_query([
            'code'  => 'QQ_CODE_001',
            'state' => $state,
        ]));

        // 新用户：redirect 到完善资料页
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/auth/qq/complete', $location);

        // openid 已绑定到新用户，
        $binding = UserThirdParty::where('open_id', 'QQOPENID_NEW_001')->where('platform', 'qq')->first();
        $this->assertNotNull($binding);
        $user = User::find($binding->user_id);
        $this->assertNotNull($user);
        $this->assertSame('QQ用户', $user->nickname);
        $this->assertSame('test_qq_avatar.png', $user->avatar);
        // 用户名前缀 qq
        $this->assertStringStartsWith('qq', $user->username);
        // 账号已登录（回调即建立登录态）
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_QQ原生回调老用户直接登录不重复建号(): void
    {
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        $oldUser = User::create([
            'username' => 'qq_old_user',
            'password' => 'qq_old_password_hash',
            'nickname' => 'QQ老用户',
            'status'   => 1,
        ]);
        UserThirdParty::create([
            'user_id'  => $oldUser->id,
            'platform' => 'qq',
            'open_id'  => 'QQOPENID_OLD_001',
        ]);

        $this->fakeQqApis('QQOPENID_OLD_001');

        $state = 'qq_state_old_001';
        $response = $this->withSession([
            'cmspro_sso_qq_oauth_state' => $state,
        ])->get('/sso/auth/qq/callback?' . http_build_query([
            'code'  => 'QQ_CODE_OLD_001',
            'state' => $state,
        ]));

        // 老用户：登录成功直接跳默认地址，不触发完善资料
        $response->assertRedirect();
        $this->assertStringNotContainsString('complete', (string) $response->headers->get('Location'));
        $this->assertAuthenticatedAs($oldUser, 'web');

        // 不重复建号（仍仅一个绑定记录）
        $this->assertSame(1, UserThirdParty::where('open_id', 'QQOPENID_OLD_001')
            ->where('platform', 'qq')
            ->count());
        $this->assertSame(1, User::count());
    }

    public function test_QQ回调state校验失败被拒绝(): void
    {
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        // session 中 state 与回调 state 不一致：回登录页并提示，不建号
        $response = $this->withSession([
            'cmspro_sso_qq_oauth_state' => 'expected_state',
            'cmspro_sso_qq_oauth_redirect' => '/home',
        ])->get('/sso/auth/qq/callback?' . http_build_query([
            'code'  => 'QQ_CODE_X',
            'state' => 'wrong_state',
        ]));

        $response->assertRedirect();
        $this->assertStringContainsString('/login', (string) $response->headers->get('Location'));
        $response->assertSessionHas('error', '授权状态校验失败，请重新登录');
        $this->assertDatabaseMissing('user_third_party', ['platform' => 'qq']);
    }

    // ---------------------------------------------------------------- 3. 邀请码流程

    public function test_QQ邀请注册新用户凭token提交邀请码建号(): void
    {
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->setConfig('invite_enabled', '1');

        $code = $this->createCode(['code' => 'QQINVITE2345678']);
        $this->fakeQqApis('QQOPENID_INVITE_001');

        $state = 'qq_state_invite_001';
        $response = $this->withSession([
            'cmspro_sso_qq_oauth_state' => $state,
        ])->get('/sso/auth/qq/callback?' . http_build_query([
            'code'  => 'QQ_CODE_INVITE_001',
            'state' => $state,
        ]));

        // 新用户且开启邀请注册：redirect 到 complete-invite 页，token 随 URL 携带
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('complete-invite', $location);
        $this->assertNull(UserThirdParty::where('open_id', 'QQOPENID_INVITE_001')->first());

        // 提取 token，提交无效邀请码：拒绝且授权数据保留可重试
        parse_str(parse_url($location, PHP_URL_QUERY) ?? '', $query);
        $token = (string) ($query['token'] ?? '');
        $this->assertNotSame('', $token);

        $this->postJson('/api/cmspro/sso/auth/qq/invite', [
            'token'       => $token,
            'invite_code' => 'WRONGCODE',
        ])->assertStatus(422)
          ->assertJsonPath('message', '邀请码不存在');

        // 有效邀请码：建号成功并消费邀请码
        $this->postJson('/api/cmspro/sso/auth/qq/invite', [
            'token'       => $token,
            'invite_code' => strtolower($code->code),
        ])->assertOk()
          ->assertJsonPath('code', 0);

        $binding = UserThirdParty::where('open_id', 'QQOPENID_INVITE_001')->where('platform', 'qq')->first();
        $this->assertNotNull($binding);
        $user = User::find($binding->user_id);
        $this->assertNotNull($user);

        // 邀请码已消费（大写存储）
        $code->refresh();
        $this->assertSame(InviteCode::STATUS_USED, (int) $code->status);
        $this->assertSame($user->id, (int) $code->used_by);
        // 提交邀请码后已登录
        $this->assertAuthenticatedAs($user, 'web');
    }

    // ---------------------------------------------------------------- 4. PC 扫码轮询

    public function test_QQ_PC扫码登录全流程(): void
    {
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        // 1. 获取授权 URL（PC 扫码模式，state 存 Cache）
        $urlResponse = $this->getJson('/api/cmspro/sso/auth/qq/login-url?device=pc')
            ->assertOk();
        $state = (string) $urlResponse->json('data.state');
        $authUrl = (string) $urlResponse->json('data.url');
        $this->assertNotSame('', $state);

        // 2. 未扫码前轮询：未登录
        $this->getJson('/api/cmspro/sso/auth/qq/login-status?state=' . $state)
            ->assertOk()
            ->assertJsonPath('data.logged_in', false);

        // 3. 手机端扫码回调（模拟 QQ 服务器回调）
        $this->fakeQqApis('QQOPENID_PC_001');
        $this->get('/sso/auth/qq/callback?' . http_build_query([
            'code'  => 'QQ_CODE_PC_001',
            'state' => $state,
        ]))->assertRedirect();

        // 4. PC 端轮询：已登录，并建立 PC 端登录态
        $this->getJson('/api/cmspro/sso/auth/qq/login-status?state=' . $state)
            ->assertOk()
            ->assertJsonPath('data.logged_in', true);

        $this->assertDatabaseHas('user_third_party', [
            'platform' => 'qq',
            'open_id'  => 'QQOPENID_PC_001',
        ]);
        // 轮询建立了登录态（当前请求会话已登录用户）
        $user = User::where('username', 'like', 'qq_%')->first();
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user, 'web');
    }

    // ---------------------------------------------------------------- 辅助方法

    /**
     * 模拟 QQ 互联全部外部接口（与 QqOauthService 实现保持一致的 URL 与返回格式）
     */
    private function fakeQqApis(string $openid): void
    {
        Http::fake([
            // 用 code 换 access_token：query 字符串返回格式
            'https://graph.qq.com/oauth2.0/token*' => Http::response(
                'access_token=TEST_ACCESS_TOKEN&expires_in=7776000&refresh_token=TEST_REFRESH_TOKEN'
            ),
            // 用 access_token 换 openid：callback( {...} ); JSONP 格式，注意真正 JSON 体逗号分隔
            'https://graph.qq.com/oauth2.0/me*' => Http::response(
                'callback( {"client_id":"101234567","openid":"' . $openid . '"} );'
            ),
            // 获取用户信息：JSON 格式
            'https://graph.qq.com/user/get_user_info*' => Http::response([
                'ret' => 0,
                'msg' => '',
                'nickname' => 'QQ用户',
                'figureurl_qq_1' => 'https://q.qlogo.cn/test_1.png',
                'figureurl_qq_2' => 'test_qq_avatar.png',
            ]),
        ]);
    }

    /**
     * 预置一条邀请码
     */
    private function createCode(array $attributes = []): InviteCode
    {
        return InviteCode::create(array_merge([
            'code'   => InviteCode::generateUniqueCode(),
            'status' => InviteCode::STATUS_UNUSED,
        ], $attributes));
    }

    /**
     * 写入配置项
     */
    private function setConfig(string $name, string $value): void
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

        if (!Schema::hasTable('app_cmspro_sso_invite_codes')) {
            Schema::create('app_cmspro_sso_invite_codes', function ($table) {
                $table->bigIncrements('id');
                $table->string('code', 32)->unique();
                $table->unsignedTinyInteger('status')->default(1);
                $table->decimal('price', 10, 2)->nullable();
                $table->unsignedBigInteger('used_by')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
                $table->index('status');
            });
        }

        if (!Schema::hasTable('app_cmspro_sso_invite_orders')) {
            Schema::create('app_cmspro_sso_invite_orders', function ($table) {
                $table->bigIncrements('id');
                $table->string('out_trade_no', 128)->unique();
                $table->string('query_token', 64);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->decimal('amount', 10, 2);
                $table->unsignedTinyInteger('status')->default(0);
                $table->unsignedBigInteger('invite_code_id')->nullable();
                $table->string('pay_order_no', 64)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
                $table->index('status');
            });
        }
    }
}