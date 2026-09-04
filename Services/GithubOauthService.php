<?php

namespace App\Apps\CmsproSso\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GitHub OAuth2 服务
 *
 * 独立实现 GitHub OAuth App 授权能力，不依赖其他应用：
 * 1. buildOauthRedirectUrl()：生成 GitHub 授权跳转链接（read:user 作用域）
 * 2. getOpenidByOauthCode()：用授权 code 换取 access_token（GitHub 以用户数字 id 作为身份锚点）
 * 3. getUserInfoByToken()：用 access_token 获取昵称/头像
 */
class GithubOauthService
{
    public function __construct(
        protected SettingService $settingService
    ) {
    }

    /**
     * 生成 GitHub 授权跳转链接
     *
     * @param string $redirectUri 授权后回调地址（需 URL 编码）
     * @param string $state 自定义 state 参数，防 CSRF
     * @return array ['success' => bool, 'url' => string, 'message' => string]
     */
    public function buildOauthRedirectUrl(string $redirectUri, string $state = ''): array
    {
        $clientId = $this->settingService->get('github_client_id');

        if ($clientId === '') {
            return ['success' => false, 'url' => '', 'message' => 'GitHub Client ID 未配置'];
        }

        $url = 'https://github.com/login/oauth/authorize'
            . '?client_id=' . urlencode($clientId)
            . '&redirect_uri=' . urlencode($redirectUri)
            . '&scope=read:user';

        if ($state !== '') {
            $url .= '&state=' . urlencode($state);
        }

        return ['success' => true, 'url' => $url, 'message' => 'ok'];
    }

    /**
     * 用授权 code 换取 access_token 与用户标识
     *
     * GitHub token 响应用户标识需再调 /user 获取（数字 id），此处返回 access_token，
     * openid 由 getUserInfoByToken 阶段补全——为保持与其他平台两段式接口一致，
     * 本方法直接完成 token + /user 调用并返回数字 id 字符串作为 openid。
     *
     * @param string $code 授权 code（一次性，10 分钟有效）
     * @return array ['success' => bool, 'openid' => string, 'access_token' => string, 'message' => string]
     */
    public function getOpenidByOauthCode(string $code): array
    {
        $clientId = $this->settingService->get('github_client_id');
        $clientSecret = $this->settingService->get('github_client_secret');

        if ($clientId === '' || $clientSecret === '') {
            return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => 'GitHub Client ID/Client Secret 未配置'];
        }

        try {
            $tokenResponse = Http::withOptions(['verify' => $this->resolveVerify()])
                ->accept('application/json')
                ->asForm()
                ->post('https://github.com/login/oauth/access_token', [
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'code'          => $code,
                    'redirect_uri'  => $this->redirectUri(),
                ]);

            $accessToken = (string) ($tokenResponse->json('access_token') ?? '');

            if ($accessToken === '') {
                Log::error('GitHub换取 access_token 失败', $tokenResponse->json() ?? []);
                return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => $tokenResponse->json('error_description') ?? 'GitHub授权失败'];
            }

            // GitHub 授权流程不单独返回用户标识，取 /user 的数字 id 作为身份锚点
            $user = $this->fetchGithubUser($accessToken);
            $openid = (string) ($user['id'] ?? '');

            if ($openid === '') {
                Log::error('GitHub获取用户标识失败', ['response' => $user]);
                return ['success' => false, 'openid' => '', 'access_token' => $accessToken, 'message' => '获取GitHub用户标识失败'];
            }

            return [
                'success'      => true,
                'openid'       => $openid,
                'access_token' => $accessToken,
                'message'      => 'ok',
            ];
        } catch (\Throwable $e) {
            Log::error('GitHub授权请求异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => 'GitHub授权请求异常'];
        }
    }

    /**
     * 用 access_token 获取用户信息（昵称、头像等）
     *
     * @param string $accessToken 授权 access_token
     * @param string $openid 用户标识（GitHub 数字 id，本方法仅作参数形状对齐）
     * @return array ['success' => bool, 'nickname' => string, 'avatar' => string, 'message' => string]
     */
    public function getUserInfoByToken(string $accessToken, string $openid): array
    {
        if ($accessToken === '') {
            return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => '缺少 access_token'];
        }

        try {
            $user = $this->fetchGithubUser($accessToken);

            if (!isset($user['id'])) {
                Log::error('GitHub获取用户信息失败', ['response' => $user]);
                return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => '获取GitHub用户信息失败'];
            }

            return [
                'success'  => true,
                'nickname' => (string) ($user['name'] ?: ($user['login'] ?? '')),
                'avatar'   => (string) ($user['avatar_url'] ?? ''),
                'message'  => 'ok',
            ];
        } catch (\Throwable $e) {
            Log::error('GitHub获取用户信息异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => '获取用户信息异常'];
        }
    }

    /**
     * 调用 GitHub /user 接口获取用户数据
     */
    protected function fetchGithubUser(string $accessToken): array
    {
        $response = Http::withOptions(['verify' => $this->resolveVerify()])
            ->withToken($accessToken)
            ->accept('application/vnd.github+json')
            ->withUserAgent('CmsPro-SSO')
            ->get('https://api.github.com/user');

        return $response->json() ?? [];
    }

    /**
     * GitHub 授权回调地址（与授权跳转时使用的 redirect_uri 保持一致）
     */
    protected function redirectUri(): string
    {
        return ShareService::frontUrl('/auth/github/callback');
    }

    /**
     * 解析 SSL 校验配置（兼容本地开发环境证书问题）
     */
    protected function resolveVerify(): bool
    {
        return filter_var(env('GITHUB_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN);
    }
}
