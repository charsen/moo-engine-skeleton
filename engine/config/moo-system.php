<?php

declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2026-05-25 14:15
 * @LastEditors: Charsen
 * @LastEditTime: 2026-06-02 11:59
 * @Description: moo-system 包默认配置
 *
 * host 不发布也能用，ServiceProvider boot() 时 mergeConfig。
 * host 如要覆盖，跑 `php artisan vendor:publish --tag=moo-system-config`
 * 后在 host config/moo-system.php 改值。
 */
return [

    /*
     * Admin 路由 wrap 配置
     *
     * 包 routes/admin.php 内只写 'notify-robots' 形式的相对路径，
     * ServiceProvider boot() 时按这里的值给路由统一加 prefix / name / middleware。
     *
     * 默认值与作者生产项目的 bootstrap/app.php 一致，
     * host 如果用了非标 prefix（多租户 / 多版本），覆盖这里就行。
     */
    'admin' => [
        'prefix' => 'api/admin',
        'name'   => 'admin.',
        // 指向 bootstrap/app.php 里自建的 'moo-system' 组（含完整 JWT 强制认证链：
        // jwt.assign.guard:admin + jwt.guard.auth:admin + jwt.auth.refresh）。
        'middleware' => 'moo-system',
    ],
];
