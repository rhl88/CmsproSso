<?php

namespace App\Apps\CmsproSso\Controllers\Home;

use App\Apps\CmsproSso\Services\OAuthService;
use App\Apps\CmsproSso\Services\QqOauthService;
use App\Apps\CmsproSso\Services\ShareService;
use App\Apps\CmsproSso\Services\WechatOauthService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 快捷模式服务端 · 授权端点（浏览器跳转流程）
 *
 * 客户端站点将用户重定向到此端点：
 *   1. 校验客户端与回调地址白名单；
 *   2. 构建（或按请求参数取）社交平台授权地址，302 跳转社交平台授权页；
 *   3. 社交平台授权完成后回调本应用社交回调端点，服务端以纯跳板方式向客户端
 *      透传社交授权数据（不建账号、不落用户数据）。
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

        // 中转扫码会话（state = relay_qr:{session_id}）：二维码内容本应直达社交授权页，
        // 但扫码会话创建时不限定平台（platform 可为空），此处兜底解析目标平台后仍跳社交授权
        $isRelayQr = str_starts_with($state, 'relay_qr:');

        // 解析本次中转的目标社交平台（未指定时默认微信，与登录页入口平台一致）
        if ($platform === '') {
            $platform = 'wechat';
        }

        // 构建社交平台授权地址（纯跳板：服务端不登录、不签发，直接代跳社交授权）
        $callbackUrl = ShareService::frontUrl('/auth/' . $platform . '/callback');
        // 社交回调 state 携带中转上下文（客户端与会话标识），回调时据此识别中转场景
        $relayContext = $isRelayQr
            ? $state
            : 'relay:' . $client->client_id . ':' . bin2hex(random_bytes(12));
        // 客户端原始 state（整页跳转中转时为客户端登录会话暂存值）一并编入，社交授权完成后回跳客户端时带回
        $oauthState = $this->buildOauthState($relayContext, $redirectUri, $isRelayQr ? '' : $state);

        $buildResult = $this->buildPlatformOauthUrl($platform, $callbackUrl, $oauthState);
        if (!$buildResult['success']) {
            return response($buildResult['message'], 400);
        }

        return redirect($buildResult['url']);
    }

    /**
     * 构建社交回调 state（中转上下文 + 回调地址，Base64 编码承载）
     *
     * @param string $relayContext 中转上下文（relay:{client_id}:{nonce} 或 relay_qr:{session_id}）
     * @param string $redirectUri 客户端回调地址（社交授权完成后透传给授权码流程）
     * @param string $clientState 客户端原始 state（整页跳转中转时携带，社交授权完成后回跳客户端时原样带回）
     */
    protected function buildOauthState(string $relayContext, string $redirectUri, string $clientState = ''): string
    {
        $payload = [
            'ctx' => $relayContext,
            'uri' => $redirectUri,
        ];
        if ($clientState !== '') {
            $payload['s'] = $clientState;
        }

        return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    }

    /**
     * 构建社交平台授权跳转地址（微信网页授权 / QQ 互联授权）
     *
     * @return array{success: bool, url: string, message: string}
     */
    protected function buildPlatformOauthUrl(string $platform, string $callbackUrl, string $oauthState): array
    {
        $services = [
            'wechat' => WechatOauthService::class,
            'qq'     => QqOauthService::class,
        ];

        if (!isset($services[$platform])) {
            return ['success' => false, 'url' => '', 'message' => '无效的平台'];
        }

        return app($services[$platform])->buildOauthRedirectUrl($callbackUrl, $oauthState);
    }
}
