<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_sso_access_tokens')) {
            return;
        }

        Schema::create('app_cmspro_sso_access_tokens', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('token_hash', 64)->unique()->comment('令牌SHA256哈希（明文仅签发时返回一次）');
            $table->string('client_id', 32)->comment('客户端ID，关联app_cmspro_sso_clients');
            $table->unsignedBigInteger('user_id')->comment('服务端用户ID，关联users表');
            $table->string('platform', 20)->default('')->comment('发起登录的平台标识（wechat/qq）');
            $table->timestamp('expires_at')->comment('过期时间');
            $table->timestamp('revoked_at')->nullable()->comment('吊销时间，非空即已吊销');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');

            $table->index('client_id');
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE `app_cmspro_sso_access_tokens` COMMENT '统一登录快捷模式访问令牌表'");
    }

    public function down(): void
    {
        Schema::dropIfExists('app_cmspro_sso_access_tokens');
    }
};
