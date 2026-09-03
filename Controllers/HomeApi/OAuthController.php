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
