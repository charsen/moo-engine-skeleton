<?php

declare(strict_types=1);
/*
 * 客户端（移动端）接口路由（挂载前缀 app、中间件组 client，见 bootstrap/app.php）
 *
 * client 组只做 jwt.assign.guard:user（指派守卫，不强制认证）；
 * 受保护路由再叠加 jwt.guard.auth:user（校验 token 的 guard claim 必须是 user，
 * 后台 admin token 调移动端接口会 401 —— 守卫隔离）+ jwt.auth.refresh（强制认证 + 无感续签）。
 *
 * 标记 `:insert_code_here:do_not_delete` 供 moo-scaffold 生成器插入路由，勿删。
 */

use App\Api\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => 'Hello app api ~');

// 公开：登录 / 退出
Route::post('authenticate', [AuthController::class, 'authenticate'])->name('authenticate');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

// 主动刷新：只校验 guard claim，不挂 jwt.auth.refresh（原因见 AuthController::refresh 注释）
Route::post('refresh', [AuthController::class, 'refresh'])
    ->middleware('jwt.guard.auth:user')->name('refresh');

// 需要登录（user 守卫）
Route::group(['middleware' => ['jwt.guard.auth:user', 'jwt.auth.refresh']], function () {
    Route::get('me/info', [AuthController::class, 'me'])->name('me.info');

    // :insert_code_here:do_not_delete
});
