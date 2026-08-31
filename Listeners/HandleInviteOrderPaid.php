<?php

namespace App\Apps\CmsproSso\Listeners;

use App\Apps\CmsproSso\Models\InviteCode;
use App\Apps\CmsproSso\Models\InviteOrder;
use App\Apps\Onlinepay\Events\OrderPaid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 邀请码购买订单支付成功监听器
 *
 * Onlinepay 派发 OrderPaid 事件后，依据商户订单号前缀识别本应用订单，
 * 幂等地更新订单状态并生成/发放邀请码。
 */
class HandleInviteOrderPaid
{
    public function handle(OrderPaid $event): void
    {
        $order = $event->order;

        // 仅处理本应用的邀请码购买订单
        if (!str_starts_with((string) $order->out_trade_no, InviteOrder::TRADE_NO_PREFIX)) {
            return;
        }

        $inviteOrder = InviteOrder::where('out_trade_no', $order->out_trade_no)->first();
        if (!$inviteOrder) {
            Log::warning('邀请码订单支付回调：本地订单不存在', ['out_trade_no' => $order->out_trade_no]);
            return;
        }

        // 幂等：已支付订单不重复发放
        if ((int) $inviteOrder->status === InviteOrder::STATUS_PAID) {
            return;
        }

        DB::transaction(function () use ($inviteOrder, $order) {
            $code = InviteCode::create([
                'code'        => InviteCode::generateUniqueCode(),
                'status'      => InviteCode::STATUS_UNUSED,
                'price'       => $inviteOrder->amount,
            ]);

            $inviteOrder->update([
                'status'         => InviteOrder::STATUS_PAID,
                'invite_code_id' => $code->id,
                'pay_order_no'   => $order->order_no ?? null,
                'paid_at'        => now(),
            ]);
        });

        Log::info('邀请码购买订单已支付并发放邀请码', [
            'out_trade_no'   => $inviteOrder->out_trade_no,
            'invite_code_id' => $inviteOrder->invite_code_id,
        ]);
    }
}
