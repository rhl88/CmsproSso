<?php

use App\Apps\CmsproSso\Controllers\Home\AuthController;
use App\Apps\CmsproSso\Controllers\Home\InviteController;
use Illuminate\Support\Facades\Route;

// 前台统一登录页面路由（公开访问）
// 首页：已登录停留首页，未登录跳转登录页
Route::get('/', function () {
    if (!auth('web')->check()) {
        return redirect(\App\Apps\CmsproSso\Services\ShareService::frontUrl('/login'));
    }

    return view('cmspro.sso::Home.home', [
        'user' => auth('web')->user(),
    ]);
});
Route::get('/login', [AuthController::class, 'loginForm']);
Route::get('/register', [AuthController::class, 'registerForm']);
Route::get('/forgot-password', [AuthController::class, 'forgotPasswordForm']);
// 邀请码购买页（游客可访问，仅在线购买模式开启时可用）
Route::get('/invite/buy', [InviteController::class, 'buyForm']);
// 微信新用户邀请码填写页（邀请注册开启时，微信内授权回调发现新用户后跳转）
Route::get('/auth/wechat/complete-invite', [AuthController::class, 'completeInviteForm']);
// QQ 新用户邀请码填写页（邀请注册开启时，QQ 授权回调发现新用户后跳转）
Route::get('/auth/qq/complete-invite', [AuthController::class, 'completeInviteForm']);
// 支付宝/抖音/微博/GitHub 新用户邀请码填写页（邀请注册开启时，授权回调发现新用户后跳转）
Route::get('/auth/alipay/complete-invite', [AuthController::class, 'completeInviteForm']);
Route::get('/auth/douyin/complete-invite', [AuthController::class, 'completeInviteForm']);
Route::get('/auth/weibo/complete-invite', [AuthController::class, 'completeInviteForm']);
Route::get('/auth/github/complete-invite', [AuthController::class, 'completeInviteForm']);

// 微信授权回调（公开访问，微信服务器回调）
Route::get('/auth/wechat/callback', [AuthController::class, 'wechatCallback']);
// QQ 授权回调（公开访问，QQ 服务器回调）
Route::get('/auth/qq/callback', [AuthController::class, 'qqCallback']);
// 支付宝/抖音/微博/GitHub 授权回调（公开访问，平台服务器回调）
Route::get('/auth/alipay/callback', [AuthController::class, 'alipayCallback']);
Route::get('/auth/douyin/callback', [AuthController::class, 'douyinCallback']);
Route::get('/auth/weibo/callback', [AuthController::class, 'weiboCallback']);
Route::get('/auth/github/callback', [AuthController::class, 'githubCallback']);
// 快捷模式服务端授权端点（客户端站点跳入，仅 quick_mode=server 时开放）
Route::get('/oauth/authorize', [\App\Apps\CmsproSso\Controllers\Home\OAuthController::class, 'authorize']);
// 快捷模式客户端回调（服务端授权后跳回，仅 quick_mode=client 时开放）
Route::get('/auth/relay/callback', [AuthController::class, 'relayCallback']);
// 快捷模式客户端新用户邀请码填写页（邀请注册开启时）
Route::get('/auth/relay/complete-invite', [AuthController::class, 'completeInviteForm']);
// 快捷模式客户端新用户完善资料页
Route::get('/auth/relay/complete', [AuthController::class, 'completeForm']);
// PC 扫码登录成功提示页
Route::get('/auth/wechat/pc-success', [AuthController::class, 'pcSuccessForm']);
Route::get('/auth/qq/pc-success', [AuthController::class, 'pcSuccessForm']);
// 中转扫码确认成功提示页（客户端扫码会话在服务端完成授权后，手机端展示）
Route::get('/auth/relay/pc-success', [AuthController::class, 'pcSuccessForm']);
// 首次社交登录完善资料页
Route::get('/auth/wechat/complete', [AuthController::class, 'completeForm']);
Route::get('/auth/qq/complete', [AuthController::class, 'completeForm']);
Route::get('/auth/alipay/complete', [AuthController::class, 'completeForm']);
Route::get('/auth/douyin/complete', [AuthController::class, 'completeForm']);
Route::get('/auth/weibo/complete', [AuthController::class, 'completeForm']);
Route::get('/auth/github/complete', [AuthController::class, 'completeForm']);

// 需登录的前台页面（受统一登录认证中间件保护）
Route::middleware('cmspro.sso.auth')->group(function () {
    Route::get('/home', [AuthController::class, 'home']);
});
