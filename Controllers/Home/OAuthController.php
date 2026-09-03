<?php

namespace App\Apps\CmsproSso\Controllers\Home;

use App\Apps\CmsproSso\Services\OAuthService;
use App\Apps\CmsproSso\Services\ShareService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 快捷模式服务端 · 授权端点（浏览器跳转流程）
 *
 * 客户端站点将用户重定向到此端点：
 *   1. 校验客户端与回调地址白名单；
 *   2. 服务端未登录 → 跳转统一登录页（携带 platform 提示，登录后回跳本端点）；
 *   3. 已登录 → 签发授权码并 302 回客户端回调地址（code + state）。
 */
class OAuthController extends Controller
{
    public function __construct(
        protected OAuthService $oauthService
    ) {
    }

    public function authorize(Request $request)
    {
        // 仅快捷模式=服务端时开放
        if (!$this->oauthService->isServerEnabled()) {
            abort(404);
        }

        $clientId = trim((string) $request->query('client_id', ''));
        $redirectUri = trim((string) $request->query('redirect_uri', ''));
        $responseType = trim((string) $request->query('response_type', ''));
        $platform = trim((string) $request->query('platform', ''));
        $state = trim((string) $request->query('state', ''));

        // 客户端与回调地址校验（无效时不得向回调地址跳转，防开放重定向）
        // 注意：此处显式返回 400 响应而非 abort()，避免框架异常兜底将 4xx 渲染为 500
        $client = $clientId !== '' ? $this->oauthService->findEnabledClient($clientId) : null;
        if (!$client) {
            return response('无效的客户端（client_id 不存在或已被禁用）', 400);
        }
        if ($redirectUri === '' || !$this->oauthService->isRedirectUriAllowed($client, $redirectUri)) {
            return response('回调地址不在客户端白名单内', 400);
        }
        if ($responseType !== 'code') {
            return response('不支持的 response_type，仅支持 code', 400);
        }
        if ($platform !== '' && !$this->oauthService->isPlatformAvailable($platform)) {
            return response('该登录方式未在服务端启用', 400);
        }

        // 未登录：跳转统一登录页，登录成功后回跳本端点完成授权
        if (!auth('web')->check()) {
            $authorizeUrl = $request->fullUrl();
            $loginUrl = ShareService::frontUrl('/login')
                . '?redirect=' . urlencode($authorizeUrl)
                . ($platform !== '' ? '&platform=' . urlencode($platform) : '');

            return redirect($loginUrl);
        }

        // 已登录：签发授权码并回跳客户端
        $user = auth('web')->user();
        $code = $this->oauthService->issueAuthCode($client, $user, $platform, $redirectUri);

        $query = ['code' => $code];
        if ($state !== '') {
            $query['state'] = $state;
        }

        return redirect($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($query));
    }
}
