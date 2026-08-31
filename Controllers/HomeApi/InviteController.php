<?php

namespace App\Apps\CmsproSso\Controllers\HomeApi;

use App\Apps\CmsproSso\Models\InviteOrder;
use App\Apps\CmsproSso\Services\SettingService;
use App\Apps\Onlinepay\Gateways\GatewayFactory;
use App\Apps\Onlinepay\Services\PayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * 邀请码购买 API 控制器（游客可访问）
 *
 * 在线购买模式开启后，提供可用支付渠道查询、下单与支付状态轮询能力。
 * 游客下单使用 query_token 防止订单号枚举。
 */
class InviteController extends Controller
{
    public function __construct(protected SettingService $settingService)
    {
    }

    /**
     * 可用支付渠道列表
     */
    public function channels(): JsonResponse
    {
        if (!$this->isBuyEnabled()) {
            return response()->json(['code' => 400, 'message' => '在线购买未开启'], 400);
        }

        $payService = app(PayService::class);
        $channels = [];

        foreach (GatewayFactory::getAvailableGateways() as $code => $info) {
            if ($payService->getChannelConfig($code)) {
                $channels[] = [
                    'code' => $code,
                    'name' => $info['name'],
                ];
            }
        }

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => [
                'channels' => $channels,
                'price'    => $this->invitePrice(),
            ],
        ]);
    }

    /**
     * 创建邀请码购买订单（游客下单，user_id 为空）
     */
    public function createOrder(Request $request): JsonResponse
    {
        if (!$this->isBuyEnabled()) {
            return response()->json(['code' => 400, 'message' => '在线购买未开启'], 400);
        }

        $validated = $request->validate([
            'channel' => 'required|string|in:wechat,alipay,qqpay,unionpay',
        ], [
            'channel.required' => '请选择支付渠道',
            'channel.in'       => '支付渠道不合法',
        ]);

        $payService = app(PayService::class);
        $channel = $validated['channel'];

        if (!$payService->getChannelConfig($channel)) {
            return response()->json(['code' => 400, 'message' => '该支付渠道未启用'], 400);
        }

        $amount = $this->invitePrice();

        // 先落业务订单，再调 Onlinepay 下单（避免网络请求放入长事务）
        $order = InviteOrder::create([
            'out_trade_no' => InviteOrder::generateTradeNo(),
            'query_token'  => InviteOrder::generateQueryToken(),
            'user_id'      => auth('web')->id(),
            'amount'       => $amount,
            'status'       => InviteOrder::STATUS_PENDING,
        ]);

        try {
            $result = $payService->createOrder([
                'out_trade_no' => $order->out_trade_no,
                'channel'      => $channel,
                'pay_method'   => $this->resolvePayMethod($channel),
                'subject'      => '邀请码购买',
                'body'         => '注册邀请码购买，订单号：' . $order->out_trade_no,
                'amount'       => $amount,
                'callback_url' => request()->fullUrl(),
                'client_ip'    => $request->ip(),
                'user_id'      => auth('web')->id(),
                'user_type'    => auth('web')->check() ? 'user' : 'guest',
                'type'         => 'payment',
            ]);
        } catch (\Throwable $e) {
            Log::error('邀请码购买下单失败', ['error' => $e->getMessage(), 'out_trade_no' => $order->out_trade_no]);
            $order->update(['status' => InviteOrder::STATUS_CLOSED]);

            return response()->json(['code' => 500, 'message' => '下单失败，请稍后重试'], 500);
        }

        if (!$result['success']) {
            $order->update(['status' => InviteOrder::STATUS_CLOSED]);

            return response()->json(['code' => 500, 'message' => $result['message'] ?? '下单失败'], 500);
        }

        $order->update(['pay_order_no' => $result['order_no'] ?? null]);

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => [
                'out_trade_no' => $order->out_trade_no,
                'query_token'  => $order->query_token,
                'pay_url'      => $result['pay_url'] ?? '',
                'qr_code'      => $result['qr_code'] ?? '',
                'amount'       => $amount,
            ],
        ]);
    }

    /**
     * 轮询订单支付状态（凭订单号 + query_token）
     */
    public function status(Request $request): JsonResponse
    {
        $outTradeNo = trim((string) $request->input('out_trade_no', ''));
        $token = trim((string) $request->input('query_token', ''));

        if ($outTradeNo === '' || $token === '') {
            return response()->json(['code' => 400, 'message' => '参数缺失'], 400);
        }

        $order = InviteOrder::where('out_trade_no', $outTradeNo)
            ->where('query_token', $token)
            ->first();

        if (!$order) {
            return response()->json(['code' => 404, 'message' => '订单不存在'], 404);
        }

        $paid = (int) $order->status === InviteOrder::STATUS_PAID;

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => [
                'status'      => (int) $order->status,
                'paid'        => $paid,
                'amount'      => (float) $order->amount,
                // 已发放时返回邀请码，前端展示供用户复制
                'invite_code' => $paid && $order->inviteCode ? $order->inviteCode->code : '',
            ],
        ]);
    }

    /**
     * 在线购买是否开启（与购买页控制器保持同一判定）
     */
    protected function isBuyEnabled(): bool
    {
        if ($this->settingService->get('invite_enabled', '0') !== '1') {
            return false;
        }

        return $this->settingService->get('invite_grant_mode', 'online_buy') === 'online_buy'
            && $this->invitePrice() > 0;
    }

    /**
     * 邀请码售价（元），非法配置返回 0
     */
    protected function invitePrice(): float
    {
        $price = $this->settingService->get('invite_price', '');

        return is_numeric($price) ? round((float) $price, 2) : 0.0;
    }

    /**
     * 渠道 → 默认支付方式（与 Onlinepay 用户端充值一致）
     */
    protected function resolvePayMethod(string $channel): string
    {
        return match ($channel) {
            'wechat'  => 'native',
            'alipay'  => 'facetoface',
            'qqpay'   => 'NATIVE',
            'unionpay' => 'gateway',
            default   => 'native',
        };
    }
}
