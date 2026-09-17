<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 邮箱验证码登录测试
 *
 * 覆盖：
 *   1. 未启用邮箱验证码登录时，发送验证码/邮箱登录均被拒绝；
 *   2. 系统未配置 SMTP 时，即使开关开启也不可用；
 *   3. 启用后，发送验证码成功（通过 Mail::fake 拦截邮件发送）；
 *   4. 邮箱不存在时，登录自动创建用户并返回 is_new=true；
 *   5. 邮箱已存在时，直接登录并返回 is_new=false；
 *   6. 验证码一次性失效（使用后无法重复登录）。
 */
class SsoEmailLoginTest extends TestCase
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

        // 启用邮箱验证码登录并配置 SMTP（模拟系统邮件已配置）
        config(['apps.cmspro.sso.email_login_enabled' => '1']);
        config(['mail.default' => 'smtp']);
        config(['mail.mailers.smtp.username' => 'noreply@example.com']);
        config(['mail.mailers.smtp.password' => 'secret']);
        config(['mail.from.address' => 'noreply@example.com']);
        config(['mail.from.name' => '用户中心']);

        Mail::fake();
    }

    public function test_未启用时发送验证码被拒(): void
    {
        config(['apps.cmspro.sso.email_login_enabled' => '0']);

        $this->postJson('/api/cmspro/sso/auth/email/code', ['email' => 'user@example.com'])
            ->assertStatus(400)
            ->assertJsonPath('code', 400);
    }

    public function test_未启用时邮箱登录被拒(): void
    {
        config(['apps.cmspro.sso.email_login_enabled' => '0']);

        $this->postJson('/api/cmspro/sso/auth/email/login', ['email' => 'user@example.com', 'code' => '123456'])
            ->assertStatus(400)
            ->assertJsonPath('code', 400);
    }

    public function test_系统未配置SMTP时开关开启也不可用(): void
    {
        // mail_configured() 以 SMTP username 是否填写为标准
        config(['mail.mailers.smtp.username' => '']);

        $this->postJson('/api/cmspro/sso/auth/email/code', ['email' => 'user@example.com'])
            ->assertStatus(400)
            ->assertJsonPath('code', 400);
    }

    public function test_发送验证码成功并写入缓存(): void
    {
        $this->postJson('/api/cmspro/sso/auth/email/code', ['email' => 'User@Example.com'])
            ->assertOk()
            ->assertJsonPath('code', 0);

        // 邮箱统一转小写，验证码写入与系统注册共用的缓存键（Mail::fake 已拦截真实投递）
        $this->assertTrue(Cache::has('user_register_code:user@example.com'));
    }

    public function test_邮箱验证码发送频率限制(): void
    {
        // 首次发送正常
        $this->postJson('/api/cmspro/sso/auth/email/code', ['email' => 'user@example.com'])
            ->assertOk();

        // 触发每分钟 3 次限流（同一邮箱）
        $this->postJson('/api/cmspro/sso/auth/email/code', ['email' => 'user@example.com'])
            ->assertOk();
        $this->postJson('/api/cmspro/sso/auth/email/code', ['email' => 'user@example.com'])
            ->assertOk();

        // 第 4 次被限流
        $this->postJson('/api/cmspro/sso/auth/email/code', ['email' => 'user@example.com'])
            ->assertStatus(429)
            ->assertJsonPath('code', 429);
    }

    public function test_邮箱格式不正确时拒绝发送(): void
    {
        $this->postJson('/api/cmspro/sso/auth/email/code', ['email' => 'not-an-email'])
            ->assertStatus(422);
    }

    public function test_验证码正确且邮箱已存在时自动登录(): void
    {
        // 预置已存在邮箱的用户
        User::create([
            'username' => 'existing_email_user',
            'password' => bcrypt('123456'),
            'nickname' => '既有邮箱用户',
            'email'    => 'exist@example.com',
            'group_id' => UserGroup::where('code', 'default')->value('id'),
            'status'   => 1,
        ]);

        $this->obtainCode('exist@example.com', '654321');

        $this->postJson('/api/cmspro/sso/auth/email/login', ['email' => 'exist@example.com', 'code' => '654321'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.is_new', false);

        // 不创建新用户
        $this->assertDatabaseMissing('users', ['username' => 'exist']);
    }

    public function test_邮箱不存在时自动创建用户并提示完善(): void
    {
        $email = 'new@example.com';
        $this->obtainCode($email, '111111');

        $this->postJson('/api/cmspro/sso/auth/email/login', ['email' => $email, 'code' => '111111'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.is_new', true);

        // 自动创建邮箱用户（用户名取 @ 前缀）
        $this->assertDatabaseHas('users', ['email' => $email, 'username' => 'new']);
    }

    public function test_验证码使用后一次性失效(): void
    {
        $email = 'once@example.com';

        $this->obtainCode($email, '222222');

        $this->postJson('/api/cmspro/sso/auth/email/login', ['email' => $email, 'code' => '222222'])
            ->assertOk();

        // 同一验证码第二次登录失败（已一次性失效）
        $this->postJson('/api/cmspro/sso/auth/email/login', ['email' => $email, 'code' => '222222'])
            ->assertJsonPath('code', 422);
    }

    public function test_验证码错误时登录失败(): void
    {
        $email = 'wrong@example.com';
        $this->obtainCode($email, '333333');

        $this->postJson('/api/cmspro/sso/auth/email/login', ['email' => $email, 'code' => '999999'])
            ->assertStatus(422)
            ->assertJsonPath('code', 422);
    }

    public function test_登录成功后跳转到redirect参数指定地址(): void
    {
        User::create([
            'username' => 'email_redirect_user',
            'password' => bcrypt('123456'),
            'nickname' => '邮箱跳转用户',
            'email'    => 'redirect@example.com',
            'group_id' => UserGroup::where('code', 'default')->value('id'),
            'status'   => 1,
        ]);

        $this->obtainCode('redirect@example.com', '444444');

        $this->postJson('/api/cmspro/sso/auth/email/login', [
            'email'    => 'redirect@example.com',
            'code'     => '444444',
            'redirect' => '/forum',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.redirect', '/forum');
    }

    /**
     * 预置邮箱验证码到缓存（绕过真实邮件发送，与系统注册验证码共用缓存键）
     */
    protected function obtainCode(string $email, string $code): void
    {
        Cache::put('user_register_code:' . $email, $code, now()->addMinutes(10));
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
