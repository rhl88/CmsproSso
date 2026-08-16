<?php

namespace App\Apps\CmsproSso\Services;

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
        'access_mode', 'access_domain', 'access_path',
        'wechat_enabled', 'wechat_mp_app_id', 'wechat_mp_app_secret',
        'wechat_oauth_enabled', 'wechat_oauth_url',
        'login_title', 'allow_register', 'default_redirect',
        'override_system',
    ];

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
}
