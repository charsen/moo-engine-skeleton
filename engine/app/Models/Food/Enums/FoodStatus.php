<?php

declare(strict_types=1);

namespace App\Models\Food\Enums;

use Mooeen\Scaffold\Concerns\EnumExtend;

/**
 * 食品 模型的 状态 字段枚举
 */
enum FoodStatus: int
{
    use EnumExtend;

    case ON_SHELF = 1;
    case OFF_SHELF = 2;

    public static function getLabel(self $value): string
    {
        return match ($value) {
            self::ON_SHELF => __('model.food_status_on_shelf'),
            self::OFF_SHELF => __('model.food_status_off_shelf'),
        };
    }
}
