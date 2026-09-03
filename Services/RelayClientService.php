<?php

namespace App\Apps\CmsproSso\Services;

use Illuminate\Support\Facades\Http;

/**
 * 快捷模式（社交登录中转）客户端服务
 *
 * 本站作为快捷模式客户端时，与中转服务端站点通信：
 *   - 拉取可中转的社交平台列表；
 *   - 构建服务端 authorize 授权跳转地址；
 *   - 以授权码换取访问令牌并获取服务端用户信息。
 *
 * relay_server_url 为服务端前台基础地址：
 *   - 服务端为路径前缀模式时填含前缀地址（如 http://www.cmspro.com/sso）；
 *   - 服务端为子域名模式时填域名地址（如 https://sso.example.com）。
 * 开放 API 固定挂于服务端 /api/cmspro/sso/oauth 前缀，按 server_url 的 host 推导。
 */
class RelayClientService
{
    /**
     * 与服务端通信超时时间（秒）
     */
    public const TIMEOUT = 10;

    public function __construct(
        protected SettingService $settingService
    ) {
    }

    /**
     * 当前站点是否已具备客户端中转条件（模式 + 服务端地址 + 凭证齐全）
     */
    public function isConfigured(): bool
    {
        return $this->settingService->get('quick_mode', 'standalone') === 'client'
            && $this->serverUrl() !== ''
            && $this->settingService->get('relay_client_id', '') !== ''
            && $this->settingService->get('relay_client_secret', '') !== '';
    }

    /**
     * 服务端前台基础地址（去除尾部斜杠）
     */
    public function serverUrl(): string
    {
        return rtrim(trim($this->settingService->get('relay_server_url', '')), '/');
    }

    /**
     * 服务端开放 API 基地址（scheme://host + 固定前缀，不跟随前台路径前缀）
     */
    public function apiBase(): string
    {
        $host = parse_url($this->serverUrl(), PHP_URL_HOST);
        if ($host === null || $host === '') {
            return '';
        }

        $scheme = parse_url($this->serverUrl(), PHP_URL_SCHEME) ?: 'https';

        return $scheme . '://' . $host . '/api/cmspro/sso/oauth';
    }

    /**
     * 本站中转回调地址（服务端授权后跳回）
     */
    public function callbackUrl(): string
    {
        return ShareService::frontUrl('/auth/relay/callback');
    }

    /**
     * 构建服务端 authorize 授权跳转地址
     */
    public function authorizeUrl(string $platform, string $state): string
    {
        $query = [
            'client_id'     => $this->settingService->get('relay_client_id', ''),
            'redirect_uri'  => $this->callbackUrl(),
            'response_type' => 'code',
            'state'         => $state,
        ];
        if ($platform !== '') {
            $query['platform'] = $platform;
        }

        return $this->serverUrl() . '/oauth/authorize?' . http_build_query($query);
    }

    /**
     * 拉取服务端可中转的社交平台列表
     *
     * @return array<int, array{platform: string, name: string}> 服务端未开放/凭证无效/通信失败时返回空数组
     */
    public function platforms(): array
    {
        $apiBase = $this->apiBase();
        if ($apiBase === '') {
            return [];
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get($apiBase . '/platforms', [
                    'client_id' => $this->settingService->get('relay_client_id', ''),
                ]);
        } catch (\Throwable) {
            return [];
        }

        if (!$response->successful()) {
            return [];
        }

        $body = $response->json();
        $platforms = $body['data']['platforms'] ?? [];

        return is_array($platforms) ? $platforms : [];
    }

    /**
     * 授权码换取访问令牌（服务器间调用服务端 token 端点）
     *
     * @return array{success: bool, message: string, access_token?: string}
     */
    public function exchangeCode(string $code): array
    {
        $apiBase = $this->apiBase();
        if ($apiBase === '') {
            return ['success' => false, 'message' => '中转服务端地址未配置'];
        }

        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post($apiBase . '/token', [
                'grant_type'    => 'authorization_code',
                'client_id'     => $this->settingService->get('relay_client_id', ''),
                'client_secret' => $this->settingService->get('relay_client_secret', ''),
                'code'          => $code,
                'redirect_uri'  => $this->callbackUrl(),
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '连接中转服务端失败：' . $e->getMessage()];
        }

        if (!$response->successful()) {
            $message = (string) ($response->json('message') ?? '授权码换取失败（HTTP ' . $response->status() . '）');

            return ['success' => false, 'message' => $message];
        }

        $accessToken = (string) ($response->json('data.access_token') ?? '');
        if ($accessToken === '') {
            return ['success' => false, 'message' => '中转服务端未返回访问令牌'];
        }

        return ['success' => true, 'message' => 'ok', 'access_token' => $accessToken];
    }

    /**
     * 凭访问令牌获取服务端用户信息
     *
     * @return array|null 失败返回 null；成功返回 {id, username, nickname, avatar, platform}
     */
    public function userinfo(string $accessToken): ?array
    {
        $apiBase = $this->apiBase();
        if ($apiBase === '') {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)->timeout(self::TIMEOUT)->get($apiBase . '/userinfo');
        } catch (\Throwable) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json('data');

        return is_array($data) && isset($data['id']) ? $data : null;
    }

    /**
     * 创建服务端 PC 扫码中转会话
     *
     * 调服务端 relay-qrcode 创建扫码会话（服务器间调用），
     * 返回 {session_id, qrcode_url, expires_in}，失败返回 null。
     *
     * @param string $platform 社交平台（wechat/qq），留空不限定平台
     * @return array{session_id: string, qrcode_url: string, expires_in: int}|null
     */
    public function createQrSession(string $platform = ''): ?array
    {
        $apiBase = $this->apiBase();
        if ($apiBase === '') {
            return null;
        }

        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post($apiBase . '/relay-qrcode', [
                'client_id'    => $this->settingService->get('relay_client_id', ''),
                'redirect_uri' => $this->callbackUrl(),
                'platform'     => $platform,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json('data');
        $sessionId = (string) ($data['session_id'] ?? '');
        $qrcodeUrl = (string) ($data['qrcode_url'] ?? '');

        if ($sessionId === '' || $qrcodeUrl === '') {
            return null;
        }

        return [
            'session_id' => $sessionId,
            'qrcode_url' => $qrcodeUrl,
            'expires_in' => (int) ($data['expires_in'] ?? 600),
        ];
    }

    /**
     * 拉取服务端扫码会话结果（一次性消费）
     *
     * 调服务端 relay-status：waiting/consumed 返回 null（继续轮询），
     * confirmed 返回 {code, platform}，failed 返回 {error}。
     *
     * @return array|null confirmed/failed 结果；waiting 或通信失败返回 null
     */
    public function pullQrResult(string $sessionId): ?array
    {
        $apiBase = $this->apiBase();
        if ($apiBase === '') {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->get($apiBase . '/relay-status', [
                'session_id' => $sessionId,
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json('data') ?? [];
        $status = (string) ($data['status'] ?? '');

        if ($status === 'confirmed') {
            return [
                'code'     => (string) ($data['code'] ?? ''),
                'platform' => (string) ($data['platform'] ?? ''),
            ];
        }

        if ($status === 'failed') {
            return ['error' => (string) ($data['error'] ?? '扫码授权失败')];
        }

        // waiting（未扫码）或 consumed（结果已被取走，服务端 relay-status 为一次性
        // 消费后回 consumed，客户端拉取结果后服务端已无结果可返回）
        return null;
    }
}
