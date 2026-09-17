<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use App\Apps\CmsproSso\Models\InviteCode;
use App\Enums\AppStatus;
use App\Models\AdminUser;
use App\Models\AppModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 统一登录应用 · 邀请码管理测试
 *
 * 覆盖：
 *   1. 列表返回 items + pagination 分页结构，支持关键词/状态筛选；
 *   2. 批量生成邀请码（数量与费用快照、邀请码唯一）；
 *   3. 生成参数校验（数量范围、费用格式）；
 *   4. 未使用邀请码可禁用/启用、可删除；
 *   5. 已使用邀请码不可修改状态、不可删除（保留追溯）。
 */
class SsoInviteCodeTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->app->register(\App\Apps\CmsproSso\ServiceProvider::class);

        $this->admin = AdminUser::create([
            'username' => 'sso_invite_' . uniqid(),
            'password' => bcrypt('123456'),
            'status'   => 1,
        ]);
    }

    public function test_列表返回分页结构(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->createCode();
        }

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/admin/cmspro/sso/api/invites?page=1&limit=15');

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.pagination.total', 25);

        $this->assertCount(15, $response->json('data.items'));
    }

    public function test_列表支持关键词与状态筛选(): void
    {
        $this->createCode(['code' => 'ABC23456789ABCDEFG']);
        $this->createCode(['code' => 'XYZ23456789ABCDEFG']);
        $this->createCode(['code' => 'ABC23456789XYZUVWX', 'status' => InviteCode::STATUS_USED]);

        // 关键词筛选
        $this->actingAs($this->admin, 'admin')
            ->getJson('/admin/cmspro/sso/api/invites?keyword=XYZ23456789ABCDEFG')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        // 状态筛选：已使用
        $this->actingAs($this->admin, 'admin')
            ->getJson('/admin/cmspro/sso/api/invites?status=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_批量生成邀请码(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/admin/cmspro/sso/api/invites/generate', ['count' => 5, 'price' => '9.90']);

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.count', 5);

        $codes = InviteCode::all();
        $this->assertCount(5, $codes);
        $this->assertCount(5, $codes->pluck('code')->unique()->values(), '邀请码应唯一');
        $codes->each(function (InviteCode $code) {
            $this->assertSame(InviteCode::STATUS_UNUSED, (int) $code->status);
            $this->assertSame(9.9, (float) $code->price);
        });
    }

    public function test_生成参数校验(): void
    {
        // 数量非法
        $this->actingAs($this->admin, 'admin')
            ->postJson('/admin/cmspro/sso/api/invites/generate', ['count' => 0])
            ->assertStatus(400);
        $this->actingAs($this->admin, 'admin')
            ->postJson('/admin/cmspro/sso/api/invites/generate', ['count' => 501])
            ->assertStatus(400);

        // 费用非法
        $this->actingAs($this->admin, 'admin')
            ->postJson('/admin/cmspro/sso/api/invites/generate', ['count' => 1, 'price' => 'abc'])
            ->assertStatus(400);

        // 以上均不应生成任何记录
        $this->assertSame(0, InviteCode::count());
    }

    public function test_未使用邀请码可禁用与启用(): void
    {
        $code = $this->createCode();

        // 禁用
        $this->actingAs($this->admin, 'admin')
            ->putJson('/admin/cmspro/sso/api/invites/' . $code->id . '/status', ['status' => 0])
            ->assertOk()
            ->assertJsonPath('code', 0);
        $this->assertDatabaseHas('app_cmspro_sso_invite_codes', ['id' => $code->id, 'status' => 0]);

        // 重新启用
        $this->actingAs($this->admin, 'admin')
            ->putJson('/admin/cmspro/sso/api/invites/' . $code->id . '/status', ['status' => 1])
            ->assertOk();
        $this->assertDatabaseHas('app_cmspro_sso_invite_codes', ['id' => $code->id, 'status' => 1]);
    }

    public function test_已使用邀请码不可改状态且不可删除(): void
    {
        $code = $this->createCode([
            'status'  => InviteCode::STATUS_USED,
            'used_by' => 999,
            'used_at' => now(),
        ]);

        $this->actingAs($this->admin, 'admin')
            ->putJson('/admin/cmspro/sso/api/invites/' . $code->id . '/status', ['status' => 0])
            ->assertStatus(400)
            ->assertJsonPath('code', 400);

        $this->actingAs($this->admin, 'admin')
            ->deleteJson('/admin/cmspro/sso/api/invites/' . $code->id)
            ->assertStatus(400);

        $this->assertDatabaseHas('app_cmspro_sso_invite_codes', ['id' => $code->id]);
    }

    public function test_未使用邀请码可删除(): void
    {
        $code = $this->createCode();

        $this->actingAs($this->admin, 'admin')
            ->deleteJson('/admin/cmspro/sso/api/invites/' . $code->id)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertDatabaseMissing('app_cmspro_sso_invite_codes', ['id' => $code->id]);
    }

    public function test_邀请码管理页面可访问(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->get('/admin/cmspro/sso/invite')
            ->assertOk();
    }

    public function test_设置页选择在线购买时按依赖状态渲染提示(): void
    {
        // Onlinepay 未启用：提示块初始可见（style 为空）
        $this->actingAs($this->admin, 'admin')
            ->get('/admin/cmspro/sso/setting')
            ->assertOk()
            ->assertSee('id="onlinepay-status-item" style=""', false)
            ->assertSee('未安装', false);

        // Onlinepay 已启用：提示块初始隐藏
        $this->createOnlinepay(AppStatus::ENABLED->value);
        $this->actingAs($this->admin, 'admin')
            ->get('/admin/cmspro/sso/setting')
            ->assertOk()
            ->assertSee('id="onlinepay-status-item" style="display:none;"', false);
    }

    public function test_在线购买模式未启用支付应用时保存被拦截(): void
    {
        // 未安装 Onlinepay
        $this->actingAs($this->admin, 'admin')
            ->putJson('/admin/cmspro/sso/api/settings', [
                'invite_enabled'    => '1',
                'invite_grant_mode' => 'online_buy',
                'invite_price'      => '9.90',
            ])
            ->assertStatus(400);

        // 已安装但未启用
        $this->createOnlinepay(AppStatus::DISABLED->value);
        $this->actingAs($this->admin, 'admin')
            ->putJson('/admin/cmspro/sso/api/settings', [
                'invite_enabled'    => '1',
                'invite_grant_mode' => 'online_buy',
                'invite_price'      => '9.90',
            ])
            ->assertStatus(400);
    }

    public function test_在线购买模式支付应用已启用时保存成功(): void
    {
        $this->createOnlinepay(AppStatus::ENABLED->value);

        $this->actingAs($this->admin, 'admin')
            ->putJson('/admin/cmspro/sso/api/settings', [
                'invite_enabled'    => '1',
                'invite_grant_mode' => 'online_buy',
                'invite_price'      => '9.90',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertDatabaseHas('config_items', [
            'code'  => 'app_cmspro_sso_invite_grant_mode',
            'value' => 'online_buy',
        ]);
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
     * 预置 Onlinepay 应用记录
     */
    private function createOnlinepay(int $status): AppModel
    {
        return AppModel::create([
            'app_id'  => 'onlinepay',
            'name'    => '在线支付',
            'version' => '1.0.0',
            'status'  => $status,
        ]);
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
                $table->string('author', 100)->nullable();
                $table->string('path', 255)->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->boolean('is_system')->default(false);
                $table->json('manifest')->nullable();
                $table->timestamp('install_time')->nullable();
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
    }
}
