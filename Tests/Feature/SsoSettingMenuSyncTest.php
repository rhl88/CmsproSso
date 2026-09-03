<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Models\AdminMenu;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 站点角色保存与后台菜单联动测试
 *
 * 覆盖：
 *   1. 站点角色（quick_mode）配置保存落库（此前因白名单缺失导致保存丢失）
 *   2. 保存后同步「客户端管理」菜单可见性（仅服务端模式可见）
 *   3. 站点角色取值校验与未登录访问拦截
 */
class SsoSettingMenuSyncTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->createClientsMenu();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);
        $this->admin = AdminUser::create([
            'username' => 'sso_admin_' . uniqid(),
            'password' => 'password_hash_placeholder',
            'name'     => 'SSO测试管理员',
            'status'   => 1,
        ]);
    }

    // ---------------------------------------------------------------- 1. 配置保存

    public function test_保存站点角色为服务端并启用客户端管理菜单(): void
    {
        // 初始为禁用状态（独立站默认不可见）
        $this->assertMenuStatus(0);

        $this->actingAs($this->admin, 'admin')
            ->putJson('/admin/cmspro/sso/api/settings', ['quick_mode' => 'server'])
            ->assertOk()
            ->assertJson(['code' => 0]);

        // 配置落库
        $this->assertDatabaseHas('config_items', [
            'code'  => 'app_cmspro_sso_quick_mode',
            'value' => 'server',
        ]);

        // 客户端管理菜单启用
        $this->assertMenuStatus(1);
    }

    public function test_保存站点角色为独立站或客户端时客户端管理菜单不可见(): void
    {
        // standalone：直接保存；client：附带服务端地址（客户端模式必填）
        $payloads = [
            'standalone' => ['quick_mode' => 'standalone'],
            'client'     => ['quick_mode' => 'client', 'relay_server_url' => 'https://sso.example.com'],
        ];

        foreach ($payloads as $mode => $payload) {
            $this->actingAs($this->admin, 'admin')
                ->putJson('/admin/cmspro/sso/api/settings', $payload)
                ->assertOk()
                ->assertJson(['code' => 0]);

            $this->assertDatabaseHas('config_items', [
                'code'  => 'app_cmspro_sso_quick_mode',
                'value' => $mode,
            ]);
            $this->assertMenuStatus(0, "站点角色 {$mode} 时客户端管理菜单应不可见");
        }
    }

    public function test_快捷模式客户端字段随表单保存(): void
    {
        $payload = [
            'quick_mode'          => 'client',
            'relay_server_url'    => 'https://sso.example.com',
            'relay_client_id'     => 'sso_test_client',
            'relay_client_secret' => 'sso_test_secret',
        ];

        $this->actingAs($this->admin, 'admin')
            ->putJson('/admin/cmspro/sso/api/settings', $payload)
            ->assertOk()
            ->assertJson(['code' => 0]);

        foreach ($payload as $key => $value) {
            $this->assertDatabaseHas('config_items', [
                'code'  => 'app_cmspro_sso_' . $key,
                'value' => $value,
            ]);
        }
    }

    // ---------------------------------------------------------------- 2. 校验与拦截

    public function test_安装后按站点角色同步客户端管理菜单默认隐藏(): void
    {
        // 模拟安装：菜单由框架注册为启用，postInstall 按站点角色（默认独立站）同步为隐藏
        AdminMenu::where('code', 'admin_cmspro_sso_clients')->update(['status' => 1]);
        $install = new \App\Apps\CmsproSso\Install();
        $install->postInstall();
        $this->assertMenuStatus(0, '默认独立站安装后客户端管理菜单应隐藏');

        // 重新安装场景：站点角色已配置为服务端时保持可见
        $this->setConfig('quick_mode', 'server');
        $install->postInstall();
        $this->assertMenuStatus(1, '站点角色为服务端时重装后菜单应保持可见');
    }

    public function test_非法站点角色返回400(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->putJson('/admin/cmspro/sso/api/settings', ['quick_mode' => 'invalid_mode'])
            ->assertStatus(400)
            ->assertJson(['code' => 400]);

        // 不落库、菜单不受影响
        $this->assertDatabaseMissing('config_items', ['code' => 'app_cmspro_sso_quick_mode']);
        $this->assertMenuStatus(0);
    }

    public function test_客户端模式必须填写服务端地址(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->putJson('/admin/cmspro/sso/api/settings', ['quick_mode' => 'client'])
            ->assertStatus(400)
            ->assertJson(['code' => 400]);
    }

    public function test_未登录访问设置接口被拦截(): void
    {
        $this->putJson('/admin/cmspro/sso/api/settings', ['quick_mode' => 'server'])
            ->assertStatus(401);
    }

    // ---------------------------------------------------------------- 辅助方法

    protected function assertMenuStatus(int $expected, string $message = ''): void
    {
        $menu = AdminMenu::where('code', 'admin_cmspro_sso_clients')->first();
        $this->assertNotNull($menu, '客户端管理菜单记录应存在');
        $this->assertSame($expected, $menu->status->value, $message);
    }

    /**
     * 写入配置项
     */
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

    /**
     * 创建「客户端管理」菜单记录（默认禁用，模拟独立站初始状态）
     */
    protected function createClientsMenu(): void
    {
        AdminMenu::create([
            'parent_id'     => 0,
            'name'          => '客户端管理',
            'icon'          => 'fa fa-plug',
            'path'          => '/admin/cmspro/sso/clients',
            'code'          => 'admin_cmspro_sso_clients',
            'type'          => 2,
            'visible'       => 1,
            'sort'          => 3,
            'status'        => 0,
            'terminal_type' => 'admin',
            'app_id'        => 'cmspro.sso',
        ]);
    }

    /**
     * 构建测试所需的最小表结构（配置表 + 管理员表 + 后台菜单表）
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

        if (!Schema::hasTable('admin_menus')) {
            Schema::create('admin_menus', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('parent_id')->default(0);
                $table->string('name', 50);
                $table->string('icon', 100)->nullable();
                $table->string('path', 200)->nullable();
                $table->string('code', 100)->nullable();
                $table->string('component', 200)->nullable();
                $table->string('permission_code', 100)->nullable();
                $table->unsignedTinyInteger('type')->default(2);
                $table->unsignedTinyInteger('visible')->default(1);
                $table->unsignedInteger('sort')->default(0);
                $table->unsignedTinyInteger('status')->default(1);
                $table->string('terminal_type', 20)->default('admin');
                $table->string('app_id', 50)->nullable();
                $table->timestamp('create_time')->nullable();
                $table->timestamp('update_time')->nullable();
            });
        }
    }
}
