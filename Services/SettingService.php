<?php

namespace App\Apps\CmsproSso\Services;

use App\Enums\AppStatus;
use App\Enums\Status;
use App\Models\AdminMenu;
use App\Models\AppModel;
use App\Models\ConfigItem;

/**
 * 统一登录应用配置服务
 *
 * 从框架配置表（config_items）读取应用配置，缺失时回退到 Config/sso.php 默认值。
 */
class SettingService
{
    /**
     * 应用配置项 code 前缀（应用 id 中的点号转为下划线）
     */
    public const CONFIG_PREFIX = 'app_cmspro_sso_';

    /**
     * 允许保存的配置项白名单
     */
    public const ALLOWED_KEYS = [
        'quick_mode', 'relay_server_url', 'relay_client_id', 'relay_client_secret',
        'access_mode', 'access_domain', 'access_path',
        'wechat_enabled', 'wechat_mp_app_id', 'wechat_mp_app_secret',
        'wechat_oauth_enabled', 'wechat_oauth_url',
        'qq_enabled', 'qq_mode', 'qq_app_id', 'qq_app_key',
        'alipay_enabled', 'alipay_app_id', 'alipay_private_key',
        'douyin_enabled', 'douyin_client_key', 'douyin_client_secret',
        'weibo_enabled', 'weibo_app_key', 'weibo_app_secret',
        'github_enabled', 'github_client_id', 'github_client_secret',
        'login_title', 'allow_register', 'default_redirect',
        'username_banned_words',
        'override_system',
        'captcha_enabled', 'captcha_scenes', 'captcha_driver',
        'invite_enabled', 'invite_grant_mode', 'invite_price', 'invite_url', 'invite_text',
        'phone_login_enabled', 'sms_provider',
        'email_login_enabled',
        'sms_aliyun_access_key_id', 'sms_aliyun_access_key_secret',
        'sms_aliyun_sign_name', 'sms_aliyun_template_code',
        'sms_tencent_secret_id', 'sms_tencent_secret_key',
        'sms_tencent_sdk_app_id', 'sms_tencent_sign_name', 'sms_tencent_template_id',
        'sms_volc_access_key_id', 'sms_volc_access_key_secret',
        'sms_volc_sms_account', 'sms_volc_sign_name', 'sms_volc_template_id',
    ];

    /**
     * 快捷模式取值
     */
    public const QUICK_MODES = ['standalone', 'server', 'client'];

    /**
     * 获取快捷模式（standalone 独立站 / server 服务端 / client 客户端，非法值回退 standalone）
     */
    public function getQuickMode(): string
    {
        $mode = $this->get('quick_mode', 'standalone');

        return in_array($mode, self::QUICK_MODES, true) ? $mode : 'standalone';
    }

    /**
     * 当前站点是否为快捷模式服务端（对外提供统一登录中转）
     */
    public function isRelayServer(): bool
    {
        return $this->getQuickMode() === 'server';
    }

    /**
     * 当前站点是否为快捷模式客户端（社交登录委托中转服务端）
     */
    public function isRelayClient(): bool
    {
        return $this->getQuickMode() === 'client';
    }

    /**
     * 按站点角色同步「客户端管理」菜单可见性（仅服务端模式可见，其余角色隐藏）
     */
    public function syncClientsMenuVisibility(string $mode): void
    {
        $menu = AdminMenu::where('code', 'admin_cmspro_sso_clients')->first();
        if ($menu) {
            $menu->update([
                'status' => $mode === 'server' ? Status::ENABLED : Status::DISABLED,
            ]);
        }
    }

    /**
     * 读取配置值
     */
    public function get(string $key, string $default = ''): string
    {
        try {
            $item = ConfigItem::where('code', self::CONFIG_PREFIX . $key)->first();
            if ($item && $item->value !== null && $item->value !== '') {
                return (string) $item->value;
            }
        } catch (\Throwable $e) {
            // 数据库不可用时回退默认值
        }

        return (string) config('apps.cmspro.sso.' . $key, $default);
    }

    /**
     * 判断微信登录是否已配置（AppID + AppSecret 均非空）
     */
    public function isWechatConfigured(): bool
    {
        return $this->get('wechat_mp_app_id') !== ''
            && $this->get('wechat_mp_app_secret') !== '';
    }

    /**
     * 判断 QQ 登录是否已配置（App ID + App Key 均非空）
     */
    public function isQqConfigured(): bool
    {
        return $this->get('qq_app_id') !== ''
            && $this->get('qq_app_key') !== '';
    }

    /**
     * 按平台判断社交登录是否已配置（统一入口，供登录入口/绑定/中转平台列表复用）
     *
     * 支付宝需 AppID + 应用私钥（RSA2 签名请求）；其余平台为应用标识 + 密钥两件套。
     */
    public function isSocialConfigured(string $platform): bool
    {
        return match ($platform) {
            'wechat' => $this->isWechatConfigured(),
            'qq'     => $this->isQqConfigured(),
            'alipay' => $this->get('alipay_app_id') !== '' && $this->get('alipay_private_key') !== '',
            'douyin' => $this->get('douyin_client_key') !== '' && $this->get('douyin_client_secret') !== '',
            'weibo'  => $this->get('weibo_app_key') !== '' && $this->get('weibo_app_secret') !== '',
            'github' => $this->get('github_client_id') !== '' && $this->get('github_client_secret') !== '',
            default  => false,
        };
    }

    /**
     * 平台中文标签（统一维护，供提示文案与前台展示复用）
     */
    public static function platformLabel(string $platform): string
    {
        return match ($platform) {
            'wechat' => '微信',
            'qq'     => 'QQ',
            'alipay' => '支付宝',
            'douyin' => '抖音',
            'weibo'  => '微博',
            'github' => 'GitHub',
            default  => $platform,
        };
    }

    /**
     * 判断是否启用「网页授权外挂」模式
     */
    public function isWechatOauthProxyEnabled(): bool
    {
        $enabled = $this->get('wechat_oauth_enabled', '0');
        $url = trim($this->get('wechat_oauth_url', ''));

        return $enabled === '1' && $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * 获取网页授权外挂地址
     */
    public function getWechatOauthUrl(): string
    {
        return trim($this->get('wechat_oauth_url', ''));
    }

    /**
     * 获取登录成功默认跳转地址
     */
    public function getDefaultRedirect(): string
    {
        $redirect = trim($this->get('default_redirect', '/'));
        return $redirect !== '' ? $redirect : '/';
    }

    /**
     * 解析注册用户名屏蔽词列表
     *
     * 支持英文/中文逗号、分号、换行分隔，忽略空白项，统一转小写便于大小写不敏感匹配。
     */
    public function getBannedWords(): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $this->get('username_banned_words', ''));
        $words = preg_split('/[,，;；\n]/u', $raw) ?: [];
        $words = array_filter(array_map('trim', $words), fn ($word) => $word !== '');

        return array_map('mb_strtolower', array_values($words));
    }

    /**
     * 判断用户名是否命中屏蔽词（包含即命中，不区分大小写）
     */
    public function isUsernameBanned(string $username): bool
    {
        $bannedWords = $this->getBannedWords();
        if ($bannedWords === []) {
            return false;
        }

        $username = mb_strtolower($username);
        foreach ($bannedWords as $word) {
            if (str_contains($username, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 支持的短信服务商
     */
    public const SMS_PROVIDERS = ['aliyun', 'tencent', 'volcengine'];

    /**
     * 判断是否启用手机验证码登录
     */
    public function isPhoneLoginEnabled(): bool
    {
        return $this->get('phone_login_enabled', '0') === '1';
    }

    /**
     * 判断是否启用邮箱验证码登录（需系统已配置 SMTP 才可用）
     */
    public function isEmailLoginEnabled(): bool
    {
        return $this->get('email_login_enabled', '0') === '1' && mail_configured();
    }

    /**
     * 获取当前短信服务商（非法值回退为 aliyun）
     */
    public function getSmsProvider(): string
    {
        $provider = $this->get('sms_provider', 'aliyun');
        return in_array($provider, self::SMS_PROVIDERS, true) ? $provider : 'aliyun';
    }

    // ================================================================ 验证码（依赖 CmsproCaptcha 应用）

    /**
     * 验证码应用 ID
     */
    public const CAPTCHA_APP_ID = 'cmspro.captcha';

    /**
     * 验证码可用的模式（与 CmsproCaptcha 的 DRIVER_MAP 保持一致）
     */
    public const CAPTCHA_DRIVERS = ['image', 'math', 'chinese', 'click', 'slider', 'rotate', 'click_button'];

    /**
     * 验证码可用的启用位置（登录 / 注册）
     */
    public const CAPTCHA_SCENES = ['login', 'register'];

    /**
     * 验证码应用是否已安装并启用
     */
    public function isCaptchaAppReady(): bool
    {
        $app = AppModel::where('app_id', self::CAPTCHA_APP_ID)->first();

        return $app !== null && $app->status === AppStatus::ENABLED;
    }

    /**
     * 判断是否启用验证码（后台开关开启 + 验证码应用已安装并启用）
     */
    public function isCaptchaEnabled(): bool
    {
        return $this->get('captcha_enabled', '0') === '1' && $this->isCaptchaAppReady();
    }

    /**
     * 获取验证码启用位置（与可用位置取交集，过滤无效值）
     *
     * @return array<string> 如 ['login', 'register']
     */
    public function getCaptchaScenes(): array
    {
        $raw = str_replace(['，', ' '], [',', ''], $this->get('captcha_scenes', ''));
        $scenes = array_filter(explode(',', $raw), fn ($scene) => $scene !== '');

        return array_values(array_intersect(self::CAPTCHA_SCENES, $scenes));
    }

    /**
     * 判断指定位置是否启用验证码
     */
    public function isCaptchaSceneEnabled(string $scene): bool
    {
        return $this->isCaptchaEnabled() && in_array($scene, $this->getCaptchaScenes(), true);
    }

    /**
     * 获取验证码模式（非法值回退为 image）
     */
    public function getCaptchaDriver(): string
    {
        $driver = $this->get('captcha_driver', 'image');
        return in_array($driver, self::CAPTCHA_DRIVERS, true) ? $driver : 'image';
    }
}
