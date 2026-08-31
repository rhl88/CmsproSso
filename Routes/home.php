<?php

use App\Apps\CmsproSso\Controllers\Home\AuthController;
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

// 微信授权回调（公开访问，微信服务器回调）
Route::get('/auth/wechat/callback', [AuthController::class, 'wechatCallback']);
// PC 扫码登录成功提示页
Route::get('/auth/wechat/pc-success', [AuthController::class, 'pcSuccessForm']);
// 首次微信登录完善资料页
Route::get('/auth/wechat/complete', [AuthController::class, 'completeForm']);

// 需登录的前台页面（受统一登录认证中间件保护）
Route::middleware('cmspro.sso.auth')->group(function () {
    Route::get('/home', [AuthController::class, 'home']);
});
