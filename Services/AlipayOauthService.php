<?php

namespace App\Apps\CmsproSso\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 支付宝 OAuth2 服务（支付宝登录）
 *
 * 独立实现支付宝开放平台网页授权能力，不依赖其他应用：
 * 1. buildOauthRedirectUrl()：生成支付宝授权跳转链接（auth_user 作用域）
 * 2. getOpenidByOauthCode()：用授权 code 换取 access_token 与 user_id（RSA2 签名请求）
 * 3. getUserInfoByToken()：用 access_token 获取昵称/头像
 *
 * 签名说明：请求参数按 key 升序拼接后以应用私钥做 SHA256withRSA（RSA2）签名；
 * 服务端响应经 HTTPS 传输，此处直接按业务码判定结果，不做公钥验签。
 */
class AlipayOauthService
{
    /**
     * 支付宝开放平台网关地址
     */
    protected const GATEWAY = 'https://openapi.alipay.com/gateway.do';

    public function __construct(
        protected SettingService $settingService
    ) {
    }

    /**
     * 生成支付宝授权跳转链接
     *
     * @param string $redirectUri 授权后回调地址（需 URL 编码）
     * @param string $state 自定义 state 参数，防 CSRF
     * @return array ['success' => bool, 'url' => string, 'message' => string]
     */
    public function buildOauthRedirectUrl(string $redirectUri, string $state = ''): array
    {
        $appId = $this->settingService->get('alipay_app_id');

        if ($appId === '') {
            return ['success' => false, 'url' => '', 'message' => '支付宝 AppID 未配置'];
        }

        $url = 'https://openauth.alipay.com/oauth2/publicAppAuthorize.htm'
            . '?app_id=' . urlencode($appId)
            . '&scope=auth_user'
            . '&redirect_uri=' . urlencode($redirectUri);

        if ($state !== '') {
            $url .= '&state=' . urlencode($state);
        }

        return ['success' => true, 'url' => $url, 'message' => 'ok'];
    }

    /**
     * 用授权 code 换取 access_token 与 user_id
     *
     * @param string $code 授权 code（一次性）
     * @return array ['success' => bool, 'openid' => string, 'access_token' => string, 'message' => string]
     */
    public function getOpenidByOauthCode(string $code): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => '支付宝 AppID/应用私钥 未配置'];
        }

        try {
            $params = [
                'app_id'     => $this->settingService->get('alipay_app_id'),
                'method'     => 'alipay.system.oauth.token',
                'format'     => 'JSON',
                'charset'    => 'utf-8',
                'sign_type'  => 'RSA2',
                'timestamp'  => date('Y-m-d H:i:s'),
                'version'    => '1.0',
                'grant_type' => 'authorization_code',
                'code'       => $code,
            ];
            $params['sign'] = $this->sign($params);

            $result = $this->postGateway($params);

            // 成功响应：{alipay_system_oauth_token_response: {access_token, user_id}}
            if (isset($result['alipay_system_oauth_token_response'])) {
                $token = $result['alipay_system_oauth_token_response'];
                $accessToken = (string) ($token['access_token'] ?? '');
                $openId = (string) ($token['user_id'] ?? '');

                if ($accessToken === '' || $openId === '') {
                    Log::error('支付宝换取 access_token 返回为空', ['response' => $token]);
                    return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => '支付宝授权失败'];
                }

                return [
                    'success'      => true,
                    'openid'       => $openId,
                    'access_token' => $accessToken,
                    'message'      => 'ok',
                ];
            }

            // 错误响应：{error_response: {code, sub_msg, ...}}
            Log::error('支付宝换取 access_token 失败', $result);
            return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => $this->errorMessage($result)];
        } catch (\Throwable $e) {
            Log::error('支付宝授权请求异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'openid' => '', 'access_token' => '', 'message' => '支付宝授权请求异常'];
        }
    }

    /**
     * 用 access_token 获取用户信息（昵称、头像等）
     *
     * @param string $accessToken 授权 access_token
     * @param string $openid 用户 user_id（本方法仅作参数形状对齐）
     * @return array ['success' => bool, 'nickname' => string, 'avatar' => string, 'message' => string]
     */
    public function getUserInfoByToken(string $accessToken, string $openid): array
    {
        if ($accessToken === '') {
            return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => '缺少 access_token'];
        }

        try {
            $params = [
                'app_id'     => $this->settingService->get('alipay_app_id'),
                'method'     => 'alipay.user.info.share',
                'format'     => 'JSON',
                'charset'    => 'utf-8',
                'sign_type'  => 'RSA2',
                'timestamp'  => date('Y-m-d H:i:s'),
                'version'    => '1.0',
                'auth_token' => $accessToken,
            ];
            $params['sign'] = $this->sign($params);

            $result = $this->postGateway($params);

            // 成功响应：{alipay_user_info_share_response: {code: "10000", nick_name, avatar, ...}}
            if (isset($result['alipay_user_info_share_response'])) {
                $info = $result['alipay_user_info_share_response'];

                if ((string) ($info['code'] ?? '10000') !== '10000') {
                    Log::error('支付宝获取用户信息失败', ['response' => $info]);
                    return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => $info['sub_msg'] ?? '获取用户信息失败'];
                }

                return [
                    'success'  => true,
                    'nickname' => (string) ($info['nick_name'] ?? ''),
                    'avatar'   => (string) ($info['avatar'] ?? ''),
                    'message'  => 'ok',
                ];
            }

            Log::error('支付宝获取用户信息失败', $result);
            return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => $this->errorMessage($result)];
        } catch (\Throwable $e) {
            Log::error('支付宝获取用户信息异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'nickname' => '', 'avatar' => '', 'message' => '获取用户信息异常'];
        }
    }

    /**
     * 支付宝凭证是否已配置（AppID + 应用私钥）
     */
    public function isConfigured(): bool
    {
        return $this->settingService->get('alipay_app_id') !== ''
            && $this->settingService->get('alipay_private_key') !== '';
    }

    /**
     * 请求支付宝网关并解析 JSON 响应
     */
    protected function postGateway(array $params): array
    {
        $response = Http::withOptions(['verify' => $this->resolveVerify()])
            ->asForm()
            ->post(self::GATEWAY, $params);

        return $response->json() ?? [];
    }

    /**
     * RSA2（SHA256withRSA）签名
     *
     * 参数按 key 升序拼接为 k=v&k=v（排除 sign 与空值），用应用私钥签名后 base64 编码。
     */
    protected function sign(array $params): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($key === 'sign' || $value === null || $value === '') {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        $data = implode('&', $pairs);
        $privateKey = openssl_pkey_get_private($this->formatPrivateKey($this->settingService->get('alipay_private_key')));
        if ($privateKey === false) {
            throw new \RuntimeException('支付宝应用私钥格式无效');
        }

        openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * 将原始 base64 私钥包装为 PEM 格式（已含 PEM 头尾时原样返回）
     */
    protected function formatPrivateKey(string $key): string
    {
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        return "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap(trim($key), 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
    }

    /**
     * 从网关错误响应中提取可读错误信息
     */
    protected function errorMessage(array $result): string
    {
        $error = $result['error_response'] ?? [];

        return (string) ($error['sub_msg'] ?? $error['msg'] ?? '支付宝授权失败');
    }

    /**
     * 支付宝授权回调地址（与授权跳转时使用的 redirect_uri 保持一致）
     */
    protected function redirectUri(): string
    {
        return ShareService::frontUrl('/auth/alipay/callback');
    }

    /**
     * 解析 SSL 校验配置（兼容本地开发环境证书问题）
     */
    protected function resolveVerify(): bool
    {
        return filter_var(env('ALIPAY_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN);
    }
}
