<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_sso_invite_codes')) {
            return;
        }

        Schema::create('app_cmspro_sso_invite_codes', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('code', 32)->unique()->comment('邀请码');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态：0-禁用 1-未使用 2-已使用');
            $table->decimal('price', 10, 2)->nullable()->comment('售价（元），生成时快照');
            $table->unsignedBigInteger('used_by')->nullable()->comment('使用者用户ID，关联users表');
            $table->timestamp('used_at')->nullable()->comment('使用时间');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');

            $table->index('status');
            $table->index('used_by');
        });

        DB::statement("ALTER TABLE `app_cmspro_sso_invite_codes` COMMENT '统一登录邀请码表'");
    }

    public function down(): void
    {
        Schema::dropIfExists('app_cmspro_sso_invite_codes');
    }
};
