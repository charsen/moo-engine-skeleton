<?php

declare(strict_types=1);

namespace App\Api\Requests\Food\Food;

use App\Models\Food\Enums\FoodCategory;
use App\Models\Food\Enums\FoodStatus;

trait FoodRequestTrait
{
    public function getTable(): string
    {
        return 'foods';
    }

    public function getValues(string $field): array
    {
        $values = [
            'food_category' => FoodCategory::values(),
            'food_status' => FoodStatus::values(),
        ];

        return $values[$field] ?? [];
    }

    /**
     * 控制器生成前端表单控件时，获取 options 选项数据
     */
    public function options(string $field): array
    {
        $options = [
            'food_category' => FoodCategory::valueLabels(),
            'food_status' => FoodStatus::valueLabels(),
        ];

        return $options[$field] ?? [];
    }
}
