<?php

namespace App\Apps\CmsproSso\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 微信网页授权（OAuth2）服务
 *
 * 独立实现公众号网页授权能力，不依赖其他应用：
 * 1. buildOauthRedirectUrl()：生成网页授权跳转链接（snsapi_userinfo 作用域）
 * 2. getOpenidByOauthCode()：用授权 code 换取 openid/unionid/access_token
 * 3. getUserInfoByToken()：用 access_token + openid 获取昵称/头像
 */
class WechatOauthService
{
    public function __construct(
        protected SettingService $settingService
    ) {
    }

    /**
     * 生成公众号网页授权跳转链接
     *
     * @param string $redirectUri 授权后回调地址（需 URL 编码）
     * @param string $state 自定义 state 参数，防 CSRF
     * @return array ['success' => bool, 'url' => string, 'message' => string]
     */
    public function buildOauthRedirectUrl(string $redirectUri, string $state = ''): array
    {
        $appId = $this->settingService->get('wechat_mp_app_id');

        if ($appId === '') {
            return ['success' => false, 'url' => '', 'message' => '公众号 AppID 未配置'];
        }

        $url = 'https://open.weixin.qq.com/connect/oauth2/authorize'
            . '?appid=' . urlencode($appId)
            . '&redirect_uri=' . urlencode($redirectUri)
            . '&response_type=code'
            . '&scope=snsapi_userinfo';

        if ($state !== '') {
            $url .= '&state=' . urlencode($state);
        }

        $url .= '#wechat_redirect';

        return ['success' => true, 'url' => $url, 'message' => 'ok'];
    }

    /**
     * 用网页授权 code 换取 access_token 与 openid
     *
     * @param string $code 授权 code（一次性，5分钟有效）
     * @return array ['success' => bool, 'openid' => string, 'unionid' => string|null, 'access_token' => string, 'refresh_token' => string, 'message' => string]
     */
    public function getOpenidByOauthCode(string $code): array
    {
        $appId = $this->settingService->get('wechat_mp_app_id');
        $secret = $this->settingService->get('wechat_mp_app_secret');

        if ($appId === '' || $secret === '') {
            return ['success' => false, 'openid' => '', 'unionid' => null, 'access_token' => '', 'refresh_token' => '', 'message' => '公众号 AppID/AppSecret 未配置'];
        }

        try {
            $response = Http::withOptions(['verify' => $this->resolveVerify()])
                ->get('https://api.weixin.qq.com/sns/oauth2/access_token', [
                    'appid'      => $appId,
                    'secret'     => $secret,
                    'code'       => $code,
                    'grant_type' => 'authorization_code',
                ]);

            $result = $response->json();

            if (isset($result['errcode']) && (int) $result['errcode'] !== 0) {
                Log::error('微信网页授权换取 openid 失败', $result);
                return ['success' => false, 'openid' => '', 'unionid' => null, 'access_token' => '', 'refresh_token' => '', 'message' => $result['errmsg'] ?? '微信授权失败'];
            }

            return [
                'success'       => true,
                'openid'        => $result['openid'] ?? '',
                'unionid'       => $result['unionid'] ?? null,
                'access_token'  => $result['access_token'] ?? '',
                'refresh_token' => $result['refresh_token'] ?? '',
                'message'       => 'ok',
            ];
        } catch (\Throwable $e) {
            Log::error('微信网页授权请求异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'openid' => '', 'unionid' => null, 'access_token' => '', 'refresh_token' => '', 'message' => '微信授权请求异常'];
        }
    }

    /**
     * 用 access_token 与 openid 获取用户信息（昵称、头像等）
     *
     * @param string $accessToken 网页授权 access_token
     * @param string $openid 用户 openid
     * @return array ['success' => bool, 'openid' => string, 'unionid' => string|null, 'nickname' => string, 'avatar' => string, 'message' => string]
     */
    public function getUserInfoByToken(string $accessToken, string $openid): array
    {
        if ($accessToken === '' || $openid === '') {
            return ['success' => false, 'openid' => '', 'unionid' => null, 'nickname' => '', 'avatar' => '', 'message' => '缺少 access_token 或 openid'];
        }

        try {
            $response = Http::withOptions(['verify' => $this->resolveVerify()])
                ->get('https://api.weixin.qq.com/sns/userinfo', [
                    'access_token' => $accessToken,
                    'openid'       => $openid,
                    'lang'         => 'zh_CN',
                ]);

            $result = $response->json();

            if (isset($result['errcode']) && (int) $result['errcode'] !== 0) {
                Log::error('微信获取用户信息失败', $result);
                return ['success' => false, 'openid' => '', 'unionid' => null, 'nickname' => '', 'avatar' => '', 'message' => $result['errmsg'] ?? '获取用户信息失败'];
            }

            return [
                'success'  => true,
                'openid'   => $result['openid'] ?? '',
                'unionid'  => $result['unionid'] ?? null,
                'nickname' => $result['nickname'] ?? '',
                'avatar'   => $result['headimgurl'] ?? '',
                'message'  => 'ok',
            ];
        } catch (\Throwable $e) {
            Log::error('微信获取用户信息异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'openid' => '', 'unionid' => null, 'nickname' => '', 'avatar' => '', 'message' => '获取用户信息异常'];
        }
    }

    /**
     * 解析 SSL 校验配置（兼容本地开发环境证书问题）
     */
    protected function resolveVerify(): bool
    {
        $verify = env('WECHAT_SSL_VERIFY', 'true');
        return filter_var($verify, FILTER_VALIDATE_BOOLEAN);
    }
}