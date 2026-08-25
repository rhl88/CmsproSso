<?php

namespace App\Apps\CmsproSso\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 阿里云短信发送器（dysmsapi SendSms）
 *
 * 采用 RPC 风格签名（HMAC-SHA1，SignatureVersion 1.0），
 * 通过 GET 请求将签名与业务参数放入 query 调用 dysmsapi.aliyuncs.com。
 */
class AliyunSmsSender extends AbstractSmsSender
{
    protected const ENDPOINT = 'https://dysmsapi.aliyuncs.com';

    public function send(string $phone, string $code): array
    {
        $accessKeyId = trim($this->settingService->get('sms_aliyun_access_key_id'));
        $accessKeySecret = trim($this->settingService->get('sms_aliyun_access_key_secret'));
        $signName = trim($this->settingService->get('sms_aliyun_sign_name'));
        $templateCode = trim($this->settingService->get('sms_aliyun_template_code'));

        $params = [
            'Action'           => 'SendSms',
            'Version'          => '2017-05-25',
            'RegionId'         => 'cn-hangzhou',
            'PhoneNumbers'     => $phone,
            'SignName'         => $signName,
            'TemplateCode'     => $templateCode,
            'TemplateParam'    => json_encode(['code' => $code], JSON_UNESCAPED_UNICODE),
            'Format'           => 'JSON',
            'AccessKeyId'      => $accessKeyId,
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce'   => bin2hex(random_bytes(16)),
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $params['Signature'] = $this->sign($params, $accessKeySecret);

        try {
            $response = Http::withOptions(['verify' => $this->verify()])
                ->get(self::ENDPOINT, $params);

            $result = $response->json();

            if (($result['Code'] ?? '') !== 'OK') {
                Log::error('阿里云短信发送失败', $result ?? []);
                return ['success' => false, 'message' => $result['Message'] ?? '阿里云短信发送失败'];
            }

            return ['success' => true, 'message' => 'ok'];
        } catch (\Throwable $e) {
            Log::error('阿里云短信请求异常', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '阿里云短信请求异常'];
        }
    }

    /**
     * 计算 RPC 签名（HMAC-SHA1）
     */
    protected function sign(array $params, string $accessKeySecret): string
    {
        ksort($params);

        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $this->percentEncode($key) . '=' . $this->percentEncode((string) $value);
        }
        $canonicalizedQueryString = implode('&', $parts);

        $stringToSign = 'GET&' . $this->percentEncode('/') . '&' . $this->percentEncode($canonicalizedQueryString);

        return base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
    }

    /**
     * 阿里云 POP 规范的百分号编码（保留 A-Z a-z 0-9 - _ . ~，其余转大写十六进制）
     */
    protected function percentEncode(string $str): string
    {
        $result = '';
        $length = strlen($str);

        for ($i = 0; $i < $length; $i++) {
            $char = $str[$i];
            if (preg_match('/[A-Za-z0-9\-_.~]/', $char) === 1) {
                $result .= $char;
            } else {
                $result .= '%' . strtoupper(bin2hex($char));
            }
        }

        return $result;
    }
}