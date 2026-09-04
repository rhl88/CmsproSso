<?php

namespace App\Apps\CmsproSso\Tests\Feature;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * 统一登录应用 · 绑定中心共用组件分层测试
 *
 * 覆盖：共用绑定中心局部视图（cmspro.sso::Home.partials.bind-center）可独立渲染、
 * 结构自包含（弹层 DOM、绑定/解绑接口、入口事件委托、平台图标使用 layui 内置类），
 * 供前台登录后首页与框架账号管理页（user.account.index）复用。
 */
class SsoBindCenterComponentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 登记统一登录应用视图命名空间，仅渲染该组件，无需数据库
        View::addNamespace('cmspro.sso', dirname(__DIR__, 2) . '/Views');
    }

    public function test_共用绑定组件可渲染且结构自包含(): void
    {
        $html = view('cmspro.sso::Home.partials.bind-center')->render();

        // 弹层容器与列表
        $this->assertStringContainsString('id="sso-bind-center-modal"', $html);
        $this->assertStringContainsString('id="sso-bind-center-list"', $html);

        // 入口采用事件委托，宿主页面任意元素加 .sso-bind-center-open 即可触发
        $this->assertStringContainsString('.sso-bind-center-open', $html);

        // 绑定中心相关接口与登录态 API 前缀
        $this->assertStringContainsString('/user/bindings', $html);
        $this->assertStringContainsString('/user/unbind/', $html);
        $this->assertStringContainsString('/bind-status', $html);
        $this->assertStringContainsString('/user/bind/phone', $html);

        // 平台图标统一使用 layui 内置图标，避免依赖单一页面的自定义样式类
        $this->assertStringContainsString('layui-icon-login-wechat', $html);
        $this->assertStringContainsString('layui-icon-login-qq', $html);
        $this->assertStringContainsString('layui-icon-cellphone', $html);
        $this->assertStringContainsString('layui-icon-email', $html);

        // 自包含：未定义 SSO_API_BASE 时兜底为相对路径
        $this->assertStringContainsString("'/api/cmspro/sso'", $html);
    }

    public function test_共用绑定组件不含退出登录逻辑(): void
    {
        $html = view('cmspro.sso::Home.partials.bind-center')->render();

        // 退出登录属宿主页面职责，组件内不应出现 logout 跳转
        $this->assertStringNotContainsString('/auth/logout', $html);
        $this->assertStringNotContainsString('logout-btn', $html);
    }
}