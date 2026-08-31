<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_sso_invite_orders')) {
            return;
        }

        Schema::create('app_cmspro_sso_invite_orders', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('out_trade_no', 128)->unique()->comment('商户订单号（前缀 SSOINV_）');
            $table->string('query_token', 64)->comment('游客查询凭据，防止订单号枚举');
            $table->unsignedBigInteger('user_id')->nullable()->index()->comment('下单用户ID，游客下单为 null');
            $table->decimal('amount', 10, 2)->comment('订单金额（邀请码售价）');
            $table->unsignedTinyInteger('status')->default(0)->comment('状态：0-待支付 1-已支付 2-已关闭');
            $table->unsignedBigInteger('invite_code_id')->nullable()->comment('发放的邀请码ID');
            $table->string('pay_order_no', 64)->nullable()->comment('Onlinepay 系统订单号');
            $table->timestamp('paid_at')->nullable()->comment('支付时间');
            $table->timestamp('create_time')->nullable()->comment('创建时间');
            $table->timestamp('update_time')->nullable()->comment('更新时间');

            $table->index('status');
        });

        DB::statement("ALTER TABLE `app_cmspro_sso_invite_orders` COMMENT '统一登录邀请码购买订单表'");
    }

    public function down(): void
    {
        Schema::dropIfExists('app_cmspro_sso_invite_orders');
    }
};
