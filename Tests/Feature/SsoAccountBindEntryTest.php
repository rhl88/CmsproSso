<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproSso\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 前台账号页绑定入口集成测试
 *
 * 覆盖：本应用按 CmsproNotepad 覆盖 dashboard 的应用注入模式，
 * 由应用覆盖系统同名路由 `user.account`（/user/account），在保留系统
 * 个人信息管理的同时渲染「账号绑定」入口并由共享绑定中心组件承接点击。
 * 不修改任何框架文件。
 */
class SsoAccountBindEntryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createUsersTable();
        $this->app->register(ServiceProvider::class);
    }

    public function test_应用覆盖前台账号页并注入账号绑定入口(): void
    {
        $this->actingAs($this->createUser(), 'web');

        // 应用路由后注册覆盖系统同名 user.account → 渲染应用视图（含账号绑定）
        $this->get('/user/account')
            ->assertOk()
            // 覆盖后页面仍保留个人信息管理
            ->assertSee('个人信息')
            // 新增账号绑定入口（.sso-bind-center-open 点击由共享绑定中心组件承接）
            ->assertSee('账号绑定')
            ->assertSee('sso-bind-center-open')
            // 页面同步引入共享绑定中心组件（弹层 DOM）
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