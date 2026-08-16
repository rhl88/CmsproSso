<?php

use App\Apps\CmsproSso\Controllers\Admin\SettingController;
use Illuminate\Support\Facades\Route;

// 后台设置页面路由
Route::get('/setting', [SettingController::class, 'index'])
    ->name('admin.cmspro.sso.setting');

// 后台设置 API 路由
Route::prefix('api')->group(function () {
    Route::get('/settings', [SettingController::class, 'getSettings']);
    Route::put('/settings', [SettingController::class, 'update']);
});
