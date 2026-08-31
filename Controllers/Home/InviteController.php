<?php

namespace App\Apps\CmsproSso\Controllers\Home;

use App\Apps\CmsproSso\Services\ShareService;
use App\Apps\CmsproSso\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 邀请码购买页控制器（游客可访问）
 */
class InviteController extends Controller
{
    public function __construct(protected SettingService $settingService)
    {
    }

    /**
     * 购买页：仅邀请注册开启且发放方式为在线购买时可访问
     */
    public function buyForm(Request $request)
    {
        if (!$this->isBuyEnabled()) {
            return redirect(ShareService::frontUrl('/register'));
        }

        return view('cmspro.sso::Home.invite.buy', [
            'price'   => $this->invitePrice(),
            'redirect' => (string) $request->input('redirect', ''),
        ]);
    }

    /**
     * 在线购买是否可用：邀请注册开启 + 发放方式为在线购买 + 费用配置有效
     */
    public function isBuyEnabled(): bool
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
    public function invitePrice(): float
    {
        $price = $this->settingService->get('invite_price', '');

        return is_numeric($price) ? round((float) $price, 2) : 0.0;
    }
}
