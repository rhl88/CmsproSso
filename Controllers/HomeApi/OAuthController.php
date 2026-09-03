<?php

namespace App\Apps\CmsproSso\Controllers\HomeApi;

use App\Apps\CmsproSso\Services\OAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 快捷模式服务端 · 开放 API（不依赖会话，供客户端站点服务器间调用）
 *
 *   GET  /api/cmspro/sso/oauth/platforms  可中转平台列表（浏览器/服务器均可）
 *   POST /api/cmspro/sso/oauth/token      授权码换取访问令牌
 *   GET  /api/cmspro/sso/oauth/userinfo   凭 Bearer 令牌获取用户信息
 *   POST /api/cmspro/sso/oauth/revoke     吊销访问令牌
 *
 * 中转扫码（客户端 PC 端扫码登录中转）：
 *   POST /api/cmspro/sso/oauth/relay-qrcode  创建扫码会话并返回社交授权地址（二维码内容）
 *   GET  /api/cmspro/sso/oauth/relay-status  轮询扫码结果（已确认后返回授权码回调上下文）
 *
 * 本组路由不注册 web/session 中间件（跨站服务器调用无 CSRF），仅限流。
 */
class OAuthController extends Controller
{
    public function __construct(
        protected OAuthService $oauthService
    ) {
    }

    /**
     * 可中转平台列表（供客户端站点动态渲染社交登录入口）
     */
    public function platforms(Request $request): JsonResponse
    {
        if (!$this->oauthService->isServerEnabled()) {
            return response()->json(['code' => 404, 'message' => '未开放'], 404);
        }

        $client = $this->oauthService->findEnabledClient(trim((string) $request->query('client_id', '')));
        if (!$client) {
            return response()->json(['code' => 400, 'message' => '无效的客户端'], 400);
        }

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => ['platforms' => $this->oauthService->relayPlatforms()],
        ]);
    }

    /**
     * 授权码换取访问令牌（OAuth2 token 端点）
     */
    public function token(Request $request): JsonResponse
    {
        if (!$this->oauthService->isServerEnabled()) {
            return response()->json(['code' => 404, 'message' => '未开放'], 404);
        }

        $clientId = trim((string) $request->input('client_id', ''));
        $clientSecret = trim((string) $request->input('client_secret', ''));
        $code = trim((string) $request->input('code', ''));
        $redirectUri = trim((string) $request->input('redirect_uri', ''));
        $grantType = trim((string) $request->input('grant_type', 'authorization_code'));

        if ($grantType !== 'authorization_code') {
            return response()->json(['code' => 400, 'message' => '不支持的 grant_type，仅支持 authorization_code'], 400);
        }
        if ($clientId === '' || $clientSecret === '' || $code === '' || $redirectUri === '') {
            return response()->json(['code' => 400, 'message' => '缺少必要参数（client_id/client_secret/code/redirect_uri）'], 400);
        }

        $result = $this->oauthService->exchangeCode($clientId, $clientSecret, $code, $redirectUri);
        if (!$result['success']) {
            return response()->json(['code' => 400, 'message' => $result['message']], 400);
        }

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => [
                'access_token' => $result['access_token'],
                'token_type'   => $result['token_type'],
                'expires_in'   => $result['expires_in'],
            ],
        ]);
    }

    /**
     * 获取用户信息（Bearer 令牌认证，仅返回基础字段）
     */
    public function userinfo(Request $request): JsonResponse
    {
        if (!$this->oauthService->isServerEnabled()) {
            return response()->json(['code' => 404, 'message' => '未开放'], 404);
        }

        $resolved = $this->oauthService->resolveToken($this->bearerToken($request));
        if (!$resolved) {
            return response()->json(['code' => 401, 'message' => '访问令牌无效或已过期'], 401);
        }

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => $this->oauthService->userinfoData($resolved['user'], $resolved['platform']),
        ]);
    }

    /**
     * 吊销访问令牌
     */
    public function revoke(Request $request): JsonResponse
    {
        if (!$this->oauthService->isServerEnabled()) {
            return response()->json(['code' => 404, 'message' => '未开放'], 404);
        }

        $token = trim((string) ($request->input('token', '') ?: $this->bearerToken($request)));
        if ($token === '') {
            return response()->json(['code' => 400, 'message' => '缺少令牌'], 400);
        }

        $this->oauthService->revokeToken($token);

        return response()->json(['code' => 0, 'message' => 'ok', 'data' => null]);
    }

    // ---------------------------------------------------------------- 中转扫码（客户端 PC 端）

    /**
     * 创建中转扫码会话并返回社交授权地址（二维码内容）
     *
     * 客户端站点 PC 端发起扫码中转：校验客户端与回调地址白名单后登记会话，
     * 返回可在客户端渲染为二维码的社交授权地址。扫码授权完成后服务端
     * authorize 回调识别该会话，将授权码签发结果写入会话供轮询接口取回。
     */
    public function relayQrcode(Request $request): JsonResponse
    {
        if (!$this->oauthService->isServerEnabled()) {
            return response()->json(['code' => 404, 'message' => '未开放'], 404);
        }

        $clientId = trim((string) $request->input('client_id', ''));
        $redirectUri = trim((string) $request->input('redirect_uri', ''));
        $platform = trim((string) $request->input('platform', ''));

        if ($platform !== '' && !$this->oauthService->isPlatformAvailable($platform)) {
            return response()->json(['code' => 400, 'message' => '该登录方式未在服务端启用'], 400);
        }

        $sessionId = $this->oauthService->createRelayQrSession($clientId, $redirectUri);
        if ($sessionId === null) {
            return response()->json(['code' => 400, 'message' => '无效的客户端或回调地址'], 400);
        }

        // 二维码内容：服务端 authorize 端点（浏览器跳转地址，手机扫码后打开授权页）
        $authorizeUrl = rtrim($this->authorizeBaseUrl(), '/')
            . '?' . http_build_query([
                'client_id'     => $clientId,
                'redirect_uri'  => $redirectUri,
                'response_type' => 'code',
                'platform'      => $platform,
                'state'         => 'relay_qr:' . $sessionId,
            ]);

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => [
                'session_id'   => $sessionId,
                'qrcode_url'   => $authorizeUrl,
                'expires_in'   => $this->oauthService::RELAY_QR_TTL,
            ],
        ]);
    }

    /**
     * 轮询中转扫码结果
     *
     * 返回状态：waiting（未扫码/未确认）→ confirmed（已确认，携带授权码回调上下文，
     * 客户端凭 code 走既有 token 换取流程建立登录态）→ consumed（结果已被取走）。
     */
    public function relayStatus(Request $request): JsonResponse
    {
        if (!$this->oauthService->isServerEnabled()) {
            return response()->json(['code' => 404, 'message' => '未开放'], 404);
        }

        $sessionId = trim((string) $request->input('session_id', ''));
        if ($sessionId === '' || !$this->oauthService->getRelayQrSession($sessionId)) {
            return response()->json(['code' => 400, 'message' => '扫码会话无效或已过期'], 400);
        }

        $result = $this->oauthService->pullRelayQrResult($sessionId);
        if (!$result) {
            return response()->json([
                'code'    => 0,
                'message' => 'ok',
                'data'    => ['status' => 'waiting'],
            ]);
        }

        // 授权失败（用户拒绝等）：携带错误信息返回
        if (isset($result['error'])) {
            return response()->json([
                'code'    => 0,
                'message' => 'ok',
                'data'    => ['status' => 'failed', 'error' => (string) $result['error']],
            ]);
        }

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => [
                'status'       => 'confirmed',
                'code'         => (string) ($result['code'] ?? ''),
                'platform'     => (string) ($result['platform'] ?? ''),
            ],
        ]);
    }

    /**
     * 服务端 authorize 端点基础地址（scheme://host[:port] + 前台路径前缀）
     */
    protected function authorizeBaseUrl(): string
    {
        return \App\Apps\CmsproSso\Services\ShareService::frontUrl('/oauth/authorize');
    }

    /**
     * 从请求头解析 Bearer 令牌
     */
    protected function bearerToken(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if ($header === '' || !str_starts_with($header, 'Bearer ')) {
            return '';
        }

        return trim(substr($header, 7));
    }
}
