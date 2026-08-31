<?php

namespace App\Apps\CmsproSso\Models;

use App\Models\BaseModel;

/**
 * 统一登录邀请码购买订单模型
 *
 * 游客/用户通过 Onlinepay 购买邀请码的订单快照，
 * 支付成功由 HandleInviteOrderPaid 监听器发放邀请码。
 *
 * status：0-待支付 1-已支付 2-已关闭
 */
class InviteOrder extends BaseModel
{
    protected $table = 'app_cmspro_sso_invite_orders';

    /** 商户订单号前缀（Onlinepay OrderPaid 事件据此识别） */
    public const TRADE_NO_PREFIX = 'SSOINV_';

    public const STATUS_PENDING = 0;

    public const STATUS_PAID = 1;

    public const STATUS_CLOSED = 2;

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    protected $fillable = [
        'out_trade_no',
        'query_token',
        'user_id',
        'amount',
        'status',
        'invite_code_id',
        'pay_order_no',
        'paid_at',
    ];

    protected $casts = [
        'amount'      => 'float',
        'paid_at'     => 'datetime:Y-m-d H:i:s',
        'create_time' => 'datetime:Y-m-d H:i:s',
        'update_time' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * 关联发放的邀请码
     */
    public function inviteCode()
    {
        return $this->belongsTo(InviteCode::class, 'invite_code_id');
    }

    /**
     * 生成唯一商户订单号：前缀 + 年月日时分秒 + 4 位随机
     */
    public static function generateTradeNo(): string
    {
        do {
            $tradeNo = self::TRADE_NO_PREFIX . date('YmdHis') . '_' . mt_rand(1000, 9999);
        } while (self::where('out_trade_no', $tradeNo)->exists());

        return $tradeNo;
    }

    /**
     * 生成游客查询凭据（32 位十六进制）
     */
    public static function generateQueryToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
