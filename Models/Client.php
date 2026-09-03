<?php

namespace App\Apps\CmsproSso\Models;

use App\Models\BaseModel;
use Illuminate\Support\Facades\DB;

/**
 * 快捷模式客户端模型（服务端）
 *
 * 每条记录代表一个接入统一登录中转的第三方（客户端）站点，
 * 凭 client_id + client_secret 走 OAuth2 授权码流程获取用户信息。
 */
class Client extends BaseModel
{
    protected $table = 'app_cmspro_sso_clients';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    public const STATUS_DISABLED = 0;

    public const STATUS_ENABLED = 1;

    protected $fillable = [
        'client_id',
        'secret_hash',
        'name',
        'redirect_uris',
        'status',
        'remark',
    ];

    protected $casts = [
        'create_time' => 'datetime:Y-m-d H:i:s',
        'update_time' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * 回调地址白名单（拆分为数组，去除空行）
     */
    public function redirectUriList(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $this->redirect_uris))));
    }

    /**
     * 生成唯一客户端ID（sso_ + 16位随机十六进制）
     */
    public static function generateClientId(): string
    {
        do {
            $clientId = 'sso_' . bin2hex(random_bytes(8));
        } while (self::where('client_id', $clientId)->exists());

        return $clientId;
    }

    /**
     * 生成客户端密钥（64位随机十六进制，明文仅返回一次，库存哈希）
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 校验密钥明文与存储哈希是否匹配
     */
    public function verifySecret(string $secret): bool
    {
        return password_verify($secret, $this->secret_hash);
    }

    /**
     * 删除客户端时同步清理授权码与访问令牌
     */
    public function deleteWithRelations(): void
    {
        DB::transaction(function () {
            AuthCode::where('client_id', $this->client_id)->delete();
            AccessToken::where('client_id', $this->client_id)->delete();
            $this->delete();
        });
    }
}
