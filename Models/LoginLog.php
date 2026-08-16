<?php

namespace App\Apps\CmsproSso\Models;

use App\Models\BaseModel;

class LoginLog extends BaseModel
{
    protected $table = 'app_cmspro_sso_login_logs';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    protected $fillable = [
        'user_id',
        'platform',
        'ip',
        'user_agent',
        'status',
        'message',
    ];

    protected $casts = [
        'create_time' => 'datetime:Y-m-d H:i:s',
        'update_time' => 'datetime:Y-m-d H:i:s',
    ];
}
