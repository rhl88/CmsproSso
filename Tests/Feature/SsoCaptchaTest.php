<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproCaptcha\Storages\SessionStorage;
use App\Enums\AppStatus;
use App\Models\AdminUser;
use App\Models\AppModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 人机验证码（依赖「验证码」应用）测试
 *
 * 覆盖：
 *   1. 后台设置保存：开关依赖检测（验证码应用未安装/未启用拦截）、启用位置必选、合法保存落库
 *   2. 页面渲染：开启后登录/注册页渲染验证码容器与组件脚本，关闭或依赖未就绪不渲染
 *   3. 登录/注册 API：未完成验证拦截、错误答案拦截、正确答案放行、场景隔离（login/register 互不牵连）
 */
class SsoCaptchaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. 后台设置保存

    public function test_开启验证码但应用未安装时拒绝保存(): void
    {
        $this->actingAs($this->createAdmin(), 'admin')
            ->putJson('/admin/cmspro/sso/api/settings', [
                'captcha_enabled' => '1',
                'captcha_scenes'  => 'login',
            ])
            ->assertStatus(400)
            ->assertJsonPath('code', 400)
            ->assertJsonPath('message', fn ($message) => str_contains($message, '未安装'));
    }

    public function test_验证码应用已安装未启用时拒绝保存(): void
    {
        $this->createCaptchaApp(AppStatus::DISABLED);

        $this->actingAs($this->createAdmin(), 'admin')
            ->putJson('/admin/cmspro/sso/api/settings', [
                'captcha_enabled' => '1',
                'captcha_scenes'  => 'login',
            ])
            ->assertStatus(400)
            ->assertJsonPath('message', fn ($message) => str_contains($message, '未启用'));
    }

    public function test_开启验证码但未选启用位置时拒绝保存(): void
    {
        $this->createCaptchaApp(AppStatus::ENABLED);

        $this->actingAs($this->createAdmin(), 'admin')
            ->putJson('/admin/cmspro/sso/api/settings', [
                'captcha_enabled' => '1',
                'captcha_scenes'  => '',
            ])
            ->assertStatus(400)
            ->assertJsonPath('message', fn ($message) => str_contains($message, '启用位置'));
    }

    public function test_合法验证码配置保存落库(): void
    {
        $this->createCaptchaApp(AppStatus::ENABLED);

        $this->actingAs($this->createAdmin(), 'admin')
            ->putJson('/admin/cmspro/sso/api/settings', [
                'captcha_enabled' => '1',
                'captcha_scenes'  => 'login,register',
                'captcha_driver'  => 'slider',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertDatabaseHas('config_items', ['code' => 'app_cmspro_sso_captcha_enabled', 'value' => '1']);
        $this->assertDatabaseHas('config_items', ['code' => 'app_cmspro_sso_captcha_scenes', 'value' => 'login,register']);
        $this->assertDatabaseHas('config_items', ['code' => 'app_cmspro_sso_captcha_driver', 'value' => 'slider']);
    }

    public function test_关闭验证码时无需依赖检测(): void
    {
        // 无验证码应用记录，关闭状态下保存其余配置不受影响
        $this->actingAs($this->createAdmin(), 'admin')
            ->putJson('/admin/cmspro/sso/api/settings', [
                'captcha_enabled' => '0',
                'captcha_scenes'  => 'login',
                'captcha_driver'  => 'image',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    // ---------------------------------------------------------------- 2. 页面渲染

    public function test_默认关闭时登录页注册页不渲染验证码(): void
    {
        $this->get('/sso/login')
            ->assertOk()
            ->assertDontSee('id="captcha-box"', false)
            ->assertDontSee('captcha-widget.js', false);

        $this->get('/sso/register')
            ->assertOk()
            ->assertDontSee('id="captcha-box"', false)
            ->assertDontSee('captcha-widget.js', false);
    }

    public function test_开启登录验证码后登录页渲染且注册页不渲染(): void
    {
        $this->createCaptchaApp(AppStatus::ENABLED);
        $this->setConfig('captcha_enabled', '1');
        $this->setConfig('captcha_scenes', 'login');

        $this->get('/sso/login')
            ->assertOk()
            ->assertSee('id="captcha-box"', false)
            ->assertSee('captcha-widget.js', false);

        $this->get('/sso/register')
            ->assertOk()
            ->assertDontSee('id="captcha-box"', false)
            ->assertDontSee('captcha-widget.js', false);
    }

    public function test_开启注册验证码后注册页渲染(): void
    {
        $this->createCaptchaApp(AppStatus::ENABLED);
        $this->setConfig('captcha_enabled', '1');
        $this->setConfig('captcha_scenes', 'register');

        $this->get('/sso/register')
            ->assertOk()
            ->assertSee('id="captcha-box"', false)
            ->assertSee('captcha-widget.js', false);
    }

    public function test_验证码应用未就绪时开关不生效(): void
    {
        // 开关开启、位置含 login，但未安装验证码应用 → 页面不渲染验证码
        $this->setConfig('captcha_enabled', '1');
        $this->setConfig('captcha_scenes', 'login');

        $this->get('/sso/login')
            ->assertOk()
            ->assertDontSee('id="captcha-box"', false);
    }

    // ---------------------------------------------------------------- 3. 登录 API 验证码校验

    public function test_未开启验证码时登录不受影响(): void
    {
        $this->createUser('captchauser', 'secret123');

        $this->postJson('/api/cmspro/sso/auth/login', [
            'account'  => 'captchauser',
            'password' => 'secret123',
        ])->assertOk()->assertJsonPath('code', 0);
    }

    public function test_开启后缺少验证码字段拒绝登录(): void
    {
        $this->enableLoginCaptcha();
        $this->createUser('captchauser', 'secret123');

        $this->postJson('/api/cmspro/sso/auth/login', [
            'account'  => 'captchauser',
            'password' => 'secret123',
        ])->assertStatus(422)
            ->assertJsonPath('message', '请先完成验证码验证');

        $this->assertGuest('web');
    }

    public function test_开启后验证码答案错误拒绝登录(): void
    {
        $this->enableLoginCaptcha();
        $this->seedCaptcha('abcd');
        $this->createUser('captchauser', 'secret123');

        $this->postJson('/api/cmspro/sso/auth/login', [
            'account'        => 'captchauser',
            'password'       => 'secret123',
            'captcha_key'    => 'captcha_test_key',
            'captcha_answer' => 'xxxx',
        ])->assertStatus(422)
            ->assertJsonPath('message', '验证码错误或已过期，请重新验证');

        $this->assertGuest('web');
    }

    public function test_开启后验证码正确允许登录(): void
    {
        $this->enableLoginCaptcha();
        $this->seedCaptcha('abcd');
        $this->createUser('captchauser', 'secret123');

        $this->postJson('/api/cmspro/sso/auth/login', [
            'account'        => 'captchauser',
            'password'       => 'secret123',
            'captcha_key'    => 'captcha_test_key',
            'captcha_answer' => 'abcd',
        ])->assertOk()->assertJsonPath('code', 0);

        $this->assertAuthenticated('web');
    }

    public function test_仅开启注册场景时登录无需验证码(): void
    {
        $this->createCaptchaApp(AppStatus::ENABLED);
        $this->setConfig('captcha_enabled', '1');
        $this->setConfig('captcha_scenes', 'register');
        $this->createUser('captchauser', 'secret123');

        $this->postJson('/api/cmspro/sso/auth/login', [
            'account'  => 'captchauser',
            'password' => 'secret123',
        ])->assertOk()->assertJsonPath('code', 0);
    }

    // ---------------------------------------------------------------- 4. 注册 API 验证码校验

    public function test_开启后缺少验证码拒绝注册(): void
    {
        $this->enableRegisterCaptcha();

        $this->postJson('/api/cmspro/sso/auth/register', [
            'username'              => 'captchareg01',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)
            ->assertJsonPath('message', '请先完成验证码验证');

        $this->assertDatabaseMissing('users', ['username' => 'captchareg01']);
    }

    public function test_开启后验证码答案错误拒绝注册(): void
    {
        $this->enableRegisterCaptcha();
        $this->seedCaptcha('abcd');

        $this->postJson('/api/cmspro/sso/auth/register', [
            'username'              => 'captchareg02',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
            'captcha_key'           => 'captcha_test_key',
            'captcha_answer'        => 'xxxx',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['username' => 'captchareg02']);
    }

    public function test_开启后验证码正确注册成功(): void
    {
        $this->enableRegisterCaptcha();
        $this->seedCaptcha('abcd');

        $this->postJson('/api/cmspro/sso/auth/register', [
            'username'              => 'captchareg03',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
            'captcha_key'           => 'captcha_test_key',
            'captcha_answer'        => 'abcd',
        ])->assertOk()->assertJsonPath('code', 0);

        $this->assertDatabaseHas('users', ['username' => 'captchareg03']);
    }

    public function test_仅开启登录场景时注册无需验证码(): void
    {
        $this->createCaptchaApp(AppStatus::ENABLED);
        $this->setConfig('captcha_enabled', '1');
        $this->setConfig('captcha_scenes', 'login');

        $this->postJson('/api/cmspro/sso/auth/register', [
            'username'              => 'captchareg04',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertOk()->assertJsonPath('code', 0);

        $this->assertDatabaseHas('users', ['username' => 'captchareg04']);
    }

    // ---------------------------------------------------------------- 辅助

    /**
     * 向会话写入一条已知答案的图片验证码（模拟用户完成人机验证）
     */
    protected function seedCaptcha(string $answer = 'abcd', string $key = 'captcha_test_key'): void
    {
        (new SessionStorage())->store($key, [
            'answer'     => $answer,
            'type'       => 'image',
            'expires_at' => time() + 300,
            'created_at' => time(),
            'attempts'   => 0,
        ], 300);
    }

    protected function enableLoginCaptcha(): void
    {
        $this->createCaptchaApp(AppStatus::ENABLED);
        $this->setConfig('captcha_enabled', '1');
        $this->setConfig('captcha_scenes', 'login');
    }

    protected function enableRegisterCaptcha(): void
    {
        $this->createCaptchaApp(AppStatus::ENABLED);
        $this->setConfig('captcha_enabled', '1');
        $this->setConfig('captcha_scenes', 'register');
    }

    protected function createCaptchaApp(AppStatus $status): void
    {
        AppModel::create([
            'app_id'  => 'cmspro.captcha',
            'name'    => '验证码',
            'version' => '1.0.0',
            'path'    => 'app/Apps/CmsproCaptcha',
            'status'  => $status,
        ]);
    }

    protected function createUser(string $username, string $password): User
    {
        return User::create([
            'username' => $username,
            'password' => Hash::make($password),
            'nickname' => $username,
            'status'   => 1,
        ]);
    }

    protected function createAdmin(): AdminUser
    {
        return AdminUser::create([
            'username' => 'sso_admin_' . uniqid(),
            'password' => 'password_hash_placeholder',
            'name'     => 'SSO验证码测试管理员',
            'status'   => 1,
        ]);
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
        if (!Schema::hasTable('apps')) {
            Schema::create('apps', function ($table) {
                $table->bigIncrements('id');
                $table->string('app_id', 50)->unique();
                $table->string('name', 100)->nullable();
                $table->string('description', 255)->nullable();
                $table->string('version', 20)->nullable();
                $table->string('author', 50)->nullable();
                $table->string('forum_url', 255)->nullable();
                $table->string('icon', 255)->nullable();
                $table->string('path', 255)->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->boolean('is_system')->default(false);
                $table->json('manifest')->nullable();
                $table->timestamp('install_time')->nullable();
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
            });
        }

        if (!Schema::hasTable('admin_users')) {
            Schema::create('admin_users', function ($table) {
                $table->bigIncrements('id');
                $table->string('username', 50)->unique();
                $table->string('password');
                $table->string('name', 50)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('phone', 20)->nullable();
                $table->string('avatar', 500)->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->timestamp('last_login_at')->nullable();
                $table->string('last_login_ip', 45)->nullable();
                $table->unsignedInteger('login_count')->default(0);
                $table->timestamp('password_changed_at')->nullable();
                $table->string('remember_token', 100)->nullable();
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
