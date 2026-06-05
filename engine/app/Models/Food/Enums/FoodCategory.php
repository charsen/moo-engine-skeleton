<?php

declare(strict_types=1);

namespace App\Models\Food\Enums;

use Mooeen\Scaffold\Concerns\EnumExtend;

/**
 * 食品 模型的 分类 字段枚举
 */
enum FoodCategory: int
{
    use EnumExtend;

    case FRUIT = 1;
    case VEGETABLE = 2;
    case MEAT = 3;
    case STAPLE = 4;

    public static function getLabel(self $value): string
    {
        return match ($value) {
            self::FRUIT => __('model.food_category_fruit'),
            self::VEGETABLE => __('model.food_category_vegetable'),
            self::MEAT => __('model.food_category_meat'),
            self::STAPLE => __('model.food_category_staple'),
        };
    }
}
