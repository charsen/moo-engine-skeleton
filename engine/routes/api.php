<?php

declare(strict_types=1);
/*
 * 客户端（移动端）接口路由（挂载前缀 app，见 bootstrap/app.php）
 *
 * 标记 `:insert_code_here:do_not_delete` 供 moo-scaffold 生成器插入路由，勿删。
 */

use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => 'Hello app api ~');

// :insert_code_here:do_not_delete
