<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproSso\Services\SmsService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 找回密码（邮箱/手机双通道）测试
 *
 * 覆盖：
 *   1. 找回密码页渲染：通道全关提示、单通道表单、双通道 tab
 *   2. 登录页「忘记密码」入口默认显示
 *   3. 手机找回 API：验证码发送（未注册手机拦截/成功发送/通道关闭拦截）与密码重置
 *      （验证码错误/一次性消费/两次密码不一致/禁用账号拦截）
 *   邮箱通道复用系统 /api/user/password/* 接口（框架级测试覆盖），此处不重复验证。
 */
class SsoForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. 页面渲染

    public function test_邮箱手机通道均关闭时显示未开启提示(): void
    {
        $this->get('/sso/forgot-password')
            ->assertOk()
            ->assertSee('系统未开启找回密码功能，请联系管理员')
            ->assertSee('返回登录')
            ->assertDontSee('请输入注册邮箱')
            ->assertDontSee('请输入注册手机号');
    }

    public function test_仅邮箱通道开启时显示邮箱表单且无tab(): void
    {
        $this->enableEmailChannel();

        $this->get('/sso/forgot-password')
            ->assertOk()
            ->assertSee('请输入注册邮箱')
            ->assertDontSee('请输入注册手机号')
            ->assertDontSee('手机找回');
    }

    public function test_仅手机通道开启时显示手机表单且无tab(): void
    {
        $this->enablePhoneChannel();

        $this->get('/sso/forgot-password')
            ->assertOk()
            ->assertSee('请输入注册手机号')
            ->assertDontSee('请输入注册邮箱')
            ->assertDontSee('邮箱找回');
    }

    public function test_邮箱手机均开启时以tab形式显示(): void
    {
        $this->enableEmailChannel();
        $this->enablePhoneChannel();

        $this->get('/sso/forgot-password')
            ->assertOk()
            ->assertSee('邮箱找回')
            ->assertSee('手机找回')
            ->assertSee('请输入注册邮箱')
            ->assertSee('请输入注册手机号');
    }

    public function test_登录页默认显示忘记密码入口(): void
    {
        // 入口不做通道显隐判断，默认显示（找回密码页内再做通道可用性提示）
        $this->get('/sso/login')
            ->assertOk()
            ->assertSee('忘记密码')
            ->assertSee('forgot-password');
    }

    // ---------------------------------------------------------------- 2. 手机验证码发送

    public function test_未注册手机号发送找回验证码被拒(): void
    {
        $this->enablePhoneChannel();

        $this->postJson('/api/cmspro/sso/auth/forgot/phone/code', ['phone' => '13800138000'])
            ->assertStatus(422)
            ->assertJsonPath('message', '该手机号未注册');
    }

    public function test_已注册手机号成功发送找回验证码(): void
    {
        $this->enablePhoneChannel();
        $this->createUser(['phone' => '13800138000']);

        $this->postJson('/api/cmspro/sso/auth/forgot/phone/code', ['phone' => '13800138000'])
            ->assertOk()
            ->assertJsonPath('code', 0);

        // 验证码已写入独立缓存键（与登录验证码区分）
        $this->assertTrue(Cache::has('cmspro_sso_pwd_reset_phone:13800138000'));
    }

    public function test_手机通道关闭时发送找回验证码被拒(): void
    {
        // 未开启 phone_login_enabled
        $this->postJson('/api/cmspro/sso/auth/forgot/phone/code', ['phone' => '13800138000'])
            ->assertStatus(400)
            ->assertJsonPath('message', '系统未开启手机找回密码功能');
    }

    // ---------------------------------------------------------------- 3. 手机重置密码

    public function test_验证码错误时重置失败(): void
    {
        $this->enablePhoneChannel();
        $this->createUser(['phone' => '13800138000']);
        Cache::put('cmspro_sso_pwd_reset_phone:13800138000', '123456', now()->addMinutes(10));

        $this->postJson('/api/cmspro/sso/auth/forgot/phone/reset', [
            'phone'                 => '13800138000',
            'code'                  => '000000',
            'password'              => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertStatus(422)
            ->assertJsonPath('message', '验证码无效或已过期');
    }

    public function test_验证码正确时重置成功且验证码一次性消费(): void
    {
        $this->enablePhoneChannel();
        $this->createUser(['phone' => '13800138000', 'password' => 'oldpass123']);
        Cache::put('cmspro_sso_pwd_reset_phone:13800138000', '123456', now()->addMinutes(10));

        $this->postJson('/api/cmspro/sso/auth/forgot/phone/reset', [
            'phone'                 => '13800138000',
            'code'                  => '123456',
            'password'              => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertOk()
            ->assertJsonPath('code', 0);

        $user = User::where('phone', '13800138000')->first();
        $this->assertTrue(Hash::check('newpass123', $user->password), '密码应已更新为新密码');

        // 一次性消费：同一验证码二次提交应失败
        $this->postJson('/api/cmspro/sso/auth/forgot/phone/reset', [
            'phone'                 => '13800138000',
            'code'                  => '123456',
            'password'              => 'newpass456',
            'password_confirmation' => 'newpass456',
        ])->assertStatus(422)
            ->assertJsonPath('message', '验证码无效或已过期');
    }

    public function test_两次密码输入不一致时重置被拒(): void
    {
        $this->enablePhoneChannel();
        Cache::put('cmspro_sso_pwd_reset_phone:13800138000', '123456', now()->addMinutes(10));

        $this->postJson('/api/cmspro/sso/auth/forgot/phone/reset', [
            'phone'                 => '13800138000',
            'code'                  => '123456',
            'password'              => 'newpass123',
            'password_confirmation' => 'other456',
        ])->assertStatus(422)
            ->assertJsonPath('message', '两次密码输入不一致');
    }

    public function test_禁用账号重置密码被拒(): void
    {
        $this->enablePhoneChannel();
        $this->createUser(['phone' => '13800138000', 'status' => 0]);
        Cache::put('cmspro_sso_pwd_reset_phone:13800138000', '123456', now()->addMinutes(10));

        $this->postJson('/api/cmspro/sso/auth/forgot/phone/reset', [
            'phone'                 => '13800138000',
            'code'                  => '123456',
            'password'              => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertStatus(422)
            ->assertJsonPath('message', '账号不存在或已被禁用');
    }

    // ---------------------------------------------------------------- 辅助

    protected function enableEmailChannel(): void
    {
        $this->setConfig('email_login_enabled', '1');
        // mail_configured() 依据 SMTP 用户名判断
        config(['mail.mailers.smtp.username' => 'smtp_tester']);
    }

    protected function enablePhoneChannel(): void
    {
        $this->setConfig('phone_login_enabled', '1');
        // 短信服务配置检查通过 stub 控制，避免依赖真实服务商配置
        $stub = new class($this->app->make(\App\Apps\CmsproSso\Services\SettingService::class)) extends SmsService {
            public function isConfigured(): bool
            {
                return true;
            }

            public function sendVerificationCode(string $phone, string $code): array
            {
                return ['success' => true, 'message' => 'ok'];
            }
        };
        $this->app->instance(SmsService::class, $stub);
    }

    protected function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'forgot_user_' . uniqid(),
            'password' => Hash::make('secret123'),
            'nickname' => '找回测试用户',
            'status'   => 1,
        ], $overrides));
    }

    protected function setConfig(string $name, string $value): void
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

    protected function createTables(): void
    {
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
