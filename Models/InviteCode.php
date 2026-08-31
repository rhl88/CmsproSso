<?php

namespace App\Apps\CmsproSso\Models;

use App\Models\BaseModel;

/**
 * 统一登录邀请码模型
 *
 * status：0-禁用 1-未使用 2-已使用
 */
class InviteCode extends BaseModel
{
    protected $table = 'app_cmspro_sso_invite_codes';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    public const STATUS_DISABLED = 0;

    public const STATUS_UNUSED = 1;

    public const STATUS_USED = 2;

    protected $fillable = [
        'code',
        'status',
        'price',
        'used_by',
        'used_at',
    ];

    protected $casts = [
        'price'       => 'float',
        'used_at'     => 'datetime:Y-m-d H:i:s',
        'create_time' => 'datetime:Y-m-d H:i:s',
        'update_time' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * 状态文本
     */
    public function getStatusLabelAttribute(): string
    {
        return match ((int) $this->status) {
            self::STATUS_DISABLED => '禁用',
            self::STATUS_USED     => '已使用',
            default               => '未使用',
        };
    }

    /**
     * 生成唯一邀请码（大写字母 + 数字，排除易混淆字符）
     *
     * @throws \RuntimeException 生成失败（连续多次冲突）时抛出
     */
    public static function generateUniqueCode(int $length = 16): string
    {
        // 排除 0/O、1/I/L 等易混淆字符
        $pool = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $pool[random_int(0, strlen($pool) - 1)];
            }

            if (!self::where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('邀请码生成失败，请重试');
    }
}
