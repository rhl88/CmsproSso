<?php

namespace App\Apps\CmsproSso\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * QQ 互联（OAuth2）服务
 *
 * 独立实现 QQ 互联网站应用授权能力，不依赖其他应用：
 * 1. buildOauthRedirectUrl()：生成 QQ 授权跳转链接（get_user_info 作用域）
 * 2. getOpenidByOauthCode()：用授权 code 换取 access_token，再用 access_token 换取 openid
 * 3. getUserInfoByToken()：用 access_token + openid 获取昵称/头像
 */
class QqOauthService
{
    public function __construct(
        protected SettingService $settingService
    ) {
    }

    /**
     * 生成 QQ 互联授权跳转链接
     *
     * @param string $redirectUri 授权后回调地址（需 URL 编码）
     * @param string $state 自定义 state 参数，防 CSRF
     * @return array ['success' => bool, 'url' => string, 'message' => string]
     */
    public function buildOauthRedirectUrl(string $redirectUri, string $state = ''): array
    {
        $appId = $this->settingService->get('qq_app_id');

        if ($appId === '') {
            return ['success' => false, 'url' => '', 'message' => 'QQ互联 App ID 未配置'];
        }

        $url = 'https://graph.qq.com/oauth2.0/authorize'
            . '?response_type=code'
            . '&client_id=' . urlencode($appId)
            . '&redirect_uri=' . urlencode($redirectUri)
            . '&scope=get_user_info';

        if ($state !== '') {
            $url .= '&state=' . urlencode($state);
        }

        return ['success' => true, 'url' => $url, 'message' => 'ok'];
    }

    /**
     * 用授权 code 换取 access_token 与 openid
     *
     * QQ 互联需两步：
     * 1. 用 code 换 access_token（https://graph.qq.com/oauth2.0/token）
     * 2. 用 access_token 换 openid（https://graph.qq.com/oauth2.0/me）
     *
     * @param string $code 授权 code（一次性，10分钟有效）
     * @return array ['success' => bool, 'openid' => string, 'access_token' => string, 'message' => string]
     */
    public function getOpenidByOauthCode(string $code): array
    {
        $appId = $this->settingService->get('qq_app_id');
        $appKey = $this->settingService->get('qq_app_key');

        if ($appId === '' || $appKey === '') {
            return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => 'QQ互联 App ID/App Key 未配置'];
        }

        try {
            // 第一步：code 换取 access_token
            $tokenResponse = Http::withOptions(['verify' => $this->resolveVerify()])
                ->get('https://graph.qq.com/oauth2.0/token', [
                    'grant_type'    => 'authorization_code',
                    'client_id'     => $appId,
                    'client_secret' => $appKey,
                    'code'          => $code,
                    'redirect_uri'  => $this->redirectUri(),
                ]);

            $tokenResult = $tokenResponse->body();

            // 错误响应为 JSON 格式（{"error":...,"error_description":...}），成功响应为 query 字符串（access_token=...&expires_in=...）
            if (str_starts_with(trim($tokenResult), '{')) {
                $error = json_decode($tokenResult, true) ?? [];
                Log::error('QQ互联换取 access_token 失败', $error);
                return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => $error['error_description'] ?? 'QQ互联授权失败'];
            }

            parse_str($tokenResult, $tokenData);
            $accessToken = $tokenData['access_token'] ?? '';

            if ($accessToken === '') {
                Log::error('QQ互联换取 access_token 返回为空', ['response' => $tokenResult]);
                return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => 'QQ互联授权失败'];
            }

            // 第二步：access_token 换取 openid
            $meResponse = Http::withOptions(['verify' => $this->resolveVerify()])
                ->get('https://graph.qq.com/oauth2.0/me', [
                    'access_token' => $accessToken,
                ]);

            $meResult = $meResponse->body();

            // 正常返回 callback( {"client_id":"...","openid":"..."} );，错误返回 JSON
            if (!preg_match('/"openid"\s*:\s*"([^"]+)"/', $meResult, $matches)) {
                Log::error('QQ互联获取 openid 失败', ['response' => $meResult]);
                return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => '获取QQ用户标识失败'];
            }

            return [
                'success'      => true,
                'openid'       => $matches[1],
                'access_token' => $accessToken,
                'message'      => 'ok',
            ];
        } catch (\Throwable $e) {
            Log::error('QQ互联授权请求异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => 'QQ互联授权请求异常'];
        }
    }

    /**
     * 用 access_token 与 openid 获取用户信息（昵称、头像等）
     *
     * @param string $accessToken 授权 access_token
     * @param string $openid 用户 openid
     * @return array ['success' => bool, 'nickname' => string, 'avatar' => string, 'message' => string]
     */
    public function getUserInfoByToken(string $accessToken, string $openid): array
    {
        $appId = $this->settingService->get('qq_app_id');

        if ($accessToken === '' || $openid === '' || $appId === '') {
            return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => '缺少 access_token/openid/App ID'];
        }

        try {
            $response = Http::withOptions(['verify' => $this->resolveVerify()])
                ->get('https://graph.qq.com/user/get_user_info', [
                    'access_token' => $accessToken,
                    'oauth_consumer_key' => $appId,
                    'openid'       => $openid,
                ]);

            $result = $response->json();

            if (isset($result['ret']) && (int) $result['ret'] !== 0) {
                Log::error('QQ互联获取用户信息失败', $result);
                return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => $result['msg'] ?? '获取用户信息失败'];
            }

            return [
                'success'  => true,
                'nickname' => $result['nickname'] ?? '',
                'avatar'   => $result['figureurl_qq_2'] ?: ($result['figureurl_qq_1'] ?? ''),
                'message'  => 'ok',
            ];
        } catch (\Throwable $e) {
            Log::error('QQ互联获取用户信息异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => '获取用户信息异常'];
        }
    }

    /**
     * QQ 授权回调地址（与授权跳转时使用的 redirect_uri 保持一致）
     *
     * 统一返回本应用 QQ 授权回调，code 换 openid 时需与授权时传入的 redirect_uri 完全一致。
     */
    protected function redirectUri(): string
    {
        return \App\Apps\CmsproSso\Services\ShareService::frontUrl('/auth/qq/callback');
    }

    /**
     * 解析 SSL 校验配置（兼容本地开发环境证书问题）
     */
    protected function resolveVerify(): bool
    {
        $verify = env('QQ_SSL_VERIFY', 'true');
        return filter_var($verify, FILTER_VALIDATE_BOOLEAN);
    }
}
