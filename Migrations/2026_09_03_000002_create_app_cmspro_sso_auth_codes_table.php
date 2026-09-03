<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_sso_auth_codes')) {
            return;
        }

        Schema::create('app_cmspro_sso_auth_codes', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('code', 64)->unique()->comment('授权码，一次性使用');
            $table->string('client_id', 32)->comment('客户端ID，关联app_cmspro_sso_clients');
            $table->unsignedBigInteger('user_id')->comment('服务端用户ID，关联users表');
            $table->string('platform', 20)->default('')->comment('发起登录的平台标识（wechat/qq）');
            $table->string('redirect_uri', 500)->comment('回调地址，换取令牌时须一致');
            $table->timestamp('expires_at')->comment('过期时间（签发后5分钟）');
            $table->timestamp('used_at')->nullable()->comment('使用时间，非空即已消费');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');

            $table->index('client_id');
            $table->index('user_id');
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE `app_cmspro_sso_auth_codes` COMMENT '统一登录快捷模式授权码表'");
    }

    public function down(): void
    {
        Schema::dropIfExists('app_cmspro_sso_auth_codes');
    }
};
