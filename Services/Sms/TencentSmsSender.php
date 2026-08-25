<?php

namespace App\Apps\CmsproSso\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 腾讯云短信发送器（sms SendSms）
 *
 * 采用 TC3-HMAC-SHA256 签名，POST application/json 调用 sms.tencentcloudapi.com。
 */
class TencentSmsSender extends AbstractSmsSender
{
    protected const HOST = 'sms.tencentcloudapi.com';
    protected const SERVICE = 'sms';
    protected const VERSION = '2021-01-11';

    public function send(string $phone, string $code): array
    {
        $secretId = trim($this->settingService->get('sms_tencent_secret_id'));
        $secretKey = trim($this->settingService->get('sms_tencent_secret_key'));
        $sdkAppId = trim($this->settingService->get('sms_tencent_sdk_app_id'));
        $signName = trim($this->settingService->get('sms_tencent_sign_name'));
        $templateId = trim($this->settingService->get('sms_tencent_template_id'));

        $payload = json_encode([
            'PhoneNumberSet'   => [$phone],
            'SmsSdkAppId'      => $sdkAppId,
            'SignName'         => $signName,
            'TemplateId'       => $templateId,
            'TemplateParamSet' => [$code],
        ], JSON_UNESCAPED_UNICODE);

        $timestamp = (string) time();
        $date = gmdate('Y-m-d', (int) $timestamp);
        $authorization = $this->sign($payload, $secretId, $secretKey, $timestamp, $date);

        try {
            $response = Http::withOptions(['verify' => $this->verify()])
                ->withHeaders([
                    'Authorization'  => $authorization,
                    'X-TC-Action'    => 'SendSms',
                    'X-TC-Version'   => self::VERSION,
                    'X-TC-Timestamp' => $timestamp,
                    'X-TC-Region'    => 'ap-guangzhou',
                ])
                ->withBody($payload, 'application/json; charset=utf-8')
                ->post('https://' . self::HOST);

            $result = $response->json();

            if (isset($result['Response']['Error'])) {
                $error = $result['Response']['Error'];
                Log::error('腾讯云短信发送失败', $error);
                return ['success' => false, 'message' => $error['Message'] ?? '腾讯云短信发送失败'];
            }

            return ['success' => true, 'message' => 'ok'];
        } catch (\Throwable $e) {
            Log::error('腾讯云短信请求异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '腾讯云短信请求异常'];
        }
    }

    /**
     * 计算 TC3-HMAC-SHA256 签名
     */
    protected function sign(string $payload, string $secretId, string $secretKey, string $timestamp, string $date): string
    {
        $hashedPayload = hash('sha256', $payload);

        $canonicalRequest = "POST\n"
            . "/\n"
            . "\n"
            . "content-type:application/json; charset=utf-8\n"
            . 'host:' . self::HOST . "\n"
            . "\n"
            . "content-type;host\n"
            . $hashedPayload;

        $credentialScope = $date . '/' . self::SERVICE . '/tc3_request';

        $stringToSign = "TC3-HMAC-SHA256\n"
            . $timestamp . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $secretDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('sha256', self::SERVICE, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);

        return 'TC3-HMAC-SHA256 '
            . 'Credential=' . $secretId . '/' . $credentialScope
            . ', SignedHeaders=content-type;host'
            . ', Signature=' . $signature;
    }
}