<?php

namespace App\Apps\CmsproSso\Controllers\User;

use App\Http\Controllers\Controller;

/**
 * 前台账号管理页覆盖控制器
 *
 * 本应用通过覆盖系统同名路由 `user.account`（/user/account）渲染前台账号管理页，
 * 在保留系统原有「个人信息」管理功能的同时，注入「账号绑定」模块（绑定已开启的快捷登录/手机/邮箱）。
 * 参照 CmsproNotepad 覆盖 /admin/dashboard 的应用注入模式，不修改任何框架文件。
 */
class AccountController extends Controller
{
    /**
     * 渲染前台账号管理页（应用视图，含账号绑定模块）
     */
    public function index()
    {
        return view('cmspro.sso::User.account');
    }
}