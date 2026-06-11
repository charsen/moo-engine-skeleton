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
        'name' => 'admin.',
        // 指向 bootstrap/app.php 里自建的 'moo-system' 组（含完整 JWT 强制认证链：
        // jwt.assign.guard:admin + jwt.guard.auth:admin + jwt.auth.refresh）。
        'middleware' => 'moo-system',
    ],

    /*
     * 雪花算法主键生成参数
     *
     * 唯一性两层保障：
     *   1. 跨机：多机部署时 data_center_id / worker_id 必须区分，否则会撞 ID。
     *   2. 机内：同一毫秒内的多个 ID 靠序列号区分，计数器存在 Cache 里（LaravelSequenceResolver）。
     *      => 生产环境必须用【跨进程共享的 cache store（如 Redis）】；array / 进程内驱动在多 worker
     *         并发下不防撞，可能同毫秒重号。
     *
     * host 在 .env 设置以下变量即可：
     *   SNOW_FLAKE_DATA_CENTER_ID
     *   SNOW_FLAKE_WORKER_ID
     *   SNOW_FLAKE_START_TIME
     */
    'snowflake' => [
        'data_center_id' => env('SNOW_FLAKE_DATA_CENTER_ID', 1),
        'worker_id' => env('SNOW_FLAKE_WORKER_ID', 1),
        'start_time' => env('SNOW_FLAKE_START_TIME', '2021-10-10'),
    ],
];
