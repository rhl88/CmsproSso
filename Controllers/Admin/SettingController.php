<?php

namespace App\Apps\CmsproSso\Controllers\Admin;

use App\Apps\CmsproSso\Services\SettingService;
use App\Apps\NiurenSmssend\Services\TemplateService;
use App\Enums\AppStatus;
use App\Models\AppModel;
use App\Models\ConfigGroup;
use App\Models\ConfigItem;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    /**
     * 应用 ID
     */
    protected const APP_ID = 'cmspro.sso';

    public function __construct(
        protected SettingService $settingService
    ) {
    }

    /**
     * 渲染设置页面
     */
    public function index()
    {
        $configKeys = SettingService::ALLOWED_KEYS;

        $configs = [];
        foreach ($configKeys as $key) {
            $item = ConfigItem::where('code', SettingService::CONFIG_PREFIX . $key)->first();
            // 优先取数据库值，回退到 Config/sso.php 中的默认值
            $configs[$key] = $item ? $item->value : config('apps.cmspro.sso.' . $key, '');
        }

        // Onlinepay（在线支付）安装/启用状态：邀请码"在线购买"模式的前置依赖
        $onlinepay = AppModel::where('app_id', 'onlinepay')->first();

        // Captcha（验证码）安装/启用状态：验证码开关的前置依赖
        $captcha = AppModel::where('app_id', 'cmspro.captcha')->first();
        $captchaScenes = array_filter(array_map('trim', explode(',', (string) ($configs['captcha_scenes'] ?? ''))));

        // SMTP邮箱应用安装/启用/接管状态：邮箱验证发信方式的前置依赖
        $smtpmail = AppModel::where('app_id', 'cmspro.smtpmail')->first();
        $smtpmailEnabled = $smtpmail !== null && $smtpmail->status === AppStatus::ENABLED;
        $smtpmailTakeover = $smtpmailEnabled && function_exists('smtpmail_config')
            && smtpmail_config('takeover_system_mail', '0') === '1';

        // 短信发送应用安装/启用状态与可用模板：手机验证码短信通道的前置依赖
        $smssend = AppModel::where('app_id', 'niuren.smssend')->first();
        $smssendEnabled = $smssend !== null && $smssend->status === AppStatus::ENABLED;

        return view('cmspro.sso::Admin.settings.index', [
            'configs'             => $configs,
            'relay_callback_url'  => \App\Apps\CmsproSso\Services\ShareService::frontUrl('/auth/relay/callback'),
            'onlinepay_installed' => $onlinepay !== null,
            'onlinepay_enabled'   => $onlinepay !== null && $onlinepay->status === AppStatus::ENABLED,
            'captcha_installed'   => $captcha !== null,
            'captcha_enabled_app' => $captcha !== null && $captcha->status === AppStatus::ENABLED,
            'captcha_scene_login' => in_array('login', $captchaScenes, true),
            'captcha_scene_register' => in_array('register', $captchaScenes, true),
            'smtpmail_installed'  => $smtpmail !== null,
            'smtpmail_enabled'    => $smtpmailEnabled,
            'smtpmail_takeover'   => $smtpmailTakeover,
            'smssend_installed'   => $smssend !== null,
            'smssend_enabled'     => $smssendEnabled,
            'smssend_templates'   => $this->smssendTemplateOptions($smssendEnabled),
        ]);
    }

    /**
     * 获取短信发送应用中「已启用且已设置模板标识」的模板选项
     *
     * 应用未启用、类不可加载或查询异常时返回空数组，保证设置页仍可正常渲染。
     *
     * @return array<int, array<string, mixed>>
     */
    private function smssendTemplateOptions(bool $smssendEnabled): array
    {
        if (!$smssendEnabled || !class_exists(TemplateService::class)) {
            return [];
        }

        try {
            $templates = app(TemplateService::class)->options();
        } catch (\Throwable $e) {
            return [];
        }

        return array_values(array_filter(
            $templates,
            static fn (array $item): bool => ($item['alias'] ?? '') !== ''
        ));
    }

    /**
     * 获取当前配置（API）
     */
    public function getSettings()
    {
        $configKeys = SettingService::ALLOWED_KEYS;

        $configs = [];
        foreach ($configKeys as $key) {
            $item = ConfigItem::where('code', SettingService::CONFIG_PREFIX . $key)->first();
            $configs[$key] = $item ? $item->value : config('apps.cmspro.sso.' . $key, '');
        }

        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => [
                'configs' => $configs,
            ],
        ]);
    }

    /**
     * 保存配置（API）
     */
    public function update(Request $request)
    {
        $data = $request->only(SettingService::ALLOWED_KEYS);

        // 站点角色校验
        $quickMode = (string) ($data['quick_mode'] ?? '');
        if ($quickMode !== '' && !in_array($quickMode, SettingService::QUICK_MODES, true)) {
            return response()->json(['code' => 400, 'message' => '站点角色不合法'], 400);
        }

        // 客户端模式必须填写服务端地址
        if ($quickMode === 'client' && trim((string) ($data['relay_server_url'] ?? '')) === '') {
            return response()->json(['code' => 400, 'message' => '客户端模式必须填写服务端地址'], 400);
        }

        // 访问模式校验
        $accessMode = (string) ($data['access_mode'] ?? '');
        if ($accessMode !== '' && !in_array($accessMode, ['path', 'domain'], true)) {
            return response()->json(['code' => 400, 'message' => '访问模式不合法'], 400);
        }

        // 验证码校验：开启时依赖验证码应用，且必须选择启用位置与有效模式
        if (($data['captcha_enabled'] ?? '0') === '1') {
            $captcha = AppModel::where('app_id', 'cmspro.captcha')->first();
            if (!$captcha) {
                return response()->json(['code' => 400, 'message' => '验证码功能依赖「验证码」应用，当前未安装，请先前往「应用管理」安装后再开启'], 400);
            }
            if ($captcha->status !== AppStatus::ENABLED) {
                return response()->json(['code' => 400, 'message' => '「验证码」应用已安装但未启用，请先前往「应用管理」启用后再开启验证码功能'], 400);
            }

            $scenes = array_values(array_filter(array_map('trim', explode(',', (string) ($data['captcha_scenes'] ?? '')))));
            if ($scenes === []) {
                return response()->json(['code' => 400, 'message' => '开启验证码必须至少选择一个启用位置（登录/注册）'], 400);
            }

            $driver = (string) ($data['captcha_driver'] ?? '');
            if ($driver !== '' && !in_array($driver, SettingService::CAPTCHA_DRIVERS, true)) {
                return response()->json(['code' => 400, 'message' => '验证码模式不合法'], 400);
            }
        }

        // 子域名模式必须填写绑定域名
        if ($accessMode === 'domain' && empty(trim((string) ($data['access_domain'] ?? '')))) {
            return response()->json(['code' => 400, 'message' => '子域名模式必须填写绑定域名'], 400);
        }

        // 微信登录启用时校验配置（客户端模式下社交登录走服务端中转，无需本地凭证）
        if (($data['wechat_enabled'] ?? '0') === '1' && $quickMode !== 'client') {
            if (empty(trim((string) ($data['wechat_mp_app_id'] ?? '')))) {
                return response()->json(['code' => 400, 'message' => '启用微信登录必须填写公众号 AppID'], 400);
            }
            if (empty(trim((string) ($data['wechat_mp_app_secret'] ?? '')))) {
                return response()->json(['code' => 400, 'message' => '启用微信登录必须填写公众号 AppSecret'], 400);
            }
        }

        // QQ 登录启用时校验配置（客户端模式下社交登录走服务端中转，无需本地凭证）
        if (($data['qq_enabled'] ?? '0') === '1' && $quickMode !== 'client') {
            if (empty(trim((string) ($data['qq_app_id'] ?? '')))) {
                return response()->json(['code' => 400, 'message' => '启用QQ登录必须填写 QQ互联 AppID'], 400);
            }
            if (empty(trim((string) ($data['qq_app_key'] ?? '')))) {
                return response()->json(['code' => 400, 'message' => '启用QQ登录必须填写 QQ互联 AppKey'], 400);
            }
        }

        // 网页授权外挂启用时校验外挂地址（客户端模式下无需本地外挂配置）
        if (($data['wechat_oauth_enabled'] ?? '0') === '1' && $quickMode !== 'client') {
            $oauthUrl = trim((string) ($data['wechat_oauth_url'] ?? ''));
            if ($oauthUrl === '' || filter_var($oauthUrl, FILTER_VALIDATE_URL) === false) {
                return response()->json(['code' => 400, 'message' => '启用网页授权外挂必须填写有效的外挂地址'], 400);
            }
        }

        // 邀请码发放方式校验
        $grantMode = (string) ($data['invite_grant_mode'] ?? '');
        if ($grantMode !== '' && !in_array($grantMode, ['online_buy', 'url', 'text'], true)) {
            return response()->json(['code' => 400, 'message' => '邀请码发放方式不合法'], 400);
        }

        // 邀请注册启用时按发放方式校验对应表单
        if (($data['invite_enabled'] ?? '0') === '1') {
            if ($grantMode === 'online_buy') {
                if (!is_numeric($data['invite_price'] ?? null)) {
                    return response()->json(['code' => 400, 'message' => '在线购买模式必须填写邀请码费用（数字）'], 400);
                }
                // 在线购买依赖 Onlinepay 应用，未安装/未启用时禁止保存
                $onlinepay = AppModel::where('app_id', 'onlinepay')->first();
                if (!$onlinepay || $onlinepay->status !== AppStatus::ENABLED) {
                    return response()->json(['code' => 400, 'message' => '在线购买模式依赖「在线支付」应用，请先安装并启用后再保存'], 400);
                }
            }
            if ($grantMode === 'url') {
                $inviteUrl = trim((string) ($data['invite_url'] ?? ''));
                if ($inviteUrl === '' || filter_var($inviteUrl, FILTER_VALIDATE_URL) === false) {
                    return response()->json(['code' => 400, 'message' => '指定URL模式必须填写有效的获取链接'], 400);
                }
            }
            if ($grantMode === 'text' && trim((string) ($data['invite_text'] ?? '')) === '') {
                return response()->json(['code' => 400, 'message' => '文字提示模式必须填写提示内容'], 400);
            }
        }

        // 解析配置项所属的 group_id（按 manifest 声明匹配）
        $groupMap = $this->resolveConfigGroupMap();

        foreach ($data as $key => $value) {
            $code = SettingService::CONFIG_PREFIX . $key;
            $groupId = $groupMap[$key] ?? 0;

            ConfigItem::updateOrCreate(
                ['code' => $code],
                [
                    'value'    => (string) $value,
                    'group_id' => $groupId,
                    'name'     => $key,
                    'type'     => 'text',
                    'status'   => 1,
                ]
            );

            // 清除业务层缓存
            Cache::forget("cmspro_sso:config:{$key}");
        }

        // 站点角色变更时同步「客户端管理」菜单可见性（仅服务端模式可见）
        if (array_key_exists('quick_mode', $data)) {
            $this->settingService->syncClientsMenuVisibility((string) $data['quick_mode']);
        }

        // 涉及路由的配置（access_mode/access_path/access_domain）变更后必须清缓存
        Artisan::call('route:clear');
        Artisan::call('config:clear');

        return response()->json(['code' => 0, 'message' => '配置更新成功', 'data' => null]);
    }

    /**
     * 解析配置项所属的 group_id（按 manifest 声明匹配）
     */
    protected function resolveConfigGroupMap(): array
    {
        $map = [];

        $manifestPath = base_path('app/Apps/CmsproSso/manifest.json');
        if (!file_exists($manifestPath)) {
            return $map;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $configGroups = $manifest['config_groups'] ?? [];

        foreach ($configGroups as $groupDef) {
            $group = ConfigGroup::where('code', 'app_' . self::APP_ID . '_' . $groupDef['name'])->first();
            if (!$group) {
                continue;
            }

            foreach ($groupDef['items'] as $item) {
                $map[$item['name']] = $group->id;
            }
        }

        return $map;
    }
}