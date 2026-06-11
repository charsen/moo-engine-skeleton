<?php

declare(strict_types=1);

namespace App\Models\Traits;

use Exception;
use Godruoyi\Snowflake\LaravelSequenceResolver;
use Godruoyi\Snowflake\Snowflake;

/**
 * 雪花算法 ID
 */
trait UsingSnowFlakePrimaryKey
{
    /**
     * @throws Exception
     */
    public static function bootUsingSnowFlakePrimaryKey(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->{$model->getKeyName()})) {
                // 读 config 键（moo-system 的 mergeConfigFrom 未 publish 也生效），不能写 env 名——
                // 原先的 config('SNOW_FLAKE_DATA_CENTER_ID') 是把 env 名当 config 键，永远落回默认值，
                // 多机部署在 .env 里区分 WORKER_ID 会静默失效（单机教学场景无症状）。
                $snow_flake = new Snowflake(
                    (int) config('moo-system.snowflake.data_center_id', 1),
                    (int) config('moo-system.snowflake.worker_id', 1),
                );
                $snow_flake->setStartTimeStamp(strtotime((string) config('moo-system.snowflake.start_time', '2021-10-10')) * 1000)
                    ->setSequenceResolver(new LaravelSequenceResolver(app('cache')->store()));

                $model->{$model->getKeyName()} = $snow_flake->id();
            }
        });
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * Get the auto-incrementing key type.
     */
    public function getKeyType(): string
    {
        return 'int';
    }
}
