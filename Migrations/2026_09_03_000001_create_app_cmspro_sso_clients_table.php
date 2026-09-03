<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_sso_clients')) {
            return;
        }

        Schema::create('app_cmspro_sso_clients', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('client_id', 32)->unique()->comment('客户端ID，发放给对接方');
            $table->string('secret_hash', 255)->comment('客户端密钥哈希（明文仅创建/重置时展示一次）');
            $table->string('name', 100)->comment('应用名称');
            $table->text('redirect_uris')->comment('回调地址白名单，换行分隔精确匹配');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态：0-禁用 1-启用');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');

            $table->index('status');
        });

        DB::statement("ALTER TABLE `app_cmspro_sso_clients` COMMENT '统一登录快捷模式客户端表'");
    }

    public function down(): void
    {
        Schema::dropIfExists('app_cmspro_sso_clients');
    }
};
