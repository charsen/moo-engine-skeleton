<?php

declare(strict_types=1);
/*
 * 后台管理路由（挂载前缀 api/admin、中间件组 admin，见 bootstrap/app.php）
 *
 * moo-scaffold 的控制器生成器会把新路由插入到下面的标记位置，
 * 标记 `:insert_code_here:do_not_delete` 不能删除。
 */

use App\Admin\Controllers\AuthController;
use App\Admin\Controllers\Food\FoodController;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => 'Hello admin api ~');

// 公开：登录 / 退出
Route::post('authenticate', [AuthController::class, 'authenticate'])->name('authenticate');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

// 需要登录（JWT 强制认证 + 近过期续签）
Route::group(['middleware' => ['jwt.guard.auth:admin', 'jwt.auth.refresh']], function () {
    Route::get('me/info', [AuthController::class, 'me'])->name('me.info');
    Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
});

// 业务路由（scaffold 生成插入处）。
// 演示阶段未加登录中间件，便于第 2 章直接调试；真实项目可整体移入上面的登录 group。
Route::group([], function () {
    // FoodController
    Route::iResource('food', FoodController::class);

    // :insert_code_here:do_not_delete
});
