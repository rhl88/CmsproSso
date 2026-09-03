<?php

namespace App\Apps\CmsproSso\Models;

use App\Models\BaseModel;

/**
 * 快捷模式授权码模型（服务端）
 *
 * 一次性使用，签发后 5 分钟有效，换取访问令牌后标记 used_at。
 */
class AuthCode extends BaseModel
{
    protected $table = 'app_cmspro_sso_auth_codes';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    protected $fillable = [
        'code',
        'client_id',
        'user_id',
        'platform',
        'redirect_uri',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime:Y-m-d H:i:s',
        'used_at'     => 'datetime:Y-m-d H:i:s',
        'create_time' => 'datetime:Y-m-d H:i:s',
        'update_time' => 'datetime:Y-m-d H:i:s',
    ];
}
