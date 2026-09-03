<?php

use App\Apps\CmsproSso\Controllers\Admin\ClientController;
use App\Apps\CmsproSso\Controllers\Admin\InviteCodeController;
use App\Apps\CmsproSso\Controllers\Admin\SettingController;
use Illuminate\Support\Facades\Route;

// 后台设置页面路由
Route::get('/setting', [SettingController::class, 'index'])
    ->name('admin.cmspro.sso.setting');

// 邀请码管理页面路由
Route::get('/invite', [InviteCodeController::class, 'index'])
    ->name('admin.cmspro.sso.invite');

// 快捷模式客户端管理页面路由（服务端模式）
Route::get('/clients', [ClientController::class, 'index'])
    ->name('admin.cmspro.sso.clients');

// 后台设置 API 路由
Route::prefix('api')->group(function () {
    Route::get('/settings', [SettingController::class, 'getSettings']);
    Route::put('/settings', [SettingController::class, 'update']);

    // 邀请码管理 API
    Route::get('/invites', [InviteCodeController::class, 'list']);
    Route::post('/invites/generate', [InviteCodeController::class, 'generate']);
    Route::put('/invites/{id}/status', [InviteCodeController::class, 'updateStatus'])->whereNumber('id');
    Route::delete('/invites/{id}', [InviteCodeController::class, 'destroy'])->whereNumber('id');

    // 客户端管理 API
    Route::get('/clients', [ClientController::class, 'list']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::put('/clients/{id}', [ClientController::class, 'update'])->whereNumber('id');
    Route::put('/clients/{id}/status', [ClientController::class, 'updateStatus'])->whereNumber('id');
    Route::put('/clients/{id}/reset-secret', [ClientController::class, 'resetSecret'])->whereNumber('id');
    Route::delete('/clients/{id}', [ClientController::class, 'destroy'])->whereNumber('id');
});
