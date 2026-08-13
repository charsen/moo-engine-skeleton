<?php declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// moo-scaffold 的 web 端资源路由插入锚点；不要删除。
// :insert_code_here:do_not_delete
