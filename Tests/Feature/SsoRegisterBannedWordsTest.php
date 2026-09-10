<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproSso\Services\SettingService;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 注册用户名屏蔽词测试
 *
 * 覆盖后台「用户名屏蔽词」配置的注册拦截逻辑：
 *   屏蔽词解析（英文/中文逗号、分号、换行分隔）；
 *   注册用户名包含任一屏蔽词（大小写不敏感）→ 拒绝注册；
 *   未配置屏蔽词或用户名不包含屏蔽词 → 正常注册。
 */
class SsoRegisterBannedWordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
    }

    // ---------------------------------------------------------------- 1. 屏蔽词解析

    public function test_屏蔽词支持逗号分号换行多分隔符解析(): void
    {
        $service = new SettingService();
        $this->setConfig('username_banned_words', "admin，root\n管理; 测试,  运营  ");

        $this->assertSame(['admin', 'root', '管理', '测试', '运营'], $service->getBannedWords());
    }

    public function test_屏蔽词解析忽略空白项且大小写归一(): void
    {
        $service = new SettingService();
        $this->setConfig('username_banned_words', ",,Admin,,;;\n,,ROOT,,");

        $this->assertSame(['admin', 'root'], $service->getBannedWords());
    }

    public function test_未配置屏蔽词时解析为空数组(): void
    {
        $service = new SettingService();

        $this->assertSame([], $service->getBannedWords());
    }

    // ---------------------------------------------------------------- 2. 注册拦截

    public function test_用户名包含屏蔽词时拒绝注册(): void
    {
        $this->setConfig('username_banned_words', 'admin,管理员');

        $this->postJson('/api/cmspro/sso/auth/register', [
            'username' => 'hello_admin',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)
            ->assertJsonPath('message', '用户名包含被屏蔽的词语，请更换用户名');

        $this->assertDatabaseMissing('users', ['username' => 'hello_admin']);
    }

    public function test_屏蔽词匹配不区分大小写(): void
    {
        $this->setConfig('username_banned_words', 'Admin');

        $this->postJson('/api/cmspro/sso/auth/register', [
            'username' => 'xxADMINxx',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['username' => 'xxADMINxx']);
    }

    public function test_用户名不包含屏蔽词时正常注册(): void
    {
        $this->setConfig('username_banned_words', 'admin,管理员');

        $this->postJson('/api/cmspro/sso/auth/register', [
            'username' => 'gooduser01',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertDatabaseHas('users', ['username' => 'gooduser01']);
    }

    public function test_未配置屏蔽词时注册不受影响(): void
    {
        $this->postJson('/api/cmspro/sso/auth/register', [
            'username' => 'freeregister',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertDatabaseHas('users', ['username' => 'freeregister']);
    }

    public function test_设置页渲染用户名屏蔽词表单项(): void
    {
        $admin = AdminUser::create([
            'username' => 'sso_admin_' . uniqid(),
            'password' => 'password_hash_placeholder',
            'name'     => 'SSO设置测试管理员',
            'status'   => 1,
        ]);
        $this->setConfig('username_banned_words', 'admin,管理员');
        $this->actingAs($admin, 'admin');

        $this->get('/admin/cmspro/sso/setting')
            ->assertOk()
            ->assertSee('用户名屏蔽词')
            ->assertSee('name="username_banned_words"', false);
    }

    // ---------------------------------------------------------------- 辅助

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
