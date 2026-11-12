<?php

namespace App\Apps\CmsproSso\Services\Sms;

use App\Apps\NiurenSmssend\Exceptions\SmsException;
use App\Apps\NiurenSmssend\Services\SendService;
use App\Apps\NiurenSmssend\Services\TemplateService;
use Illuminate\Support\Facades\Log;

/**
 * 短信发送应用通道（对接 NiurenSmssend）
 *
 * 本应用不再保存任何服务商凭证，改为按「模板标识（alias）」调用短信发送应用的
 * SendService::sendByAlias()；服务商、签名、服务商模板 CODE 均由该应用的模板记录决定。
 *
 * 变量约定：验证码写入模板中 var_name 为 code 的字段；
 * 若模板未使用 code 变量，则回退写入第一个字段，避免验证码无处写入。
 */
class NiurenSmsSender extends AbstractSmsSender
{
    /**
     * 调用方应用标识（写入短信发送应用的发送记录，便于溯源）
     */
    protected const CALLER_APP = 'cmspro.sso';

    /**
     * 验证码变量名约定
     */
    protected const CODE_VAR_NAME = 'code';

    /**
     * 发送验证码短信
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function send(string $phone, string $code): array
    {
        $alias = $this->settingService->getSmssendTemplateAlias();

        if ($alias === '') {
            return ['success' => false, 'message' => '未配置短信发送应用的模板标识'];
        }

        // 应用被停用或卸载后类不可加载，避免触发致命错误
        if (!class_exists(SendService::class) || !class_exists(TemplateService::class)) {
            Log::error('短信发送应用不可用', ['alias' => $alias]);

            return ['success' => false, 'message' => '短信发送应用不可用，请确认已安装并启用'];
        }

        try {
            $fieldValues = $this->resolveFieldValues($alias, $code);

            app(SendService::class)->sendByAlias($alias, $phone, $fieldValues, [], self::CALLER_APP);

            return ['success' => true, 'message' => 'ok'];
        } catch (SmsException $e) {
            // 业务失败（模板不存在、必填项缺失、服务商返回错误），提示语为中文可直接透出
            Log::error('短信发送应用发送失败', ['alias' => $alias, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('短信发送应用调用异常', ['alias' => $alias, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => '短信发送应用调用异常'];
        }
    }

    /**
     * 解析模板字段，构造 sendByAlias 所需的「字段 ID => 取值」数组
     *
     * @return array<int, string>
     */
    protected function resolveFieldValues(string $alias, string $code): array
    {
        $fields = app(TemplateService::class)->findByAlias($alias)->fields;

        $matched = [];
        foreach ($fields as $field) {
            if ((string) $field->var_name === self::CODE_VAR_NAME) {
                $matched[] = (int) $field->id;
            }
        }

        if ($matched === [] && $fields->isNotEmpty()) {
            $matched[] = (int) $fields->first()->id;
        }

        return array_fill_keys($matched, $code);
    }
}
