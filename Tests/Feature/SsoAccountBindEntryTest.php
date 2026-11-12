<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproSso\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 前台账号页绑定 Tab 集成测试
 *
 * 覆盖：本应用通过 View composer 读取系统框架账号页视图 user.account.index，
 * 经其扩展位（@stack('user_account_tab_titles') / @stack('user_account_tab_contents')
 * / @stack('user_account_scripts')）注入「账号绑定」Tab 与共享绑定中心组件；
 * 不覆盖框架路由与视图，框架账号页后续更新可正常透传。
 */
class SsoAccountBindEntryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createUsersTable();
        $this->app->register(ServiceProvider::class);
    }

    public function test_应用读取框架账号页并注入账号绑定Tab(): void
    {
        $this->actingAs($this->createUser(), 'web');

        // 框架路由渲染系统账号页，应用经扩展位注入「账号绑定」Tab
        $this->get('/user/account')
            ->assertOk()
            // 账号页以 Tab 组织，且默认选中「个人信息」
            ->assertSee('layui-tab-title', false)
            ->assertSee('<li class="layui-this">个人信息</li>', false)
            // 应用注入的 Tab 标题与面板（面板内为绑定中心内嵌容器）
            ->assertSee('<li>账号绑定</li>', false)
            ->assertSee('sso-bind-center-inline', false)
            // 注入的共享绑定中心组件（弹层 DOM，内嵌模式下由脚本移入 Tab 面板）
            ->assertSee('id="sso-bind-center-modal"', false);
    }

    protected function createUser(): User
    {
        return User::create([
            'username' => 'tester',
            'name'     => 'Tester',
            'email'    => 'tester@example.com',
            'password' => Hash::make('password'),
        ]);
    }

    protected function createUsersTable(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function ($table) {
            $table->bigIncrements('id');
            $table->string('username', 50)->default('');
            $table->string('name', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('password', 255)->default('');
            $table->string('nickname', 50)->nullable();
            $table->string('avatar', 255)->nullable();
            $table->unsignedTinyInteger('gender')->default(0);
            $table->string('signature', 255)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('create_time')->nullable();
            $table->timestamp('update_time')->nullable();
        });
    }
}