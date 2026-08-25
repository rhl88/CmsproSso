<?php

namespace App\Apps\CmsproSso\Services;

use App\Apps\CmsproSso\Services\Sms\AbstractSmsSender;
use App\Apps\CmsproSso\Services\Sms\AliyunSmsSender;
use App\Apps\CmsproSso\Services\Sms\TencentSmsSender;
use App\Apps\CmsproSso\Services\Sms\VolcengineSmsSender;

/**
 * 短信发送门面服务
 *
 * 根据当前配置的短信服务商，调度对应发送器发送验证码短信，
 * 并对外提供「是否已配置」判断，供登录/发送验证码前检查。
 */
class SmsService
{
    public function __construct(
        protected SettingService $settingService
    ) {
    }

    /**
     * 发送验证码短信
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function sendVerificationCode(string $phone, string $code): array
    {
        return $this->sender()->send($phone, $code);
    }

    /**
     * 判断当前短信服务商所需凭证是否已完整配置
     */
    public function isConfigured(): bool
    {
        foreach ($this->requiredKeys($this->settingService->getSmsProvider()) as $key) {
            if (trim($this->settingService->get($key)) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * 根据服务商获取对应发送器
     */
    protected function sender(): AbstractSmsSender
    {
        return match ($this->settingService->getSmsProvider()) {
            'tencent'    => app(TencentSmsSender::class),
            'volcengine' => app(VolcengineSmsSender::class),
            default      => app(AliyunSmsSender::class),
        };
    }

    /**
     * 各服务商发送短信所需的必填配置项
     */
    protected function requiredKeys(string $provider): array
    {
        return match ($provider) {
            'tencent' => [
                'sms_tencent_secret_id', 'sms_tencent_secret_key',
                'sms_tencent_sdk_app_id', 'sms_tencent_sign_name', 'sms_tencent_template_id',
            ],
            'volcengine' => [
                'sms_volc_access_key_id', 'sms_volc_access_key_secret',
                'sms_volc_sms_account', 'sms_volc_sign_name', 'sms_volc_template_id',
            ],
            default => [
                'sms_aliyun_access_key_id', 'sms_aliyun_access_key_secret',
                'sms_aliyun_sign_name', 'sms_aliyun_template_code',
            ],
        };
    }
}