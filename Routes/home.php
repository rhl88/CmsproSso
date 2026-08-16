<?php

use App\Apps\CmsproSso\Controllers\Home\AuthController;
use Illuminate\Support\Facades\Route;

// 前台统一登录页面路由（公开访问）
// 子域名模式：访问绑定域名根路径时重定向到登录页
Route::get('/', function () {
    return redirect(\App\Apps\CmsproSso\Services\ShareService::frontUrl('/login'));
});
Route::get('/login', [AuthController::class, 'loginForm']);
Route::get('/register', [AuthController::class, 'registerForm']);

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
