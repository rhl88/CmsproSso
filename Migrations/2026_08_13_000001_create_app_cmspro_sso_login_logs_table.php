<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_sso_login_logs')) {
            return;
        }

        Schema::create('app_cmspro_sso_login_logs', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->unsignedBigInteger('user_id')->nullable()->comment('用户ID，关联users表');
            $table->string('platform', 20)->default('password')->comment('登录方式：password-账号密码 wechat-微信 qq-QQ（预留）');
            $table->string('ip', 45)->nullable()->comment('登录IP');
            $table->string('user_agent', 500)->nullable()->comment('浏览器UA');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态：0-失败 1-成功');
            $table->string('message', 255)->nullable()->comment('登录结果说明');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');

            $table->index('user_id');
            $table->index('platform');
            $table->index('create_time');
        });

        DB::statement("ALTER TABLE `app_cmspro_sso_login_logs` COMMENT '统一登录日志表'");
    }

    public function down(): void
    {
        Schema::dropIfExists('app_cmspro_sso_login_logs');
    }
};
