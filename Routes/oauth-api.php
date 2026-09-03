<?php

use App\Apps\CmsproSso\Controllers\HomeApi\OAuthController;
use Illuminate\Support\Facades\Route;

// 快捷模式服务端 · 开放 API
// 跨站服务器间调用：不注册 web/session 中间件（无 CSRF），仅限流；
// 模式门禁（quick_mode=server）在控制器内校验。
Route::group([], function () {
    Route::get('/platforms', [OAuthController::class, 'platforms']);
    Route::post('/token', [OAuthController::class, 'token']);
    Route::get('/userinfo', [OAuthController::class, 'userinfo']);
    Route::post('/revoke', [OAuthController::class, 'revoke']);

    // 中转扫码（客户端 PC 端扫码登录中转）
    Route::post('/relay-qrcode', [OAuthController::class, 'relayQrcode']);
    Route::get('/relay-status', [OAuthController::class, 'relayStatus']);
});
