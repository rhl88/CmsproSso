<?php

use App\Apps\CmsproSso\Controllers\HomeApi\AuthController;
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

// 手机验证码登录 API
Route::post('/auth/phone/code', [AuthController::class, 'sendPhoneCode']);
Route::post('/auth/phone/login', [AuthController::class, 'phoneLogin']);

// 需登录的 API
Route::middleware('cmspro.sso.auth')->group(function () {
    Route::get('/user/info', [AuthController::class, 'userInfo']);
});
