<?php

return [
    // 访问模式配置
    'access_mode' => 'path',
    'access_domain' => '',
    'access_path' => 'sso',

    // 微信登录配置
    'wechat_enabled' => '0',
    'wechat_mp_app_id' => '',
    'wechat_mp_app_secret' => '',
    'wechat_oauth_enabled' => '0',
    'wechat_oauth_url' => '',

    // QQ 互联登录配置
    'qq_enabled' => '0',
    'qq_mode' => 'web', // 登录功能选项：qrcode 扫码登录 / web 网页登录（默认）
    'qq_app_id' => '',
    'qq_app_key' => '',

    // 登录设置
    'login_title' => '统一登录',
    'allow_register' => '1',
    'default_redirect' => '/',

    // 覆盖系统登录
    'override_system' => '0',

    // 邀请注册配置
    'invite_enabled' => '0',
    // 邀请码发放方式（online_buy/url/text）
    'invite_grant_mode' => 'online_buy',
    'invite_price' => '',
    'invite_url' => '',
    'invite_text' => '',

    // 手机验证码登录
    'phone_login_enabled' => '0',

    // 邮箱验证码登录（需系统已配置 SMTP）
    'email_login_enabled' => '0',

    // 短信服务商（aliyun/tencent/volcengine）
    'sms_provider' => 'aliyun',

    // 阿里云短信
    'sms_aliyun_access_key_id' => '',
    'sms_aliyun_access_key_secret' => '',
    'sms_aliyun_sign_name' => '',
    'sms_aliyun_template_code' => '',

    // 腾讯云短信
    'sms_tencent_secret_id' => '',
    'sms_tencent_secret_key' => '',
    'sms_tencent_sdk_app_id' => '',
    'sms_tencent_sign_name' => '',
    'sms_tencent_template_id' => '',

    // 火山引擎短信
    'sms_volc_access_key_id' => '',
    'sms_volc_access_key_secret' => '',
    'sms_volc_sms_account' => '',
    'sms_volc_sign_name' => '',
    'sms_volc_template_id' => '',
];
