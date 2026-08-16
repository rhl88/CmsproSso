<?php

namespace App\Apps\CmsproSso;

use App\Services\HookManager;

class Hooks
{
    public function register(HookManager $hooks): void
    {
        // 预留：统一登录应用可在此注册钩子，供其他应用扩展登录流程
        // 例如：登录成功后同步用户信息、注册后初始化业务数据等
    }
}
