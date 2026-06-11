<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 跨域资源共享（CORS）
    |--------------------------------------------------------------------------
    |
    | Laravel 12 的 HandleCors 中间件默认就在全局栈里，但框架内置默认值
    | 只覆盖 api/* 且不暴露任何响应头 —— 对本骨架的 JWT 无感续签是致命的：
    | JWTAuthOrRefresh 把续签出的新 token 放在 authorization 响应头里，
    | 跨域场景（H5/webview/前后端分离调试）下浏览器读不到未暴露的响应头，
    | 新 token 直接丢失 → 旧 token 出黑名单宽限窗后 401。
    | 因此发布本文件，暴露 Authorization 并把 app/* 也纳入（生产项目同款配置）。
    |
    */

    // api/*：后台前缀 api/admin；app/*：移动端前缀（bootstrap/app.php prefix('app')）
    'paths' => ['api/*', 'app/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // 暴露 authorization 响应头，前端才能接住无感续签的新 token
    'exposed_headers' => ['Authorization'],

    'max_age' => 0,

    'supports_credentials' => false,

];
