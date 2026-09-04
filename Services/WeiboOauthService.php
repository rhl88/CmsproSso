<?php

namespace App\Apps\CmsproSso\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 微博 OAuth2 服务
 *
 * 独立实现微博开放平台网站接入授权能力，不依赖其他应用：
 * 1. buildOauthRedirectUrl()：生成微博授权跳转链接
 * 2. getOpenidByOauthCode()：用授权 code 换取 access_token 与 uid（微博以 uid 作为身份锚点）
 * 3. getUserInfoByToken()：用 access_token + uid 获取昵称/头像
 */
class WeiboOauthService
{
    public function __construct(
        protected SettingService $settingService
    ) {
    }

    /**
     * 生成微博授权跳转链接
     *
     * @param string $redirectUri 授权后回调地址（需 URL 编码）
     * @param string $state 自定义 state 参数，防 CSRF
     * @return array ['success' => bool, 'url' => string, 'message' => string]
     */
    public function buildOauthRedirectUrl(string $redirectUri, string $state = ''): array
    {
        $appKey = $this->settingService->get('weibo_app_key');

        if ($appKey === '') {
            return ['success' => false, 'url' => '', 'message' => '微博 App Key 未配置'];
        }

        $url = 'https://api.weibo.com/oauth2/authorize'
            . '?client_id=' . urlencode($appKey)
            . '&redirect_uri=' . urlencode($redirectUri);

        if ($state !== '') {
            $url .= '&state=' . urlencode($state);
        }

        return ['success' => true, 'url' => $url, 'message' => 'ok'];
    }

    /**
     * 用授权 code 换取 access_token 与 uid
     *
     * @param string $code 授权 code（一次性）
     * @return array ['success' => bool, 'openid' => string, 'access_token' => string, 'message' => string]
     */
    public function getOpenidByOauthCode(string $code): array
    {
        $appKey = $this->settingService->get('weibo_app_key');
        $appSecret = $this->settingService->get('weibo_app_secret');

        if ($appKey === '' || $appSecret === '') {
            return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => '微博 App Key/App Secret 未配置'];
        }

        try {
            $tokenResponse = Http::withOptions(['verify' => $this->resolveVerify()])
                ->asForm()
                ->post('https://api.weibo.com/oauth2/access_token', [
                    'client_id'     => $appKey,
                    'client_secret' => $appSecret,
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => $this->redirectUri(),
                ]);

            $result = $tokenResponse->json() ?? [];

            // 错误响应：{error, error_code, error_description}
            if (isset($result['error_code'])) {
                Log::error('微博换取 access_token 失败', $result);
                return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => $result['error_description'] ?? '微博授权失败'];
            }

            $accessToken = (string) ($result['access_token'] ?? '');
            $uid = (string) ($result['uid'] ?? '');

            if ($accessToken === '' || $uid === '') {
                Log::error('微博换取 access_token 返回为空', ['response' => $result]);
                return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => '微博授权失败'];
            }

            return [
                'success'      => true,
                'openid'       => $uid,
                'access_token' => $accessToken,
                'message'      => 'ok',
            ];
        } catch (\Throwable $e) {
            Log::error('微博授权请求异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => '微博授权请求异常'];
        }
    }

    /**
     * 用 access_token 与 uid 获取用户信息（昵称、头像等）
     *
     * @param string $accessToken 授权 access_token
     * @param string $openid 用户 uid
     * @return array ['success' => bool, 'nickname' => string, 'avatar' => string, 'message' => string]
     */
    public function getUserInfoByToken(string $accessToken, string $openid): array
    {
        if ($accessToken === '' || $openid === '') {
            return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => '缺少 access_token/uid'];
        }

        try {
            $response = Http::withOptions(['verify' => $this->resolveVerify()])
                ->get('https://api.weibo.com/2/users/show.json', [
                    'access_token' => $accessToken,
                    'uid'          => $openid,
                ]);

            $result = $response->json() ?? [];

            // 错误响应：{error, error_code, error_description}
            if (isset($result['error_code'])) {
                Log::error('微博获取用户信息失败', $result);
                return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => $result['error_description'] ?? '获取用户信息失败'];
            }

            return [
                'success'  => true,
                'nickname' => (string) ($result['screen_name'] ?? ''),
                'avatar'   => (string) ($result['avatar_hd'] ?: ($result['profile_image_url'] ?? '')),
                'message'  => 'ok',
            ];
        } catch (\Throwable $e) {
            Log::error('微博获取用户信息异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => '获取用户信息异常'];
        }
    }

    /**
     * 微博授权回调地址（与授权跳转时使用的 redirect_uri 保持一致）
     */
    protected function redirectUri(): string
    {
        return ShareService::frontUrl('/auth/weibo/callback');
    }

    /**
     * 解析 SSL 校验配置（兼容本地开发环境证书问题）
     */
    protected function resolveVerify(): bool
    {
        return filter_var(env('WEIBO_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN);
    }
}
