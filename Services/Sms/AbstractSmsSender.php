<?php

namespace App\Apps\CmsproSso\Services\Sms;

use App\Apps\CmsproSso\Services\SettingService;

/**
 * 短信发送器抽象基类
 *
 * 各短信服务商（阿里云/腾讯云/火山引擎）的实现均继承本类，
 * 统一注入配置服务与 SSL 校验开关，对外暴露 send() 发送验证码短信。
 */
abstract class AbstractSmsSender
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
    abstract public function send(string $phone, string $code): array;

    /**
     * 解析 SSL 证书校验开关（兼容本地开发环境证书问题）
     */
    protected function verify(): bool
    {
        $verify = env('SMS_SSL_VERIFY', 'true');

        return filter_var($verify, FILTER_VALIDATE_BOOLEAN);
    }
}