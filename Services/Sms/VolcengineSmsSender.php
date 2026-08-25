<?php

namespace App\Apps\CmsproSso\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 火山引擎短信发送器（sms SendSms）
 *
 * 采用 HMAC-SHA256（SigV4 风格）签名，POST application/json 调用 sms.volcengineapi.com。
 */
class VolcengineSmsSender extends AbstractSmsSender
{
    protected const HOST = 'sms.volcengineapi.com';
    protected const SERVICE = 'volcSMS';
    protected const REGION = 'cn-north-1';
    protected const VERSION = '2020-01-01';

    public function send(string $phone, string $code): array
    {
        $accessKeyId = trim($this->settingService->get('sms_volc_access_key_id'));
        $accessKeySecret = trim($this->settingService->get('sms_volc_access_key_secret'));
        $smsAccount = trim($this->settingService->get('sms_volc_sms_account'));
        $signName = trim($this->settingService->get('sms_volc_sign_name'));
        $templateId = trim($this->settingService->get('sms_volc_template_id'));

        $payload = json_encode([
            'SmsAccount'    => $smsAccount,
            'Sign'          => $signName,
            'TemplateID'    => $templateId,
            'TemplateParam' => json_encode(['code' => $code], JSON_UNESCAPED_UNICODE),
            'PhoneNumbers'  => $phone,
        ], JSON_UNESCAPED_UNICODE);

        $xDate = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');
        $authorization = $this->sign($payload, $accessKeyId, $accessKeySecret, $xDate, $shortDate);

        try {
            $response = Http::withOptions(['verify' => $this->verify()])
                ->withHeaders([
                    'Authorization'    => $authorization,
                    'X-Date'           => $xDate,
                    'X-Content-Sha256' => hash('sha256', $payload),
                ])
                ->withBody($payload, 'application/json')
                ->post('https://' . self::HOST . '?Action=SendSms&Version=' . self::VERSION);

            $result = $response->json();

            if (isset($result['ResponseMetadata']['Error'])) {
                $error = $result['ResponseMetadata']['Error'];
                Log::error('火山引擎短信发送失败', $error);
                return ['success' => false, 'message' => $error['Message'] ?? '火山引擎短信发送失败'];
            }

            return ['success' => true, 'message' => 'ok'];
        } catch (\Throwable $e) {
            Log::error('火山引擎短信请求异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '火山引擎短信请求异常'];
        }
    }

    /**
     * 计算 HMAC-SHA256（SigV4 风格）签名
     */
    protected function sign(string $payload, string $accessKeyId, string $accessKeySecret, string $xDate, string $shortDate): string
    {
        $hashedPayload = hash('sha256', $payload);

        $canonicalQuery = 'Action=SendSms&Version=' . self::VERSION;

        $canonicalHeaders = "content-type:application/json\n"
            . 'host:' . self::HOST . "\n"
            . 'x-content-sha256:' . $hashedPayload . "\n"
            . 'x-date:' . $xDate . "\n";

        $signedHeaders = 'content-type;host;x-content-sha256;x-date';

        $canonicalRequest = "POST\n"
            . "/\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $hashedPayload;

        $credentialScope = $shortDate . '/' . self::REGION . '/' . self::SERVICE . '/request';

        $stringToSign = "HMAC-SHA256\n"
            . $xDate . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $shortDate, $accessKeySecret, true);
        $kRegion = hash_hmac('sha256', self::REGION, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return 'HMAC-SHA256 '
            . 'Credential=' . $accessKeyId . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;
    }
}