<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Models\User;
use App\Models\UserThirdParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 独立站社交新用户分流流程测试
 *
 * 覆盖：微信回调新用户不再自动建号（授权数据暂存跳分流页）、老用户直达登录、
 * 邀请注册模式仍跳邀请页、分流页渲染（token 回填/无 token 跳登录/已登录直达）、
 * 完善资料建号（complete 接口）、PC 扫码新用户轮询 need_setup 跳分流页闭环。
 */
class SsoSocialNewUserFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. 回调分流

    public function test_微信回调新用户不建号跳分流页(): void
    {
        $this->configWechatProxy();

        // 普通模式：login-url 将 state 与跳转地址存 session
        $state = $this->fetchWechatState('/target');

        $callback = $this->get('/sso/auth/wechat/callback?' . http_build_query([
            'openid'     => 'wx_new_888',
            'state'      => $state,
            'nickname'   => '微信昵称',
            'headimgurl' => 'https://a.b/wx.png',
        ]));
        $callback->assertRedirect();

        // 跳分流页并携带 token 与透传的跳转地址
        $location = (string) $callback->headers->get('Location');
        $this->assertStringContainsString('/sso/auth/wechat/complete?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['token'] ?? '');
        $this->assertSame('/target', $query['redirect'] ?? '');

        // 授权数据已暂存，未建号、未绑定、未登录
        $oauthData = Cache::get('cmspro_sso_oauth_data:' . $query['token']);
        $this->assertNotNull($oauthData);
        $this->assertSame('wx_new_888', $oauthData['openid']);
        $this->assertSame(0, User::count());
        $this->assertSame(0, UserThirdParty::where('platform', 'wechat')->count());
        $this->assertGuest('web');
    }

    public function test_微信回调老用户直接登录不跳分流页(): void
    {
        $this->configWechatProxy();

        $user = $this->createLocalUser('wx_old_1', 'secret_pwd_1');
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'wechat',
            'open_id'  => 'wx_old_888',
        ]);

        $state = $this->fetchWechatState();

        $callback = $this->get('/sso/auth/wechat/callback?' . http_build_query([
            'openid'   => 'wx_old_888',
            'state'    => $state,
            'nickname' => '微信昵称',
        ]));
        $callback->assertRedirect();

        // 老用户直接建立登录态，跳转默认页（非分流页）
        $location = (string) $callback->headers->get('Location');
        $this->assertStringNotContainsString('/auth/wechat/complete?', $location);
        $this->assertAuthenticated('web');
        $this->assertSame($user->id, (int) auth('web')->id());
    }

    public function test_微信回调新用户邀请模式跳邀请页(): void
    {
        $this->configWechatProxy();
        $this->setConfig('invite_enabled', '1');

        $state = $this->fetchWechatState();

        $callback = $this->get('/sso/auth/wechat/callback?' . http_build_query([
            'openid'   => 'wx_invite_888',
            'state'    => $state,
            'nickname' => '微信昵称',
        ]));
        $callback->assertRedirect();

        // 邀请注册模式下新用户仍走邀请页（不建号、不跳分流页）
        $this->assertStringContainsString('/sso/auth/wechat/complete-invite?', (string) $callback->headers->get('Location'));
        $this->assertGuest('web');
        $this->assertSame(0, User::count());
        $this->assertSame(0, UserThirdParty::where('platform', 'wechat')->count());
    }

    // ---------------------------------------------------------------- 2. 分流页渲染

    public function test_分流页凭token回填服务端授权昵称(): void
    {
        $token = $this->putOauthData();

        $response = $this->get('/sso/auth/wechat/complete?token=' . $token);
        $response->assertOk();

        // 页面昵称输入框回填服务端授权昵称
        $content = (string) $response->getContent();
        $this->assertStringContainsString('value="微信昵称"', $content);
    }

    public function test_分流页无token跳转登录页(): void
    {
        $this->get('/sso/auth/wechat/complete')->assertRedirect('/sso/login');
    }

    public function test_已登录用户访问分流页直接跳转默认页(): void
    {
        $user = $this->createLocalUser('flow_user_1', 'secret_pwd_1');
        $this->actingAs($user, 'web');

        $this->get('/sso/auth/wechat/complete?token=' . str_repeat('c', 32))
            ->assertRedirect('/');
    }

    // ---------------------------------------------------------------- 3. 完善资料（创建账号）

    public function test_分流页完善资料创建账号并登录(): void
    {
        $token = $this->putOauthData();

        $response = $this->postJson('/api/cmspro/sso/auth/wechat/complete', [
            'token'    => $token,
            'nickname' => '分流昵称',
            'phone'    => '13800138000',
        ])->assertOk()->assertJsonPath('code', 0);

        // 账号已创建并绑定微信身份
        $binding = UserThirdParty::where('platform', 'wechat')->where('open_id', 'wx_888')->first();
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

    public function test_完善资料token无效返回400(): void
    {
        $this->postJson('/api/cmspro/sso/auth/wechat/complete', [
            'token'    => str_repeat('a', 32),
            'nickname' => '分流昵称',
        ])->assertStatus(400)->assertJsonPath('message', '登录会话已过期，请重新登录');

        $this->assertGuest('web');
    }

    // ---------------------------------------------------------------- 4. PC 扫码新用户闭环

    public function test_PC扫码新用户轮询返回need_setup(): void
    {
        // 模拟 PC 扫码回调后新用户结果存 Cache（oauthCallback isPcMode 分支写入）
        $state = 'pc_state_' . uniqid();
        $token = $this->putOauthData();
        Cache::put('cmspro_sso_login_result:' . $state, [
            'need_setup'  => true,
            'setup_token' => $token,
        ], 600);

        $response = $this->getJson('/api/cmspro/sso/auth/wechat/login-status?' . http_build_query([
            'state'    => $state,
            'redirect' => '/pc-target',
        ]))->assertOk();

        // 轮询返回 need_setup + 分流页地址（携带 token 与跳转地址）
        $this->assertTrue((bool) $response->json('data.need_setup'));
        $this->assertFalse((bool) $response->json('data.logged_in'));
        $setupUrl = (string) $response->json('data.setup_url');
        $this->assertStringContainsString('/sso/auth/wechat/complete?', $setupUrl);
        $this->assertStringContainsString('token=' . $token, $setupUrl);
        $this->assertStringContainsString('redirect=' . urlencode('/pc-target'), $setupUrl);

        // 结果 Cache 保留，过期前轮询可重复获得该状态
        $this->assertNotNull(Cache::get('cmspro_sso_login_result:' . $state));
    }

    public function test_PC扫码新用户凭setup_url的token完成建号(): void
    {
        $state = 'pc_state_' . uniqid();
        $token = $this->putOauthData();
        Cache::put('cmspro_sso_login_result:' . $state, [
            'need_setup'  => true,
            'setup_token' => $token,
        ], 600);

        // PC 端轮询获得分流页地址
        $response = $this->getJson('/api/cmspro/sso/auth/wechat/login-status?' . http_build_query([
            'state' => $state,
        ]))->assertOk();
        $setupUrl = (string) $response->json('data.setup_url');
        parse_str((string) parse_url($setupUrl, PHP_URL_QUERY), $query);

        // PC 端凭 token 提交完善资料完成建号
        $this->postJson('/api/cmspro/sso/auth/wechat/complete', [
            'token' => $query['token'] ?? '',
            'nickname' => 'PC昵称',
        ])->assertOk()->assertJsonPath('code', 0);

        $binding = UserThirdParty::where('platform', 'wechat')->where('open_id', 'wx_888')->first();
        $this->assertNotNull($binding);
        $this->assertAuthenticated('web');
        $this->assertSame((int) $binding->user_id, (int) auth('web')->id());
    }

    // ---------------------------------------------------------------- 5. 子域名模式分流页

    public function test_子域名模式分流页平台解析正确不被误拦(): void
    {
        // 子域名部署（access_mode=domain）：前台路由无 /sso 前缀，Laravel path() 返回的
        // 路径不带前导斜杠。修复前平台解析正则匹配失败、兜底为 wechat，导致 QQ 等
        // 非微信平台分流页进页即误报「登录会话已过期」跳回登录页。
        $this->setConfig('access_mode', 'domain');
        $this->setConfig('access_domain', 'sso.test');
        $this->reRegisterRoutes();

        // QQ 平台：进页正常渲染并回填昵称（修复前误拦跳登录页）
        $token = $this->putOauthDataFor('qq', 'qq_888', 'QQ昵称');
        $response = $this->get('http://sso.test/auth/qq/complete?token=' . $token);
        $response->assertOk();
        $this->assertStringContainsString('value="QQ昵称"', (string) $response->getContent());

        // GitHub 平台：同样不被误拦
        $token = $this->putOauthDataFor('github', 'gh_888', 'GH昵称');
        $this->get('http://sso.test/auth/github/complete?token=' . $token)
            ->assertOk()
            ->assertSee('GH昵称');

        // token 无效时仍正确拦截回登录页（修复不放松校验）
        $this->get('http://sso.test/auth/qq/complete?token=' . str_repeat('d', 32))
            ->assertRedirect('http://sso.test/login');
    }

    public function test_子域名模式微信分流页正常渲染(): void
    {
        $this->setConfig('access_mode', 'domain');
        $this->setConfig('access_domain', 'sso.test');
        $this->reRegisterRoutes();

        // 微信平台：无前缀路径下平台解析为 wechat，进页正常渲染
        $token = $this->putOauthData();
        $response = $this->get('http://sso.test/auth/wechat/complete?token=' . $token);
        $response->assertOk();
        $this->assertStringContainsString('value="微信昵称"', (string) $response->getContent());
    }

    /**
     * setUp 已按 path 模式注册过前台路由，且同一 Provider 不会被二次 boot；
     * 反射仅重跑路由注册，使新配置（domain 模式）下的前台路由生效
     */
    protected function reRegisterRoutes(): void
    {
        $provider = new \App\Apps\CmsproSso\ServiceProvider($this->app);
        (new \ReflectionMethod($provider, 'registerRoutes'))->invoke($provider);
    }

    // ---------------------------------------------------------------- 辅助方法

    /**
     * 写入微信外挂模式配置（授权数据由回调 query 参数直读，无需请求微信 API）
     */
    protected function configWechatProxy(): void
    {
        $this->setConfig('wechat_enabled', '1');
        $this->setConfig('wechat_mp_app_id', 'wx_test_app_id');
        $this->setConfig('wechat_mp_app_secret', 'wx_test_secret');
        $this->setConfig('wechat_oauth_enabled', '1');
        $this->setConfig('wechat_oauth_url', 'https://proxy.example.com/wx');
    }

    /**
     * 走 login-url 拿到 state（session 已存校验值与跳转地址），返回 state
     */
    protected function fetchWechatState(string $redirect = ''): string
    {
        $response = $this->getJson('/api/cmspro/sso/auth/wechat/login-url?' . http_build_query(array_filter([
            'redirect' => $redirect,
        ])))->assertOk();

        $this->assertSame('proxy', (string) $response->json('data.mode'));

        return (string) $response->json('data.state');
    }

    /**
     * 直接写入一条微信暂存授权数据，返回 token
     */
    protected function putOauthData(): string
    {
        return $this->putOauthDataFor('wechat', 'wx_888', '微信昵称');
    }

    /**
     * 直接写入一条指定平台的暂存授权数据，返回 token
     */
    protected function putOauthDataFor(string $platform, string $openid, string $nickname): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put('cmspro_sso_oauth_data:' . $token, [
            'openid'        => $openid,
            'unionid'       => null,
            'nickname'      => $nickname,
            'avatar'        => 'https://a.b/x.png',
            'access_token'  => '',
            'refresh_token' => '',
            'platform'      => $platform,
            'raw_data'      => null,
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
