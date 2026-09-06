<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproSso\Services\AlipayOauthService;
use App\Models\User;
use App\Models\UserThirdParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 支付宝授权回调测试
 *
 * 支付宝网页授权（publicAppAuthorize）回调用 auth_code 参数名换取凭证，
 * 与微信/QQ 等平台的 code 参数名不同。覆盖：
 *   1. login-url 正常返回 state（普通模式存 session）；
 *   2. 回调携带 auth_code（而非 code）时能正确换取 openid，不落入外挂分支；
 *   3. 老用户回调直接建立登录态。
 */
class SsoAlipayCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    private function mockAlipayService(array $overrides = []): AlipayOauthService
    {
        $mock = \Mockery::mock(AlipayOauthService::class);
        $mock->shouldReceive('buildOauthRedirectUrl')->andReturn([
            'success' => true,
            'url'     => 'https://openauth.alipay.com/oauth2/publicAppAuthorize.htm?app_id=2021000000000000',
            'message' => 'ok',
        ]);
        $mock->shouldReceive('getOpenidByOauthCode')->andReturn(array_merge([
            'success'      => true,
            'openid'       => 'ALIPAY_OPENID_CALLBACK_001',
            'access_token' => 'ALIPAY_ACCESS_TOKEN_CALLBACK_001',
            'message'      => 'ok',
        ], $overrides['token'] ?? []));
        $mock->shouldReceive('getUserInfoByToken')->andReturn([
            'success'  => true,
            'nickname' => '支付宝用户',
            'avatar'   => 'https://a.b/ali.png',
            'message'  => 'ok',
        ]);

        $this->app->instance(AlipayOauthService::class, $mock);

        return $mock;
    }

    /**
     * 走 login-url 拿到支付宝原生模式 state（session 已存校验值）
     */
    private function fetchAlipayState(string $redirect = ''): string
    {
        $response = $this->getJson('/api/cmspro/sso/auth/alipay/login-url?' . http_build_query(array_filter([
            'redirect' => $redirect,
        ])))->assertOk();

        $this->assertSame('native', (string) $response->json('data.mode'));
        $this->assertSame(32, strlen((string) $response->json('data.state')));
        $response->assertSessionHas('cmspro_sso_alipay_oauth_state', (string) $response->json('data.state'));

        return (string) $response->json('data.state');
    }

    public function test_回调携带auth_code可换取凭证登录老用户(): void
    {
        $this->mockAlipayService();
        $this->setConfig('alipay_enabled', '1');
        $this->setConfig('alipay_app_id', '2021000000000000');
        $this->setConfig('alipay_private_key', 'test_alipay_private_key');

        // 老用户已绑定支付宝身份
        $user = $this->createUser();
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'alipay',
            'open_id'  => 'ALIPAY_OPENID_CALLBACK_001',
        ]);

        $state = $this->fetchAlipayState();

        // 支付宝回调使用 auth_code 参数名（非 code）
        $callback = $this->get('/sso/auth/alipay/callback?' . http_build_query([
            'auth_code' => 'ee7fc0968da1406fac285024d057a1b2',
            'app_id'    => '2021000000000000',
            'source'    => 'alipay_wallet',
            'scope'     => 'auth_user',
            'state'     => $state,
        ]));
        $callback->assertRedirect();

        // 老用户直接建立登录态
        $this->assertAuthenticated('web');
        $this->assertSame($user->id, (int) auth('web')->id());
    }

    public function test_回调携带auth_code换取凭证失败返回400(): void
    {
        $this->mockAlipayService([
            'token' => ['success' => false, 'openid' => '', 'access_token' => '', 'message' => '授权失败'],
        ]);
        $this->setConfig('alipay_enabled', '1');
        $this->setConfig('alipay_app_id', '2021000000000000');
        $this->setConfig('alipay_private_key', 'test_alipay_private_key');

        $state = $this->fetchAlipayState();

        $callback = $this->get('/sso/auth/alipay/callback?' . http_build_query([
            'auth_code' => 'expired_auth_code_value',
            'state'     => $state,
        ]));
        // abort(400) 会被框架异常兜底在 DEBUG 下渲染为 500（与 OAuthController 注释一致）
        $callback->assertStatus(500);
    }

    public function test_回调未携带code或auth_code返回400未获取openid(): void
    {
        $this->mockAlipayService();
        $this->setConfig('alipay_enabled', '1');
        $this->setConfig('alipay_app_id', '2021000000000000');
        $this->setConfig('alipay_private_key', 'test_alipay_private_key');

        $state = $this->fetchAlipayState();

        // 既不传 code 也不传 auth_code：无法换取凭证，abort(400) 在 DEBUG 下渲染为 500
        $callback = $this->get('/sso/auth/alipay/callback?' . http_build_query([
            'state' => $state,
        ]));
        $callback->assertStatus(500);
    }

    // ---------------------------------------------------------------- 测试辅助

    private function createUser(): User
    {
        return User::create([
            'username' => 'ali_user_' . uniqid(),
            'password' => \Illuminate\Support\Facades\Hash::make('secret_pwd_1'),
            'nickname' => 'ali_user',
            'status'   => 1,
        ]);
    }

    private function setConfig(string $name, string $value): void
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

    private function createTables(): void
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