<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproSso\Models\InviteCode;
use App\Apps\CmsproSso\Models\InviteOrder;
use App\Apps\CmsproSso\Services\SmsService;
use App\Apps\Onlinepay\Events\OrderPaid;
use App\Apps\Onlinepay\Models\PayOrder;
use App\Models\User;
use App\Models\UserThirdParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 邀请注册闭环测试
 *
 * 覆盖：
 *   1. 邀请注册开关控制：关闭时无需邀请码，开启时必填；
 *   2. 邀请码校验：不存在/已使用/已禁用均拒绝，有效码注册成功并标记已使用；
 *   3. 手机验证码登录新号自动建号同样需要邀请码；
 *   4. 在线购买：未开启时拒绝下单，OrderPaid 事件幂等发放邀请码；
 *   5. 订单状态轮询凭 query_token 访问。
 */
class SsoInviteRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    public function test_关闭邀请注册时无需邀请码(): void
    {
        $this->setConfig('invite_enabled', '0');

        $this->postJson('/api/cmspro/sso/auth/register', [
            'username' => 'user_noinvite',
            'password' => '123456',
            'password_confirmation' => '123456',
        ])->assertOk()->assertJsonPath('code', 0);

        $this->assertDatabaseHas('users', ['username' => 'user_noinvite']);
    }

    public function test_开启邀请注册后缺少邀请码被拒绝(): void
    {
        $this->setConfig('invite_enabled', '1');

        $this->postJson('/api/cmspro/sso/auth/register', [
            'username' => 'user_nocode',
            'password' => '123456',
            'password_confirmation' => '123456',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['username' => 'user_nocode']);
    }

    public function test_无效邀请码被拒绝(): void
    {
        $this->setConfig('invite_enabled', '1');

        // 不存在的邀请码
        $this->postJson('/api/cmspro/sso/auth/register', $this->registerPayload('WRONGCODE'))
            ->assertStatus(422)
            ->assertJsonPath('message', '邀请码不存在');
        $this->assertDatabaseMissing('users', ['username' => 'user_bad_code']);

        // 已禁用的邀请码
        $this->createCode(['code' => 'DISABLEDCODE1', 'status' => InviteCode::STATUS_DISABLED]);
        $this->postJson('/api/cmspro/sso/auth/register', $this->registerPayload('DISABLEDCODE1'))
            ->assertStatus(422)
            ->assertJsonPath('message', '邀请码已被使用或已禁用');
        $this->assertDatabaseMissing('users', ['username' => 'user_bad_code']);
    }

    public function test_有效邀请码注册成功并标记已使用(): void
    {
        $this->setConfig('invite_enabled', '1');
        $code = $this->createCode(['code' => 'GOODCODE23456789']);

        $this->postJson('/api/cmspro/sso/auth/register', $this->registerPayload('goodcode23456789'))
            ->assertOk()
            ->assertJsonPath('code', 0);

        $user = User::where('username', 'user_invite')->first();
        $this->assertNotNull($user);

        // 邀请码已标记使用（输入小写也应匹配，存储为大写）
        $code->refresh();
        $this->assertSame(InviteCode::STATUS_USED, (int) $code->status);
        $this->assertSame($user->id, (int) $code->used_by);
        $this->assertNotNull($code->used_at);
    }

    public function test_已使用邀请码不可重复使用(): void
    {
        $this->setConfig('invite_enabled', '1');
        $code = $this->createCode(['code' => 'ONECODE234567890']);

        $this->postJson('/api/cmspro/sso/auth/register', $this->registerPayload($code->code, 'user_first'))
            ->assertOk();

        // 第二个用户使用同一邀请码
        $this->postJson('/api/cmspro/sso/auth/register', $this->registerPayload($code->code, 'user_second'))
            ->assertStatus(422)
            ->assertJsonPath('message', '邀请码已被使用或已禁用');

        $this->assertDatabaseMissing('users', ['username' => 'user_second']);
    }

    public function test_手机验证码登录新号需要邀请码(): void
    {
        $this->setConfig('invite_enabled', '1');
        $this->setConfig('phone_login_enabled', '1');

        $phone = '13800001234';
        $this->cachePhoneCode($phone, '123456');

        // 未携带邀请码：拒绝
        $this->postJson('/api/cmspro/sso/auth/phone/login', [
            'phone' => $phone,
            'code'  => '123456',
        ])->assertStatus(422)
          ->assertJsonPath('message', '注册新账号需要邀请码');

        // 携带有效邀请码：建号成功并消费邀请码
        $code = $this->createCode(['code' => 'PHONECODE234567']);
        $this->cachePhoneCode($phone, '654321');

        $this->postJson('/api/cmspro/sso/auth/phone/login', [
            'phone' => $phone,
            'code'  => '654321',
            'invite_code' => $code->code,
        ])->assertOk()->assertJsonPath('code', 0);

        $user = User::where('phone', $phone)->first();
        $this->assertNotNull($user);
        $code->refresh();
        $this->assertSame(InviteCode::STATUS_USED, (int) $code->status);
        $this->assertSame($user->id, (int) $code->used_by);
    }

    public function test_获取验证码阶段拦截新手机号需要邀请码(): void
    {
        $this->setConfig('invite_enabled', '1');
        $this->setConfig('phone_login_enabled', '1');

        $url = '/api/cmspro/sso/auth/phone/code';
        $phone = '13800009999';

        // mock 短信服务：仅配置 isConfigured，验证码发送若被意外调用则测试失败
        $sms = $this->mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);

        // 新手机号未带邀请码：拦截且不发送验证码
        $this->postJson($url, ['phone' => $phone])
            ->assertStatus(422)
            ->assertJsonPath('message', '该手机号未注册，注册新账号需要邀请码')
            ->assertJsonPath('data.need_invite', true);

        // 新手机号 + 无效邀请码：拒绝
        $this->postJson($url, ['phone' => $phone, 'invite_code' => 'WRONGCODE'])
            ->assertStatus(422)
            ->assertJsonPath('message', '邀请码不存在');

        // 新手机号 + 有效邀请码（大小写不敏感）：预校验通过，正常发送
        $code = $this->createCode(['code' => 'SENDCODE2345678']);
        $sms->shouldReceive('sendVerificationCode')->once()->andReturn(['success' => true, 'message' => 'ok']);
        $this->postJson($url, ['phone' => $phone, 'invite_code' => strtolower($code->code)])
            ->assertOk()
            ->assertJsonPath('code', 0);

        // 老用户：手机号已存在，无需邀请码正常发送
        User::create([
            'username' => 'phone_old_user',
            'password' => 'old_password_hash',
            'phone'    => $phone,
            'status'   => 1,
        ]);
        $sms->shouldReceive('sendVerificationCode')->once()->andReturn(['success' => true, 'message' => 'ok']);
        $this->postJson($url, ['phone' => $phone])
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    public function test_在线购买未开启时拒绝下单(): void
    {
        $this->postJson('/api/cmspro/sso/invite/order', ['channel' => 'wechat'])
            ->assertStatus(400)
            ->assertJsonPath('message', '在线购买未开启');

        $this->getJson('/api/cmspro/sso/invite/channels')
            ->assertStatus(400);
    }

    public function test_支付成功事件发放邀请码且幂等(): void
    {
        $order = InviteOrder::create([
            'out_trade_no' => InviteOrder::generateTradeNo(),
            'query_token'  => InviteOrder::generateQueryToken(),
            'amount'       => 9.90,
            'status'       => InviteOrder::STATUS_PENDING,
        ]);

        $payOrder = (new PayOrder)->forceFill([
            'out_trade_no' => $order->out_trade_no,
            'order_no'     => 'ONL_TEST_0001',
            'type'         => 'payment',
        ]);

        Event::dispatch(new OrderPaid($payOrder));

        // 订单已支付，发放邀请码（价格与订单金额一致）
        $order->refresh();
        $this->assertSame(InviteOrder::STATUS_PAID, (int) $order->status);
        $this->assertNotNull($order->invite_code_id);
        $code = InviteCode::find($order->invite_code_id);
        $this->assertNotNull($code);
        $this->assertSame(InviteCode::STATUS_UNUSED, (int) $code->status);
        $this->assertSame(9.9, (float) $code->price);

        // 幂等：重复派发不重复发放
        Event::dispatch(new OrderPaid($payOrder));
        $this->assertSame(1, InviteCode::count());
        $this->assertSame(1, InviteOrder::where('out_trade_no', $order->out_trade_no)
            ->where('status', InviteOrder::STATUS_PAID)
            ->count());
    }

    public function test_订单状态轮询凭token访问(): void
    {
        $order = InviteOrder::create([
            'out_trade_no' => InviteOrder::generateTradeNo(),
            'query_token'  => InviteOrder::generateQueryToken(),
            'amount'       => 9.90,
            'status'       => InviteOrder::STATUS_PENDING,
        ]);

        // 正确 token：待支付
        $this->getJson('/api/cmspro/sso/invite/order/status?' . http_build_query([
                'out_trade_no' => $order->out_trade_no,
                'query_token'  => $order->query_token,
            ]))->assertOk()
            ->assertJsonPath('data.paid', false)
            ->assertJsonPath('data.invite_code', '');

        // 错误 token：订单不存在
        $this->getJson('/api/cmspro/sso/invite/order/status?' . http_build_query([
                'out_trade_no' => $order->out_trade_no,
                'query_token'  => str_repeat('0', 32),
            ]))->assertStatus(404);
    }

    public function test_微信登录授权URL阶段不收集邀请码(): void
    {
        $this->setConfig('invite_enabled', '1');
        $this->setConfig('wechat_enabled', '1');
        $this->setConfig('wechat_mp_app_id', 'wx_test_app_id');
        $this->setConfig('wechat_mp_app_secret', 'test_secret');

        // 新逻辑：授权 URL 阶段不收集邀请码，直接返回授权链接
        // （账号是否存在需授权后凭 openid 判断，新用户在回调后提示填写邀请码）
        $this->get('/api/cmspro/sso/auth/wechat/login-url')
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    public function test_微信回调新用户暂存授权数据并凭token提交邀请码建号(): void
    {
        $this->setConfig('invite_enabled', '1');
        $code = $this->createCode(['code' => 'WXCALLBACK234567']);

        $state = 'test_state_123';
        $response = $this->withSession([
            'cmspro_sso_wechat_oauth_state' => $state,
        ])->get('/sso/auth/wechat/callback?' . http_build_query([
            'openid'   => 'openid_new_user_001',
            'nickname' => '微信新用户',
            'state'    => $state,
        ]));

        // 新用户且开启邀请注册：未建号，redirect 到邀请码填写页（token 随 URL 携带）
        $response->assertRedirect();
        $this->assertStringContainsString('complete-invite', (string) $response->headers->get('Location'));
        $this->assertNull(UserThirdParty::where('open_id', 'openid_new_user_001')->first());

        // 无效邀请码：拒绝且授权数据保留，可重新提交
        $location = (string) $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY) ?? '', $query);
        $token = (string) ($query['token'] ?? '');
        $this->assertNotSame('', $token);

        $this->postJson('/api/cmspro/sso/auth/wechat/invite', [
            'token'       => $token,
            'invite_code' => 'WRONGCODE',
        ])->assertStatus(422)
          ->assertJsonPath('message', '邀请码不存在');

        // 有效邀请码：建号成功并消费邀请码
        $this->postJson('/api/cmspro/sso/auth/wechat/invite', [
            'token'       => $token,
            'invite_code' => strtolower($code->code),
        ])->assertOk()
          ->assertJsonPath('code', 0);

        $binding = UserThirdParty::where('open_id', 'openid_new_user_001')->where('platform', 'wechat')->first();
        $this->assertNotNull($binding);
        $user = User::find($binding->user_id);
        $this->assertNotNull($user);

        // 邀请码已消费（输入小写也应匹配，存储为大写）
        $code->refresh();
        $this->assertSame(InviteCode::STATUS_USED, (int) $code->status);
        $this->assertSame($user->id, (int) $code->used_by);
        $this->assertNotNull($code->used_at);
    }

    public function test_微信回调老用户不消费邀请码(): void
    {
        $this->setConfig('invite_enabled', '1');
        $code = $this->createCode(['code' => 'WXOLDUSER234567']);

        $oldUser = User::create([
            'username' => 'wx_old_user',
            'password' => 'wx_old_password_hash',
            'nickname' => '微信老用户',
            'status'   => 1,
        ]);
        UserThirdParty::create([
            'user_id'  => $oldUser->id,
            'platform' => 'wechat',
            'open_id'  => 'openid_old_user_001',
        ]);

        $state = 'test_state_456';
        $this->withSession([
            'cmspro_sso_wechat_oauth_state' => $state,
        ])->get('/sso/auth/wechat/callback?' . http_build_query([
            'openid'   => 'openid_old_user_001',
            'nickname' => '微信老用户',
            'state'    => $state,
        ]))->assertRedirect();

        // 老用户登录成功，邀请码未被消费（仍可分配给新用户注册）
        $this->assertDatabaseHas('user_third_party', [
            'platform' => 'wechat',
            'open_id'  => 'openid_old_user_001',
            'user_id'  => $oldUser->id,
        ]);
        $code->refresh();
        $this->assertSame(InviteCode::STATUS_UNUSED, (int) $code->status);
    }

    /**
     * 注册请求载荷
     */
    private function registerPayload(string $inviteCode, string $username = 'user_invite'): array
    {
        return [
            'username' => $username,
            'password' => '123456',
            'password_confirmation' => '123456',
            'invite_code' => $inviteCode,
        ];
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
     * 模拟已发送的手机验证码
     */
    private function cachePhoneCode(string $phone, string $code): void
    {
        Cache::put('cmspro_sso_phone_code:' . $phone, $code, now()->addMinutes(10));
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
