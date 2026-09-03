<?php

namespace App\Apps\CmsproSso\Services;

use App\Apps\CmsproSso\Models\AccessToken;
use App\Apps\CmsproSso\Models\AuthCode;
use App\Apps\CmsproSso\Models\Client;
use App\Enums\Status;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * 快捷模式（社交登录中转）OAuth 服务（服务端）
 *
 * 服务端以 OAuth2 授权码模式向客户端站点提供统一登录中转：
 *   authorize（浏览器跳转）→ 签发授权码 → 客户端以 code 换 access_token → 凭 token 取用户信息。
 *
 * 身份锚点：服务端用户 id。客户端以「sso + 服务端用户 id」建立本地映射，
 * 无论中转哪个社交平台，同一服务端账号在客户端始终对应同一本地账号。
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
     * 签发授权码（一次性，5 分钟有效）
     */
    public function issueAuthCode(Client $client, User $user, string $platform, string $redirectUri): string
    {
        $code = bin2hex(random_bytes(24));

        AuthCode::create([
            'code'         => $code,
            'client_id'    => $client->client_id,
            'user_id'      => $user->id,
            'platform'     => mb_substr($platform, 0, 20),
            'redirect_uri' => mb_substr($redirectUri, 0, 500),
            'expires_at'   => Carbon::now()->addSeconds(self::CODE_TTL),
        ]);

        return $code;
    }

    /**
     * 授权码换取访问令牌
     *
     * 校验链：客户端凭证 → 授权码存在/未过期/未使用/归属正确 → 回调地址一致。
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

        $user = User::find($authCode->user_id);
        if (!$user || $user->status !== Status::ENABLED) {
            return ['success' => false, 'message' => '用户不存在或已被禁用'];
        }

        $token = bin2hex(random_bytes(32));

        AccessToken::create([
            'token_hash' => AccessToken::hashToken($token),
            'client_id'  => $client->client_id,
            'user_id'    => $user->id,
            'platform'   => $authCode->platform,
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
     * 解析访问令牌（未过期、未吊销），返回关联用户与授权上下文
     *
     * @return array{user: User, client_id: string, platform: string}|null
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

        $user = User::find($record->user_id);
        if (!$user || $user->status !== Status::ENABLED) {
            return null;
        }

        return [
            'user'      => $user,
            'client_id' => $record->client_id,
            'platform'  => (string) $record->platform,
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
     * 构建用户信息（仅返回基础字段：id/用户名/昵称/头像/发起平台）
     */
    public function userinfoData(User $user, string $platform): array
    {
        return [
            'id'       => $user->id,
            'username' => (string) $user->username,
            'nickname' => (string) ($user->nickname ?? ''),
            'avatar'   => (string) ($user->avatar ?? ''),
            'platform' => $platform,
        ];
    }

    // ---------------------------------------------------------------- 中转扫码会话（客户端 PC 扫码中转）

    /**
     * 中转扫码会话 Cache 键前缀
     *
     * 会话内容为客户端 authorize 回调地址（redirect_uri），创建时校验白名单；
     * 社交授权完成后据此跳回客户端并附带授权码（PC 轮询端凭会话 ID 换取登录结果）。
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
