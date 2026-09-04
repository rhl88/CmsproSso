<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 快捷模式纯跳板透传改造
 *
 * 服务端仅作社交授权跳板，不建账号、不绑定用户、不落任何用户数据：
 *   - 授权码表 user_id 改为可空（授权码不再关联服务端用户）；
 *   - 访问令牌表 user_id 改为可空（令牌不再关联服务端用户）；
 *   - 访问令牌表新增 data_token 字段（社交授权数据暂存键，客户端凭令牌透传读取 openid 等数据）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_sso_auth_codes')
            && Schema::hasColumn('app_cmspro_sso_auth_codes', 'user_id')) {
            Schema::table('app_cmspro_sso_auth_codes', function ($table) {
                $table->unsignedBigInteger('user_id')->nullable()->comment('服务端用户ID，纯透传模式不再关联，恒为空')->change();
            });
        }

        if (Schema::hasTable('app_cmspro_sso_access_tokens')) {
            Schema::table('app_cmspro_sso_access_tokens', function ($table) {
                if (Schema::hasColumn('app_cmspro_sso_access_tokens', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->comment('服务端用户ID，纯透传模式不再关联，恒为空')->change();
                }
                if (!Schema::hasColumn('app_cmspro_sso_access_tokens', 'data_token')) {
                    $table->string('data_token', 64)->default('')->comment('社交授权数据暂存键，凭访问令牌透传读取')->after('platform');
                }
            });
        }
    }

    public function down(): void
    {
        // 纯跳板透传为单向重构，不提供回滚（回滚需先清空 user_id 空值记录）
    }
};
