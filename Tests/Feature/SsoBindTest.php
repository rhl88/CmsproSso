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
 * 统一登录应用 · 绑定中心测试
 *
 * 覆盖：
 *   1. 绑定列表查询：未登录 401、已登录返回各平台绑定状态与脱敏手机号；
 *   2. 绑定授权 URL：未登录 401、平台未启用/未配置拒绝、已绑定拒绝、URL 与 state 生成；
 *   3. 回调绑定落库：正常绑定、openid 冲突（他人绑定/自己重复绑定）；
 *   4. 手机号绑定：验证码校验、手机号被占用拒绝、绑定成功；
 *   5. 解绑：正常解绑、仅剩一种登录方式时拒绝解绑（防锁死）、非法平台拒绝。
 */
class SsoBindTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. 绑定列表

    public function test_未登录查询绑定列表返回401(): void
    {
        $this->getJson('/api/cmspro/sso/user/bindings')
            ->assertStatus(401);
    }

    public function test_已登录查询绑定列表返回各平台状态(): void
    {
        $this->setConfig('phone_login_enabled', '1');
        $this->setConfig('qq_enabled', '1');
        $user = $this->createUser(['phone' => '13800001234']);
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'qq',
            'open_id'  => 'QQOPENID_BIND_001',
            'nickname' => 'QQ昵称',
        ]);

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/user/bindings')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $data = $response->json('data');
        $this->assertSame(['phone', 'qq'], $data['platforms']);
        $this->assertTrue($data['phone']['bound']);
        $this->assertSame('138****1234', $data['phone']['phone']);
        $this->assertFalse($data['phone']['locked']);
        $this->assertArrayNotHasKey('wechat', $data);
        $this->assertTrue($data['qq']['bound']);
        $this->assertSame('QQ昵称', $data['qq']['nickname']);
    }

    public function test_未开启的平台不出现在绑定列表(): void
    {
        $user = $this->createUser();
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'qq',
            'open_id'  => 'QQOPENID_BIND_002',
            'nickname' => 'QQ昵称',
        ]);

        // 后台未开启任何快捷登录时，绑定列表为空
        $data = $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/user/bindings')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->json('data');
        $this->assertSame([], $data['platforms']);

        // 仅开启手机号：微信/QQ 均不返回
        $this->setConfig('phone_login_enabled', '1');
        $data = $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/user/bindings')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->json('data');
        $this->assertSame(['phone'], $data['platforms']);
        $this->assertArrayNotHasKey('wechat', $data);
        $this->assertArrayNotHasKey('qq', $data);
    }

    // ---------------------------------------------------------------- 2. 绑定授权 URL

    public function test_未登录获取绑定URL返回401(): void
    {
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        $this->getJson('/api/cmspro/sso/auth/qq/bind-url')
            ->assertStatus(401);
    }

    public function test_平台未启用时获取绑定URL被拒绝(): void
    {
        $user = $this->createUser();
        $this->setConfig('qq_enabled', '0');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-url')
            ->assertStatus(400)
            ->assertJsonPath('message', 'QQ用户登录未启用');
    }

    public function test_平台未配置时获取绑定URL被拒绝(): void
    {
        $user = $this->createUser();
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '');
        $this->setConfig('qq_app_key', '');

        $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-url')
            ->assertStatus(400)
            ->assertJsonPath('message', 'QQ用户登录未配置，请联系管理员');
    }

    public function test_已绑定平台时再次获取绑定URL被拒绝(): void
    {
        $user = $this->createUser();
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'qq',
            'open_id'  => 'QQOPENID_EXIST_001',
        ]);
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-url')
            ->assertStatus(400)
            ->assertJsonPath('message', '已绑定QQ用户，请先解绑');
    }

    public function test_QQ绑定URL生成成功且state存绑定专用键(): void
    {
        $user = $this->createUser();
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-url')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $url = (string) $response->json('data.url');
        $state = (string) $response->json('data.state');
        $this->assertStringContainsString('https://graph.qq.com/oauth2.0/authorize', $url);
        $this->assertNotSame('', $state);
        // 绑定模式：state 存 session 绑定专用键（与登录 state 键隔离），不存 Cache
        $response->assertSessionHas('cmspro_sso_qq_bind_oauth_state', $state);
        $this->assertFalse(Cache::has('cmspro_sso_login_state:' . $state));
        // 原生模式返回 mode=native
        $this->assertSame('native', $response->json('data.mode'));
    }

    // ---------------------------------------------------------------- 3. 回调绑定落库

    public function test_QQ回调绑定成功且不改变登录态(): void
    {
        $user = $this->createUser();
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->fakeQqApis('QQOPENID_BIND_NEW_001');

        $state = 'qq_bind_state_001';
        $response = $this->actingAs($user, 'web')
            ->withSession(['cmspro_sso_qq_bind_oauth_state' => $state])
            ->get('/sso/auth/qq/callback?' . http_build_query([
                'code'  => 'QQ_CODE_BIND_001',
                'state' => $state,
            ]));

        // 绑定成功回首页并提示
        $response->assertRedirect();
        $this->assertStringContainsString('/home', (string) $response->headers->get('Location'));
        $response->assertSessionHas('success', 'QQ用户绑定成功');

        // 绑定关系落库，未创建新用户
        $binding = UserThirdParty::where('open_id', 'QQOPENID_BIND_NEW_001')
            ->where('platform', 'qq')
            ->first();
        $this->assertNotNull($binding);
        $this->assertSame($user->id, (int) $binding->user_id);
        $this->assertSame(1, User::count());
        // 登录态未改变
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_QQ回调绑定openid被他人占用时拒绝(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser(['username' => 'other_user']);
        UserThirdParty::create([
            'user_id'  => $otherUser->id,
            'platform' => 'qq',
            'open_id'  => 'QQOPENID_TAKEN_001',
        ]);
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->fakeQqApis('QQOPENID_TAKEN_001');

        $state = 'qq_bind_state_conflict';
        $response = $this->actingAs($user, 'web')
            ->withSession(['cmspro_sso_qq_bind_oauth_state' => $state])
            ->get('/sso/auth/qq/callback?' . http_build_query([
                'code'  => 'QQ_CODE_TAKEN',
                'state' => $state,
            ]));

        // 回首页提示冲突，不落库
        $response->assertRedirect();
        $response->assertSessionHas('error', '该QQ用户已绑定其他账号，无法重复绑定');
        $this->assertSame(1, UserThirdParty::where('open_id', 'QQOPENID_TAKEN_001')->count());
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_QQ回调绑定自己已绑定时提示无需重复(): void
    {
        $user = $this->createUser();
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'qq',
            'open_id'  => 'QQOPENID_SELF_001',
        ]);
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->fakeQqApis('QQOPENID_SELF_001');

        $state = 'qq_bind_state_self';
        $response = $this->actingAs($user, 'web')
            ->withSession(['cmspro_sso_qq_bind_oauth_state' => $state])
            ->get('/sso/auth/qq/callback?' . http_build_query([
                'code'  => 'QQ_CODE_SELF',
                'state' => $state,
            ]));

        $response->assertRedirect();
        $response->assertSessionHas('error', '已绑定QQ用户，无需重复绑定');
    }

    // ---------------------------------------------------------------- 4. 手机号绑定

    public function test_手机号绑定成功(): void
    {
        $user = $this->createUser();
        $this->setConfig('phone_login_enabled', '1');
        $this->setConfig('sms_enabled', '1');

        // 预置验证码缓存
        Cache::put('cmspro_sso_phone_code:13811112222', '123456', 300);

        $this->actingAs($user, 'web')
            ->postJson('/api/cmspro/sso/user/bind/phone', [
                'phone' => '13811112222',
                'code'  => '123456',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '手机号绑定成功');

        $user->refresh();
        $this->assertSame('13811112222', $user->phone);
        // 验证码一次性失效
        $this->assertFalse(Cache::has('cmspro_sso_phone_code:13811112222'));
    }

    public function test_手机号已被其他账号使用时绑定被拒绝(): void
    {
        $user = $this->createUser();
        $this->createUser(['username' => 'phone_user', 'phone' => '13833334444']);
        $this->setConfig('phone_login_enabled', '1');
        $this->setConfig('sms_enabled', '1');

        Cache::put('cmspro_sso_phone_code:13833334444', '123456', 300);

        $this->actingAs($user, 'web')
            ->postJson('/api/cmspro/sso/user/bind/phone', [
                'phone' => '13833334444',
                'code'  => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', '该手机号已被其他账号使用');

        $user->refresh();
        $this->assertNull($user->phone);
        // 验证码未被消费
        $this->assertTrue(Cache::has('cmspro_sso_phone_code:13833334444'));
    }

    public function test_手机验证码错误时绑定被拒绝(): void
    {
        $user = $this->createUser();
        $this->setConfig('phone_login_enabled', '1');
        $this->setConfig('sms_enabled', '1');

        Cache::put('cmspro_sso_phone_code:13855556666', '123456', 300);

        $this->actingAs($user, 'web')
            ->postJson('/api/cmspro/sso/user/bind/phone', [
                'phone' => '13855556622',
                'code'  => '000000',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', '验证码不正确或已过期');

        $user->refresh();
        $this->assertNull($user->phone);
    }

    // ---------------------------------------------------------------- 5. 解绑

    public function test_解绑QQ成功(): void
    {
        $user = $this->createUser(['password' => 'real_password_hash']);
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'qq',
            'open_id'  => 'QQOPENID_UNBIND_001',
        ]);

        $this->actingAs($user, 'web')
            ->postJson('/api/cmspro/sso/user/unbind/qq')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', 'QQ用户已解绑');

        $this->assertDatabaseMissing('user_third_party', [
            'user_id'  => $user->id,
            'platform' => 'qq',
        ]);
    }

    public function test_仅剩一种登录方式时解绑被拒绝(): void
    {
        // 密码不可用（平台随机密码前缀 qq_），仅 QQ 一种登录方式
        $user = $this->createUser(['username' => 'qq_only_user', 'password' => 'qq_random_password']);
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'qq',
            'open_id'  => 'QQOPENID_LOCKED_001',
        ]);

        $this->actingAs($user, 'web')
            ->postJson('/api/cmspro/sso/user/unbind/qq')
            ->assertStatus(422)
            ->assertJsonPath('message', '当前仅剩此一种登录方式，不可解绑');

        $this->assertDatabaseHas('user_third_party', [
            'user_id'  => $user->id,
            'platform' => 'qq',
        ]);
    }

    public function test_解绑未绑定的平台返回400(): void
    {
        $user = $this->createUser();

        $this->actingAs($user, 'web')
            ->postJson('/api/cmspro/sso/user/unbind/wechat')
            ->assertStatus(400)
            ->assertJsonPath('message', '该平台尚未绑定');
    }

    public function test_非法解绑类型返回400(): void
    {
        $user = $this->createUser();

        $this->actingAs($user, 'web')
            ->postJson('/api/cmspro/sso/user/unbind/dingtalk')
            ->assertStatus(400)
            ->assertJsonPath('message', '不支持的解绑类型');
    }

    public function test_未登录解绑返回401(): void
    {
        $this->postJson('/api/cmspro/sso/user/unbind/qq')
            ->assertStatus(401);
    }

    // ---------------------------------------------------------------- 辅助方法

    /**
     * 模拟 QQ 互联全部外部接口
     */
    private function fakeQqApis(string $openid): void
    {
        Http::fake([
            'https://graph.qq.com/oauth2.0/token*' => Http::response(
                'access_token=TEST_ACCESS_TOKEN&expires_in=7776000&refresh_token=TEST_REFRESH_TOKEN'
            ),
            'https://graph.qq.com/oauth2.0/me*' => Http::response(
                'callback( {"client_id":"101234567","openid":"' . $openid . '"} );'
            ),
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
     * 创建测试用户
     */
    private function createUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'username' => 'bind_user_' . uniqid(),
            'password' => 'bind_user_password_hash',
            'nickname' => '绑定测试用户',
            'status'   => 1,
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
    }
}
