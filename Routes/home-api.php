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
// 邀请注册开启时，微信新用户凭 token 提交邀请码完成注册（PC 弹窗 / 微信端 complete-invite 页共用）
Route::post('/auth/wechat/invite', [AuthController::class, 'submitInvite']);

// QQ 登录 API
Route::get('/auth/qq/login-url', [AuthController::class, 'qqLoginUrl']);
Route::get('/auth/qq/login-status', [AuthController::class, 'qqLoginStatus']);
// 邀请注册开启时，QQ 新用户凭 token 提交邀请码完成注册（PC 弹窗 / QQ 端 complete-invite 页共用）
Route::post('/auth/qq/invite', [AuthController::class, 'submitInvite']);

// 支付宝/抖音/微博/GitHub 登录 API（整页跳转授权，state 存 session，无 PC 扫码，共用 login-status 之外的通用流程）
Route::get('/auth/alipay/login-url', [AuthController::class, 'alipayLoginUrl']);
Route::get('/auth/douyin/login-url', [AuthController::class, 'douyinLoginUrl']);
Route::get('/auth/weibo/login-url', [AuthController::class, 'weiboLoginUrl']);
Route::get('/auth/github/login-url', [AuthController::class, 'githubLoginUrl']);
// 邀请注册开启时，新用户凭 token 提交邀请码完成注册（complete-invite 页共用，token 内含平台标识）
Route::post('/auth/alipay/invite', [AuthController::class, 'submitInvite']);
Route::post('/auth/douyin/invite', [AuthController::class, 'submitInvite']);
Route::post('/auth/weibo/invite', [AuthController::class, 'submitInvite']);
Route::post('/auth/github/invite', [AuthController::class, 'submitInvite']);

// 快捷模式客户端 API（中转登录，仅 quick_mode=client 时开放）
Route::get('/auth/relay/login-url', [AuthController::class, 'relayLoginUrl']);
// PC 扫码中转：轮询服务端扫码结果（confirmed 后客户端建登录态/跳新用户分流页）
Route::get('/auth/relay/login-status', [AuthController::class, 'relayLoginStatus']);
Route::post('/auth/relay/invite', [AuthController::class, 'submitInvite']);
// 新用户分流：完善资料（新建账号） / 账号绑定（登录已有账号）
Route::post('/auth/relay/setup', [AuthController::class, 'setupAccount']);
Route::post('/auth/relay/bind', [AuthController::class, 'bindAccount']);

// 社交新用户分流页「完善资料」提交：凭授权句柄（token）创建账号并登录（{platform} 由路由注入，relay 走上方 setup）
Route::post('/auth/{platform}/complete', [AuthController::class, 'completeProfile']);
// 社交新用户分流页「账号绑定」提交：凭 token + 账号密码绑定既有账号并登录（仅微信/QQ/支付宝/抖音/微博/GitHub 开放）
Route::post('/auth/{platform}/bind-existing', [AuthController::class, 'bindToExistingAccount']);

// 手机验证码登录 API
Route::post('/auth/phone/code', [AuthController::class, 'sendPhoneCode']);
Route::post('/auth/phone/login', [AuthController::class, 'phoneLogin']);

// 邮箱验证码登录 API
Route::post('/auth/email/code', [AuthController::class, 'sendEmailCode']);
Route::post('/auth/email/login', [AuthController::class, 'emailLogin']);

// 找回密码 API（手机短信通道；邮箱通道复用系统 /api/user/password/* 接口）
Route::post('/auth/forgot/phone/code', [AuthController::class, 'sendForgotPhoneCode']);
Route::post('/auth/forgot/phone/reset', [AuthController::class, 'resetPasswordByPhone']);

// 邀请码购买 API（游客可访问，仅在线购买模式开启时可用）
Route::get('/invite/channels', [InviteController::class, 'channels']);
Route::post('/invite/order', [InviteController::class, 'createOrder']);
Route::get('/invite/order/status', [InviteController::class, 'status']);

// 需登录的 API
Route::middleware('cmspro.sso.auth')->group(function () {
    Route::get('/user/info', [AuthController::class, 'userInfo']);

    // 绑定中心（微信/QQ/支付宝/抖音/微博/GitHub 授权绑定、手机验证码绑定、解绑）
    Route::get('/user/bindings', [AuthController::class, 'bindings']);
    Route::get('/auth/wechat/bind-url', [AuthController::class, 'wechatBindUrl']);
    Route::get('/auth/wechat/bind-status', [AuthController::class, 'wechatBindStatus']);
    Route::get('/auth/qq/bind-url', [AuthController::class, 'qqBindUrl']);
    Route::get('/auth/qq/bind-status', [AuthController::class, 'qqBindStatus']);
    // 支付宝/抖音/微博/GitHub 绑定：整页跳转授权，回调直接落库，无需轮询 bind-status
    Route::get('/auth/alipay/bind-url', [AuthController::class, 'alipayBindUrl']);
    Route::get('/auth/douyin/bind-url', [AuthController::class, 'douyinBindUrl']);
    Route::get('/auth/weibo/bind-url', [AuthController::class, 'weiboBindUrl']);
    Route::get('/auth/github/bind-url', [AuthController::class, 'githubBindUrl']);
    Route::post('/user/bind/phone', [AuthController::class, 'bindPhone']);
    Route::post('/user/bind/email', [AuthController::class, 'bindEmail']);
    Route::post('/user/unbind/{platform}', [AuthController::class, 'unbind']);
});
