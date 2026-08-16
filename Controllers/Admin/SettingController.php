<?php

namespace App\Apps\CmsproSso\Controllers\Admin;

use App\Apps\CmsproSso\Services\SettingService;
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

        return view('cmspro.sso::Admin.settings.index', compact('configs'));
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

        // 访问模式校验
        $accessMode = (string) ($data['access_mode'] ?? '');
        if ($accessMode !== '' && !in_array($accessMode, ['path', 'domain'], true)) {
            return response()->json(['code' => 400, 'message' => '访问模式不合法'], 400);
        }

        // 子域名模式必须填写绑定域名
        if ($accessMode === 'domain' && empty(trim((string) ($data['access_domain'] ?? '')))) {
            return response()->json(['code' => 400, 'message' => '子域名模式必须填写绑定域名'], 400);
        }

        // 微信登录启用时校验配置
        if (($data['wechat_enabled'] ?? '0') === '1') {
            if (empty(trim((string) ($data['wechat_mp_app_id'] ?? '')))) {
                return response()->json(['code' => 400, 'message' => '启用微信登录必须填写公众号 AppID'], 400);
            }
            if (empty(trim((string) ($data['wechat_mp_app_secret'] ?? '')))) {
                return response()->json(['code' => 400, 'message' => '启用微信登录必须填写公众号 AppSecret'], 400);
            }
        }

        // 网页授权外挂启用时校验外挂地址
        if (($data['wechat_oauth_enabled'] ?? '0') === '1') {
            $oauthUrl = trim((string) ($data['wechat_oauth_url'] ?? ''));
            if ($oauthUrl === '' || filter_var($oauthUrl, FILTER_VALIDATE_URL) === false) {
                return response()->json(['code' => 400, 'message' => '启用网页授权外挂必须填写有效的外挂地址'], 400);
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