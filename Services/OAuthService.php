<?php

namespace App\Apps\CmsproSso\Services;

use App\Apps\CmsproSso\Models\AccessToken;
use App\Apps\CmsproSso\Models\AuthCode;
use App\Apps\CmsproSso\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * 快捷模式（社交登录中转）OAuth 服务（服务端）
 *
 * 服务端以 OAuth2 授权码模式向客户端站点提供统一登录中转：
 *   社交平台授权回调 → 暂存社交授权数据 → 签发授权码 → 客户端以 code 换 access_token
 *   → 凭 token 取社交授权数据（openid/unionid/nickname/avatar）。
 *
 * 纯跳板透传：服务端仅代跳社交授权，不建账号、不绑定用户、不落任何用户数据，
 * 授权码与访问令牌均不关联服务端用户（user_id 为空），身份锚点为社交平台真实 openid，
 * 由客户端站点以透传的 openid 建号登录。
 */
class OAuthService
{
    /**
     * 授权码有效期（秒）：一次性使用，5 分钟内有效
     */
    public const CODE_TTL = 300;

    /**
     * 访问令牌有效期（秒）：签发后 1 小时内可调用户信息接口
     */
    public const TOKEN_TTL = 3600;

    /**
     * 中转扫码会话有效期（秒）：客户端创建会话后 10 分钟内完成扫码
     */
    public const RELAY_QR_TTL = 600;

    /**
     * 社交授权数据暂存有效期（秒）：授权回调到客户端 code 换取之间的暂存窗口
     */
    public const OAUTH_DATA_TTL = 600;

    /**
     * 可中转的社交平台定义（后续新增平台在此扩展 + 配置组）
     */
    public const PLATFORMS = [
        'wechat' => ['name' => '微信'],
        'qq'     => ['name' => 'QQ'],
    ];

    public function __construct(
        protected SettingService $settingService
    ) {
    }

    /**
     * 社交授权数据暂存 Cache 键前缀（凭随机 token 一次性取回，随授权码透传给客户端）
     */
    public const OAUTH_DATA_PREFIX = 'cmspro_sso_oauth_grant:';

    /**
     * 当前站点是否为快捷模式服务端
     */
    public function isServerEnabled(): bool
    {
        return $this->settingService->get('quick_mode', 'standalone') === 'server';
    }

    /**
     * 当前站点是否为快捷模式客户端
     */
    public function isClientEnabled(): bool
    {
        return $this->settingService->get('quick_mode', 'standalone') === 'client';
    }

    /**
     * 获取可中转的社交平台列表（已启用且凭证已配置）
     *
     * @return array<int, array{platform: string, name: string}>
     */
    public function relayPlatforms(): array
    {
        $platforms = [];
        foreach (self::PLATFORMS as $platform => $meta) {
            if ($this->settingService->get($platform . '_enabled', '0') !== '1') {
                continue;
            }

            $configured = $platform === 'qq'
                ? $this->settingService->isQqConfigured()
                : $this->settingService->isWechatConfigured();
            if (!$configured) {
                continue;
            }

            $platforms[] = ['platform' => $platform, 'name' => $meta['name']];
        }

        return $platforms;
    }

    /**
     * 校验平台是否可中转
     */
    public function isPlatformAvailable(string $platform): bool
    {
        return in_array($platform, array_column($this->relayPlatforms(), 'platform'), true);
    }

    /**
     * 按客户端ID查找启用状态的客户端
     */
    public function findEnabledClient(string $clientId): ?Client
    {
        return Client::where('client_id', $clientId)
            ->where('status', Client::STATUS_ENABLED)
            ->first();
    }

    /**
     * 校验回调地址是否在客户端白名单内（精确匹配，防开放重定向）
     */
    public function isRedirectUriAllowed(Client $client, string $redirectUri): bool
    {
        return in_array($redirectUri, $client->redirectUriList(), true);
    }

    /**
     * 暂存社交授权数据并签发关联授权码（一次性，5 分钟有效）
     *
     * 纯跳板透传：社交授权数据（openid/nickname/avatar 等）暂存 Cache，授权码不关联
     * 服务端用户（user_id 为空），客户端以 code 换 token 后凭暂存键取回社交数据。
     *
     * @param Client $client 客户端
     * @param string $dataToken 社交授权数据暂存键（调用方先 putGrantData 写入）
     * @param string $platform 平台标识（wechat/qq）
     * @param string $redirectUri 授权码绑定的回调地址
     */
    public function issueAuthCode(Client $client, string $dataToken, string $platform, string $redirectUri): string
    {
        $code = bin2hex(random_bytes(24));

        AuthCode::create([
            'code'         => $code,
            'client_id'    => $client->client_id,
            'user_id'      => null,
            'platform'     => mb_substr($platform, 0, 20),
            'redirect_uri' => mb_substr($redirectUri, 0, 500),
            'expires_at'   => Carbon::now()->addSeconds(self::CODE_TTL),
        ]);

        // 授权码 → 暂存数据键映射（code 换 token 时定位社交授权数据）
        Cache::put(self::OAUTH_DATA_PREFIX . $code, $dataToken, self::CODE_TTL);

        return $code;
    }

    /**
     * 暂存社交授权数据（社交回调写入，客户端 code 换 token 后凭令牌读取）
     *
     * @param array $data 社交授权数据（openid/unionid/nickname/avatar/access_token 等）
     * @return string 暂存键（随机 token）
     */
    public function putGrantData(array $data): string
    {
        $dataToken = bin2hex(random_bytes(16));
        Cache::put(self::OAUTH_DATA_PREFIX . 'data:' . $dataToken, $data, self::OAUTH_DATA_TTL);

        return $dataToken;
    }

    /**
     * 取回并消费社交授权数据（一次性）
     */
    public function pullGrantData(string $dataToken): ?array
    {
        if ($dataToken === '') {
            return null;
        }

        $data = Cache::pull(self::OAUTH_DATA_PREFIX . 'data:' . $dataToken);

        return is_array($data) ? $data : null;
    }

    /**
     * 授权码换取访问令牌
     *
     * 校验链：客户端凭证 → 授权码存在/未过期/未使用/归属正确 → 回调地址一致。
     * 纯跳板透传：令牌不关联服务端用户，仅绑定社交授权数据暂存键。
     *
     * @return array{success: bool, message: string, access_token?: string, token_type?: string, expires_in?: int}
     */
    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri): array
    {
        $client = $this->findEnabledClient($clientId);
        if (!$client || !$client->verifySecret($clientSecret)) {
            return ['success' => false, 'message' => '客户端凭证无效'];
        }

        $authCode = AuthCode::where('code', $code)->first();
        if (!$authCode) {
            return ['success' => false, 'message' => '授权码无效'];
        }
        if ($authCode->used_at !== null) {
            return ['success' => false, 'message' => '授权码已被使用'];
        }
        if (Carbon::now()->gt(Carbon::parse($authCode->expires_at))) {
            return ['success' => false, 'message' => '授权码已过期'];
        }
        if ($authCode->client_id !== $client->client_id) {
            return ['success' => false, 'message' => '授权码与客户端不匹配'];
        }
        if ($authCode->redirect_uri !== $redirectUri) {
            return ['success' => false, 'message' => '回调地址与授权请求不一致'];
        }

        // 标记授权码已消费（一次性）
        $authCode->update(['used_at' => Carbon::now()]);

        // 取回社交授权数据暂存键（code → 数据键映射与授权码同期过期）
        $dataToken = (string) Cache::pull(self::OAUTH_DATA_PREFIX . $code);
        if ($dataToken === '') {
            return ['success' => false, 'message' => '社交授权数据已过期，请重新授权'];
        }

        $token = bin2hex(random_bytes(32));

        AccessToken::create([
            'token_hash' => AccessToken::hashToken($token),
            'client_id'  => $client->client_id,
            'user_id'    => null,
            'platform'   => $authCode->platform,
            'data_token' => $dataToken,
            'expires_at' => Carbon::now()->addSeconds(self::TOKEN_TTL),
        ]);

        return [
            'success'      => true,
            'message'      => 'ok',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => self::TOKEN_TTL,
        ];
    }

    /**
     * 解析访问令牌（未过期、未吊销），返回关联客户端与平台上下文
     *
     * 纯跳板透传：令牌不关联服务端用户，社交授权数据凭 data_token 暂存键读取。
     *
     * @return array{data_token: string, client_id: string, platform: string}|null
     */
    public function resolveToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $record = AccessToken::where('token_hash', AccessToken::hashToken($token))->first();
        if (!$record || $record->revoked_at !== null) {
            return null;
        }
        if (Carbon::now()->gt(Carbon::parse($record->expires_at))) {
            return null;
        }

        return [
            'data_token' => (string) ($record->data_token ?? ''),
            'client_id'  => $record->client_id,
            'platform'   => (string) $record->platform,
        ];
    }

    /**
     * 吊销访问令牌
     */
    public function revokeToken(string $token): bool
    {
        $record = AccessToken::where('token_hash', AccessToken::hashToken($token))->first();
        if (!$record || $record->revoked_at !== null) {
            return false;
        }

        $record->update(['revoked_at' => Carbon::now()]);

        return true;
    }

    /**
     * 构建社交授权数据响应（透传社交平台真实 openid 与用户信息）
     *
     * @param string $dataToken 访问令牌携带的社交授权数据暂存键
     */
    public function userinfoData(string $dataToken): array
    {
        $data = $this->pullGrantData($dataToken);
        if ($data === null) {
            return [];
        }

        return [
            'openid'        => (string) ($data['openid'] ?? ''),
            'unionid'       => (string) ($data['unionid'] ?? ''),
            'nickname'      => (string) ($data['nickname'] ?? ''),
            'avatar'        => (string) ($data['avatar'] ?? ''),
            'access_token'  => (string) ($data['access_token'] ?? ''),
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'platform'      => (string) ($data['platform'] ?? ''),
            'raw_data'      => $data['raw_data'] ?? null,
        ];
    }

    // ---------------------------------------------------------------- 中转扫码会话（客户端 PC 扫码中转）

    /**
     * 中转扫码会话 Cache 键前缀
     *
     * 会话内容为客户端 client_id 与 authorize 回调地址（redirect_uri），创建时校验白名单；
     * 社交授权回调据此签发授权码写入会话，客户端 PC 端凭会话 ID 轮询取回登录结果。
     */
    public const RELAY_QR_SESSION_PREFIX = 'cmspro_sso_relay_qr:';

    /**
     * 创建中转扫码会话
     *
     * 客户端站点发起 PC 扫码中转时调用：校验客户端与回调地址后登记会话，
     * 会话值保存客户端回调地址（扫码确认后 302 跳回）。
     *
     * @return string|null 会话 ID（32 位随机串），客户端或回调地址无效时返回 null
     */
    public function createRelayQrSession(string $clientId, string $redirectUri): ?string
    {
        $client = $this->findEnabledClient($clientId);
        if (!$client || $redirectUri === '' || !$this->isRedirectUriAllowed($client, $redirectUri)) {
            return null;
        }

        $sessionId = bin2hex(random_bytes(16));
        Cache::put(self::RELAY_QR_SESSION_PREFIX . $sessionId, [
            'client_id'    => $client->client_id,
            'redirect_uri' => $redirectUri,
        ], self::RELAY_QR_TTL);

        return $sessionId;
    }

    /**
     * 读取中转扫码会话（不消费，供 authorize 回调与轮询接口共用）
     */
    public function getRelayQrSession(string $sessionId): ?array
    {
        if ($sessionId === '') {
            return null;
        }

        $session = Cache::get(self::RELAY_QR_SESSION_PREFIX . $sessionId);

        return is_array($session) ? $session : null;
    }

    /**
     * 写入中转扫码会话登录结果（社交授权完成后由回调写入，PC 端轮询读取）
     */
    public function putRelayQrResult(string $sessionId, array $result): void
    {
        Cache::put(self::RELAY_QR_SESSION_PREFIX . $sessionId . ':result', $result, self::RELAY_QR_TTL);
    }

    /**
     * 读取并消费中转扫码会话登录结果（一次性，PC 端轮询到已登录后取回授权码）
     */
    public function pullRelayQrResult(string $sessionId): ?array
    {
        $result = Cache::pull(self::RELAY_QR_SESSION_PREFIX . $sessionId . ':result');

        return is_array($result) ? $result : null;
    }
}
