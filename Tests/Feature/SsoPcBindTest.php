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
 * 统一登录应用 · 绑定中心 PC 扫码绑定测试
 *
 * 覆盖 PC 端弹二维码跨设备扫码绑定闭环：
 *   1. 绑定授权 URL（device=pc）：state 存 Cache 绑定专用键（含目标用户），返回 device=pc；
 *   2. 扫码回调绑定落库：绑定到发起用户（不依赖回调请求自身登录态）、openid 冲突/目标失效拒绝；
 *   3. bind-status 轮询：尚未扫码 bound=false、成功 bound=true 且一次性消费、失败返回错误。
 */
class SsoPcBindTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. 绑定授权 URL（device=pc）

    public function test_未登录获取扫码绑定URL返回401(): void
    {
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        $this->getJson('/api/cmspro/sso/auth/qq/bind-url?device=pc')
            ->assertStatus(401);
    }

    public function test_PC扫码绑定URL返回device_pc且state存Cache绑定键(): void
    {
        $user = $this->createUser();
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-url?device=pc')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $state = (string) $response->json('data.state');
        $this->assertSame('pc', $response->json('data.device'));
        $this->assertStringContainsString('https://graph.qq.com/oauth2.0/authorize', (string) $response->json('data.url'));
        // 绑定扫码：state 存 Cache 绑定专用键（含目标用户），而非 session
        $bindCache = Cache::get('cmspro_sso_bind_state:' . $state);
        $this->assertSame($user->id, (int) ($bindCache['user_id'] ?? 0));
        $response->assertSessionMissing('cmspro_sso_qq_bind_oauth_state');
    }

    public function test_非pc模式绑定URL返回device为空且不写Cache(): void
    {
        $user = $this->createUser();
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-url')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $state = (string) $response->json('data.state');
        $this->assertSame('', $response->json('data.device'));
        // 跳转绑定：state 存 session，不写 Cache 绑定键
        $this->assertFalse(Cache::has('cmspro_sso_bind_state:' . $state));
        $response->assertSessionHas('cmspro_sso_qq_bind_oauth_state', $state);
    }

    public function test_已绑定平台时即使PC扫码也拒绝(): void
    {
        $user = $this->createUser();
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'qq',
            'open_id'  => 'QQOPENID_PC_EXIST_001',
        ]);
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-url?device=pc')
            ->assertStatus(400)
            ->assertJsonPath('message', '已绑定QQ用户，请先解绑');
    }

    // ---------------------------------------------------------------- 1.5 绑定展示形态（display 由后端统一决策）

    public function test_PC绑定display_qq_mode为qrcode时返回内嵌扫码(): void
    {
        $user = $this->createUser();
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->setConfig('qq_mode', 'qrcode');

        $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-url?device=pc')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.display', 'qrcode');
    }

    public function test_PC绑定display_qq_mode为web时返回跳转授权(): void
    {
        $user = $this->createUser();
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->setConfig('qq_mode', 'web');

        $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-url?device=pc')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.display', 'redirect');
    }

    public function test_PC绑定display_未配置qq_mode时默认跳转授权(): void
    {
        $user = $this->createUser();
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');

        $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-url?device=pc')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.display', 'redirect');
    }

    public function test_PC绑定display_微信固定返回内嵌扫码(): void
    {
        $user = $this->createUser();
        $this->setConfig('wechat_enabled', '1');
        $this->setConfig('wechat_mp_app_id', 'wx_test_app_id');
        $this->setConfig('wechat_mp_app_secret', 'wx_test_app_secret');

        $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/wechat/bind-url?device=pc')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.display', 'qrcode');
    }

    public function test_非pc绑定display固定返回跳转授权(): void
    {
        $user = $this->createUser();
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->setConfig('qq_mode', 'qrcode');

        $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-url')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.display', 'redirect');
    }

    // ---------------------------------------------------------------- 2. 扫码回调绑定落库

    public function test_PC扫码回调绑定成功落到发起用户且写bound结果(): void
    {
        $user = $this->createUser();
        $state = 'qq_pc_bind_state_success';
        Cache::put('cmspro_sso_bind_state:' . $state, ['user_id' => $user->id], 600);
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->fakeQqApis('QQOPENID_PC_BIND_OK_001');

        // 回调模拟手机浏览器（未登录），绑定目标取自 Cache，与回调请求自身登录态无关
        $response = $this->get('/sso/auth/qq/callback?' . http_build_query([
            'code'  => 'QQ_CODE_PC_BIND',
            'state' => $state,
        ]));

        $response->assertRedirect();
        // 手机端跳转无需登录的绑定结果提示页（不能跳受保护 /home，否则手机端无登录态会被重定向到登录页）
        $this->assertStringContainsString('/auth/qq/pc-success?result=bind_success', (string) $response->headers->get('Location'));

        // 绑定落库到 Cache 中发起的用户，甚至回调未登录也可完成
        $binding = UserThirdParty::where('platform', 'qq')
            ->where('open_id', 'QQOPENID_PC_BIND_OK_001')
            ->first();
        $this->assertNotNull($binding);
        $this->assertSame($user->id, (int) $binding->user_id);
        // 回调请求自身未登录，登录态不变
        $this->assertGuest('web');
        // 绑定结果写 Cache，供 PC 端轮询
        $this->assertSame(['bound' => true], Cache::get('cmspro_sso_bind_result:' . $state));
    }

    public function test_PC扫码回调openid被他人占用时写error(): void
    {
        $user = $this->createUser();
        $other = $this->createUser(['username' => 'other_user']);
        UserThirdParty::create([
            'user_id'  => $other->id,
            'platform' => 'qq',
            'open_id'  => 'QQOPENID_PC_TAKEN_001',
        ]);
        $state = 'qq_pc_bind_state_conflict';
        Cache::put('cmspro_sso_bind_state:' . $state, ['user_id' => $user->id], 600);
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->fakeQqApis('QQOPENID_PC_TAKEN_001');

        $response = $this->get('/sso/auth/qq/callback?' . http_build_query([
            'code'  => 'QQ_CODE_PC_TAKEN',
            'state' => $state,
        ]));

        $response->assertRedirect();
        // 手机端跳转绑定失败提示页（无需登录）
        $this->assertStringContainsString('/auth/qq/pc-success?result=bind_failed', (string) $response->headers->get('Location'));
        // 未新增绑定记录
        $this->assertSame(1, UserThirdParty::where('open_id', 'QQOPENID_PC_TAKEN_001')->count());
        // 结果写 Cache 供 PC 端轮询
        $result = Cache::get('cmspro_sso_bind_result:' . $state);
        $this->assertSame('该QQ用户已绑定其他账号，无法重复绑定', $result['error']);
    }

    public function test_PC扫码回调目标用户不存在时写error(): void
    {
        $state = 'qq_pc_bind_state_nouser';
        Cache::put('cmspro_sso_bind_state:' . $state, ['user_id' => 999999], 600);
        $this->setConfig('qq_enabled', '1');
        $this->setConfig('qq_app_id', '101234567');
        $this->setConfig('qq_app_key', 'test_qq_app_key');
        $this->fakeQqApis('QQOPENID_PC_NOUSER_001');

        $response = $this->get('/sso/auth/qq/callback?' . http_build_query([
            'code'  => 'QQ_CODE_PC_NOUSER',
            'state' => $state,
        ]));

        $response->assertRedirect();
        // 手机端跳转绑定失败提示页（无需登录）
        $this->assertStringContainsString('/auth/qq/pc-success?result=bind_failed', (string) $response->headers->get('Location'));
        $result = Cache::get('cmspro_sso_bind_result:' . $state);
        $this->assertSame('绑定目标不存在或已注销，无法完成绑定', $result['error']);
    }

    // ---------------------------------------------------------------- 3. bind-status 轮询

    public function test_bindStatus未登录返回401(): void
    {
        $this->getJson('/api/cmspro/sso/auth/qq/bind-status?state=abc')
            ->assertStatus(401);
    }

    public function test_bindStatus尚未扫码返回bound_false(): void
    {
        $user = $this->createUser();

        $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-status?state=qq_pc_bind_pending')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.bound', false);
    }

    public function test_bindStatus成功后返回bound_true且一次性消费(): void
    {
        $user = $this->createUser();
        $state = 'qq_pc_bind_status_ok';
        Cache::put('cmspro_sso_bind_result:' . $state, ['bound' => true], 600);

        $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-status?state=' . $state)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.bound', true);

        // 一次性结果，消费后即清理
        $this->assertFalse(Cache::has('cmspro_sso_bind_result:' . $state));
    }

    public function test_bindStatus失败返回422和错误信息(): void
    {
        $user = $this->createUser();
        $state = 'qq_pc_bind_status_err';
        Cache::put('cmspro_sso_bind_result:' . $state, ['error' => '该QQ用户已绑定其他账号，无法重复绑定'], 600);

        $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/auth/qq/bind-status?state=' . $state)
            ->assertStatus(422)
            ->assertJsonPath('message', '该QQ用户已绑定其他账号，无法重复绑定');
    }

    public function test_绑定结果提示页未登录可访问且展示绑定结果文案(): void
    {
        // 手机端扫码授权后跳转的绑定结果提示页无需登录即可访问（公开路由）
        $this->get('/sso/auth/qq/pc-success?result=bind_success')
            ->assertOk()
            ->assertSee('绑定成功');
        $this->get('/sso/auth/qq/pc-success?result=bind_failed')
            ->assertOk()
            ->assertSee('绑定失败');
    }

    // ---------------------------------------------------------------- 绑定列表详情字段

    public function test_绑定列表已绑定平台返回详情字段(): void
    {
        // 启用手机号 / 邮箱 / 微信 / QQ 四类登录方式
        $this->setConfig('phone_login_enabled', '1');
        config(['apps.cmspro.sso.email_login_enabled' => '1']);
        config(['mail.mailers.smtp.username' => 'noreply@example.com']);
        $this->setConfig('wechat_enabled', '1');
        $this->setConfig('qq_enabled', '1');

        $user = $this->createUser([
            'phone' => '13800001234',
            'email' => 'user@example.com',
        ]);
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'wechat',
            'open_id'  => 'WX_OPENID_BIND_001',
            'nickname' => '微信昵称',
        ]);
        UserThirdParty::create([
            'user_id'  => $user->id,
            'platform' => 'qq',
            'open_id'  => 'QQ_OPENID_BIND_001',
            'nickname' => 'QQ昵称',
        ]);

        $data = $this->actingAs($user, 'web')
            ->getJson('/api/cmspro/sso/user/bindings')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->json('data');

        // 平台顺序与绑定中心展示一致
        $this->assertSame(['phone', 'email', 'wechat', 'qq'], $data['platforms']);
        // 已绑定：返回脱敏手机号
        $this->assertTrue($data['phone']['bound']);
        $this->assertSame('138****1234', $data['phone']['phone']);
        // 已绑定：返回脱敏邮箱（支撑前端展示绑定的邮箱）
        $this->assertTrue($data['email']['bound']);
        $this->assertStringContainsString('*', (string) $data['email']['email']);
        // 已绑定：返回绑定的微信/QQ 昵称
        $this->assertTrue($data['wechat']['bound']);
        $this->assertSame('微信昵称', $data['wechat']['nickname']);
        $this->assertTrue($data['qq']['bound']);
        $this->assertSame('QQ昵称', $data['qq']['nickname']);
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
            'username' => 'pc_bind_user_' . uniqid(),
            'password' => 'pc_bind_user_password_hash',
            'nickname' => 'PC扫码绑定测试用户',
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

    /**
     * 构建测试所需的最小表结构（config 配置表 + 用户表 + 第三方绑定表）
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