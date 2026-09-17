<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 手机验证码登录测试
 *
 * 覆盖：
 *   1. 未启用验证码登录时，发送验证码/手机登录均被拒绝；
 *   2. 启用后，发送验证码成功（通过 Http::fake 拦截阿里云短信调用）；
 *   3. 手机号不存在时，登录自动创建用户并返回 is_new=true；
 *   4. 手机号已存在时，直接登录并返回 is_new=false；
 *   5. 验证码一次性失效（使用后无法重复登录）。
 */
class SsoPhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);

        UserGroup::firstOrCreate(
            ['code' => 'default'],
            ['name' => '默认组', 'is_default' => 1, 'status' => 1]
        );

        // 启用手机验证码登录，并配置阿里云短信（模拟后台已配置）
        config(['apps.cmspro.sso.phone_login_enabled' => '1']);
        config(['apps.cmspro.sso.sms_aliyun_access_key_id' => 'test-key-id']);
        config(['apps.cmspro.sso.sms_aliyun_access_key_secret' => 'test-key-secret']);
        config(['apps.cmspro.sso.sms_aliyun_sign_name' => '测试签名']);
        config(['apps.cmspro.sso.sms_aliyun_template_code' => 'SMS_123456789']);

        // 模拟阿里云短信发送成功（闭包匹配：URL 可能无 path）
        Http::fake(
            fn () => Http::response(['Code' => 'OK'], 200)
        );
    }

    public function test_未启用时发送验证码被拒(): void
    {
        config(['apps.cmspro.sso.phone_login_enabled' => '0']);

        $this->postJson('/api/cmspro/sso/auth/phone/code', ['phone' => '13800138000'])
            ->assertStatus(400)
            ->assertJsonPath('code', 400);
    }

    public function test_未启用时手机登录被拒(): void
    {
        config(['apps.cmspro.sso.phone_login_enabled' => '0']);

        $this->postJson('/api/cmspro/sso/auth/phone/login', ['phone' => '13800138000', 'code' => '123456'])
            ->assertStatus(400)
            ->assertJsonPath('code', 400);
    }

    public function test_发送验证码成功并调用短信服务(): void
    {
        $this->postJson('/api/cmspro/sso/auth/phone/code', ['phone' => '13800138000'])
            ->assertOk()
            ->assertJsonPath('code', 0);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'dysmsapi.aliyuncs.com')
                && str_contains($request['PhoneNumbers'], '13800138000');
        });
    }

    public function test_手机验证码发送频率限制(): void
    {
        // 首次发送正常
        $this->postJson('/api/cmspro/sso/auth/phone/code', ['phone' => '13800138000'])
            ->assertOk();

        // 触发每分钟 3 次限流（同一手机号）
        $this->postJson('/api/cmspro/sso/auth/phone/code', ['phone' => '13800138000'])
            ->assertOk();
        $this->postJson('/api/cmspro/sso/auth/phone/code', ['phone' => '13800138000'])
            ->assertOk();

        // 第 4 次被限流
        $this->postJson('/api/cmspro/sso/auth/phone/code', ['phone' => '13800138000'])
            ->assertStatus(429)
            ->assertJsonPath('code', 429);
    }

    public function test_验证码正确且手机号已存在时自动登录(): void
    {
        // 预置已存在手机号的用户
        User::create([
            'username' => 'existing_user',
            'password' => bcrypt('123456'),
            'nickname' => '既有用户',
            'phone'    => '13900139000',
            'group_id' => UserGroup::where('code', 'default')->value('id'),
            'status'   => 1,
        ]);

        // 获取验证码并登录
        $this->obtainCode('13900139000', '654321');

        $this->postJson('/api/cmspro/sso/auth/phone/login', ['phone' => '13900139000', 'code' => '654321'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.is_new', false);

        // 不创建新用户（仍仅有预置用户，手机号未被占用为第二个用户）
        $this->assertDatabaseHas('users', ['username' => 'existing_user']);
        $this->assertDatabaseMissing('users', ['username' => '13900139000']);
    }

    public function test_手机号不存在时自动创建用户并提示完善(): void
    {
        $phone = '13700137000';
        $this->obtainCode($phone, '111111');

        $this->postJson('/api/cmspro/sso/auth/phone/login', ['phone' => $phone, 'code' => '111111'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.is_new', true)
            ->assertJsonPath('data.redirect', url('/sso/auth/wechat/complete'));

        // 自动创建手机号用户
        $this->assertDatabaseHas('users', ['phone' => $phone]);
    }

    public function test_验证码使用后一次性失效(): void
    {
        $phone = '13600136000';

        $this->obtainCode($phone, '222222');

        $this->postJson('/api/cmspro/sso/auth/phone/login', ['phone' => $phone, 'code' => '222222'])
            ->assertOk();

        // 同一验证码第二次登录失败（已一次性失效）
        $this->postJson('/api/cmspro/sso/auth/phone/login', ['phone' => $phone, 'code' => '222222'])
            ->assertJsonPath('code', 422);
    }

    public function test_登录成功后跳转到redirect参数指定地址(): void
    {
        User::create([
            'username' => 'redirect_user',
            'password' => bcrypt('123456'),
            'nickname' => '跳转用户',
            'phone'    => '13500135000',
            'group_id' => UserGroup::where('code', 'default')->value('id'),
            'status'   => 1,
        ]);

        $this->obtainCode('13500135000', '333333');

        $this->postJson('/api/cmspro/sso/auth/phone/login', [
            'phone'    => '13500135000',
            'code'     => '333333',
            'redirect' => '/forum',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.redirect', '/forum');
    }

    public function test_外部域名的redirect被拒绝并回退默认跳转(): void
    {
        User::create([
            'username' => 'redirect_safe_user',
            'password' => bcrypt('123456'),
            'nickname' => '安全用户',
            'phone'    => '13400134000',
            'group_id' => UserGroup::where('code', 'default')->value('id'),
            'status'   => 1,
        ]);

        $this->obtainCode('13400134000', '444444');

        $this->postJson('/api/cmspro/sso/auth/phone/login', [
            'phone'    => '13400134000',
            'code'     => '444444',
            'redirect' => 'http://evil.example.com/steal',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.redirect', '/');
    }

    public function test_同主域兄弟子域的redirect被放行(): void
    {
        User::create([
            'username' => 'redirect_subdomain_user',
            'password' => bcrypt('123456'),
            'nickname' => '子域用户',
            'phone'    => '13200132000',
            'group_id' => UserGroup::where('code', 'default')->value('id'),
            'status'   => 1,
        ]);

        $this->obtainCode('13200132000', '666666');

        // 模拟在 sso.cmspro.cn 登录（测试请求 host 由 URL 生成器 root 决定），redirect 指向兄弟子域 bbs.cmspro.cn
        \Illuminate\Support\Facades\URL::forceRootUrl('https://sso.cmspro.cn');
        $this->postJson('/api/cmspro/sso/auth/phone/login', [
                'phone'    => '13200132000',
                'code'     => '666666',
                'redirect' => 'https://bbs.cmspro.cn',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.redirect', 'https://bbs.cmspro.cn');
    }

    public function test_不同主域的redirect即使子域形态也被拒绝(): void
    {
        User::create([
            'username' => 'redirect_cross_user',
            'password' => bcrypt('123456'),
            'nickname' => '跨域用户',
            'phone'    => '13100131000',
            'group_id' => UserGroup::where('code', 'default')->value('id'),
            'status'   => 1,
        ]);

        $this->obtainCode('13100131000', '777777');

        // sso.cmspro.cn 登录，redirect 指向其他主域的子域形态（bbs.evil-cn.com）应被拒绝
        \Illuminate\Support\Facades\URL::forceRootUrl('https://sso.cmspro.cn');
        $this->postJson('/api/cmspro/sso/auth/phone/login', [
                'phone'    => '13100131000',
                'code'     => '777777',
                'redirect' => 'http://bbs.evil-cn.com/steal',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.redirect', '/');
    }

    public function test_新用户redirect透传到完善资料页(): void
    {
        $phone = '13300133000';
        $this->obtainCode($phone, '555555');

        $this->postJson('/api/cmspro/sso/auth/phone/login', [
            'phone'    => $phone,
            'code'     => '555555',
            'redirect' => '/abc',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.is_new', true)
            ->assertJsonPath('data.redirect', url('/sso/auth/wechat/complete') . '?redirect=%2Fabc');
    }

    /**
     * 预置手机验证码到缓存（绕过真实短信发送）
     */
    protected function obtainCode(string $phone, string $code): void
    {
        Cache::put('cmspro_sso_phone_code:' . $phone, $code, now()->addMinutes(10));
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
    }
}