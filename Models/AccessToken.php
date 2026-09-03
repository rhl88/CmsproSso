<?php

namespace App\Apps\CmsproSso\Models;

use App\Models\BaseModel;

/**
 * 快捷模式访问令牌模型（服务端）
 *
 * 不透明令牌：库内存 SHA256 哈希，过期或吊销后失效。
 */
class AccessToken extends BaseModel
{
    protected $table = 'app_cmspro_sso_access_tokens';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    protected $fillable = [
        'token_hash',
        'client_id',
        'user_id',
        'platform',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime:Y-m-d H:i:s',
        'revoked_at'  => 'datetime:Y-m-d H:i:s',
        'create_time' => 'datetime:Y-m-d H:i:s',
        'update_time' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * 令牌明文的 SHA256 哈希（库存此值，明文仅签发时返回一次）
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
