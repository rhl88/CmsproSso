<?php

namespace App\Apps\CmsproSso\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 抖音 OAuth2 服务
 *
 * 独立实现抖音开放平台网站应用授权能力，不依赖其他应用：
 * 1. buildOauthRedirectUrl()：生成抖音授权跳转链接（user_info 作用域）
 * 2. getOpenidByOauthCode()：用授权 code 换取 access_token 与 open_id
 * 3. getUserInfoByToken()：用 access_token + open_id 获取昵称/头像
 */
class DouyinOauthService
{
    public function __construct(
        protected SettingService $settingService
    ) {
    }

    /**
     * 生成抖音授权跳转链接
     *
     * @param string $redirectUri 授权后回调地址（需 URL 编码）
     * @param string $state 自定义 state 参数，防 CSRF
     * @return array ['success' => bool, 'url' => string, 'message' => string]
     */
    public function buildOauthRedirectUrl(string $redirectUri, string $state = ''): array
    {
        $clientKey = $this->settingService->get('douyin_client_key');

        if ($clientKey === '') {
            return ['success' => false, 'url' => '', 'message' => '抖音 ClientKey 未配置'];
        }

        $url = 'https://open.douyin.com/platform/oauth/connect/'
            . '?client_key=' . urlencode($clientKey)
            . '&response_type=code'
            . '&scope=user_info'
            . '&redirect_uri=' . urlencode($redirectUri);

        if ($state !== '') {
            $url .= '&state=' . urlencode($state);
        }

        return ['success' => true, 'url' => $url, 'message' => 'ok'];
    }

    /**
     * 用授权 code 换取 access_token 与 open_id
     *
     * @param string $code 授权 code（一次性）
     * @return array ['success' => bool, 'openid' => string, 'access_token' => string, 'message' => string]
     */
    public function getOpenidByOauthCode(string $code): array
    {
        $clientKey = $this->settingService->get('douyin_client_key');
        $clientSecret = $this->settingService->get('douyin_client_secret');

        if ($clientKey === '' || $clientSecret === '') {
            return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => '抖音 ClientKey/ClientSecret 未配置'];
        }

        try {
            $tokenResponse = Http::withOptions(['verify' => $this->resolveVerify()])
                ->get('https://open.douyin.com/oauth/access_token/', [
                    'client_key'    => $clientKey,
                    'client_secret' => $clientSecret,
                    'code'          => $code,
                    'grant_type'    => 'authorization_code',
                ]);

            $result = $tokenResponse->json('data') ?? [];

            // 错误响应：{data: {error_code, description}}（error_code 非 0 即失败）
            if ((int) ($result['error_code'] ?? -1) !== 0) {
                Log::error('抖音换取 access_token 失败', $tokenResponse->json() ?? []);
                return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => $result['description'] ?? '抖音授权失败'];
            }

            $accessToken = (string) ($result['access_token'] ?? '');
            $openId = (string) ($result['open_id'] ?? '');

            if ($accessToken === '' || $openId === '') {
                Log::error('抖音换取 access_token 返回为空', ['response' => $result]);
                return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => '抖音授权失败'];
            }

            return [
                'success'      => true,
                'openid'       => $openId,
                'access_token' => $accessToken,
                'message'      => 'ok',
            ];
        } catch (\Throwable $e) {
            Log::error('抖音授权请求异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => '抖音授权请求异常'];
        }
    }

    /**
     * 用 access_token 与 open_id 获取用户信息（昵称、头像等）
     *
     * @param string $accessToken 授权 access_token
     * @param string $openid 用户 open_id
     * @return array ['success' => bool, 'nickname' => string, 'avatar' => string, 'message' => string]
     */
    public function getUserInfoByToken(string $accessToken, string $openid): array
    {
        if ($accessToken === '' || $openid === '') {
            return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => '缺少 access_token/open_id'];
        }

        try {
            $response = Http::withOptions(['verify' => $this->resolveVerify()])
                ->get('https://open.douyin.com/oauth/userinfo/', [
                    'access_token' => $accessToken,
                    'open_id'      => $openid,
                ]);

            $result = $response->json('data') ?? [];

            if ((int) ($result['error_code'] ?? -1) !== 0) {
                Log::error('抖音获取用户信息失败', $response->json() ?? []);
                return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => $result['description'] ?? '获取用户信息失败'];
            }

            return [
                'success'  => true,
                'nickname' => (string) ($result['nickname'] ?? ''),
                'avatar'   => (string) ($result['avatar'] ?? ''),
                'message'  => 'ok',
            ];
        } catch (\Throwable $e) {
            Log::error('抖音获取用户信息异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => '获取用户信息异常'];
        }
    }

    /**
     * 抖音授权回调地址（与授权跳转时使用的 redirect_uri 保持一致）
     */
    protected function redirectUri(): string
    {
        return ShareService::frontUrl('/auth/douyin/callback');
    }

    /**
     * 解析 SSL 校验配置（兼容本地开发环境证书问题）
     */
    protected function resolveVerify(): bool
    {
        return filter_var(env('DOUYIN_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN);
    }
}
