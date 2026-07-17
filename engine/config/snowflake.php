<?php

declare(strict_types=1);

/*
 * 雪花算法 ID 配置 —— 独立成文件（原先内联在 config/moo-system.php，1-3 抽出）。
 *
 * 消费方：moo-scaffold 的 scaffold.snowflake 单例（见 ScaffoldProvider）复用 SNOW_FLAKE_* env，
 * 与本文件同源。UsingSnowFlakePrimaryKey trait 生成的主键最终都出自这套参数。
 *
 * ⚠ 血泪一：data_center_id / worker_id 各 5 bit（取值 0-31）。多机部署、且各机的 cache store
 *   （LaravelSequenceResolver 依赖它做机内同毫秒序列号）不共享时，每台机器的 (data_center_id,
 *   worker_id) 组合【必须唯一】，否则同毫秒生成的 ID 会撞主键。做法：每个部署的 .env 设不同的
 *   SNOW_FLAKE_WORKER_ID。
 *
 * ⚠ 血泪二：start_time 是 ID 时间戳的纪元基准。线上一旦在某个 (dc,worker) 命名空间下生成过 ID，
 *   此值在该命名空间内【永不可改】——改了会让新 ID 的时间戳位整体偏移，可能与历史 ID 相撞。
 *   要换纪元只能连 dc 位一起换到未用过的命名空间。
 *
 * ⚠ 血泪三：env() 只能在 config 文件里调用。生产 `php artisan config:cache` 后运行时 env() 返 null，
 *   所以业务代码统一读 config('snowflake.*')，【绝不直接 env()】。
 */

return [
    'data_center_id' => (int) env('SNOW_FLAKE_DATA_CENTER_ID', 1),
    'worker_id'      => (int) env('SNOW_FLAKE_WORKER_ID', 1),
    'start_time'     => env('SNOW_FLAKE_START_TIME', '2021-10-10'),
];
