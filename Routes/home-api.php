<?php

use App\Apps\CmsproSso\Controllers\HomeApi\AuthController;
use App\Apps\CmsproSso\Controllers\HomeApi\InviteController;
use App\Apps\CmsproSso\Controllers\HomeApi\QrCodeController;
use Illuminate\Support\Facades\Route;

// 二维码生成（PC 扫码登录弹窗用）
Route::get('/qrcode', [QrCodeController::class, 'generate']);

// 前台统一登录 API（公开访问）
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

// 微信登录 API
Route::get('/auth/wechat/login-url', [AuthController::class, 'wechatLoginUrl']);
Route::get('/auth/wechat/login-status', [AuthController::class, 'wechatLoginStatus']);
Route::post('/auth/wechat/complete', [AuthController::class, 'completeProfile']);
// 邀请注册开启时，微信新用户凭 token 提交邀请码完成注册（PC 弹窗 / 微信端 complete-invite 页共用）
Route::post('/auth/wechat/invite', [AuthController::class, 'submitInvite']);

// 手机验证码登录 API
Route::post('/auth/phone/code', [AuthController::class, 'sendPhoneCode']);
Route::post('/auth/phone/login', [AuthController::class, 'phoneLogin']);

// 邀请码购买 API（游客可访问，仅在线购买模式开启时可用）
Route::get('/invite/channels', [InviteController::class, 'channels']);
Route::post('/invite/order', [InviteController::class, 'createOrder']);
Route::get('/invite/order/status', [InviteController::class, 'status']);

// 需登录的 API
Route::middleware('cmspro.sso.auth')->group(function () {
    Route::get('/user/info', [AuthController::class, 'userInfo']);
});
