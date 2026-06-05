<?php

declare(strict_types=1);

namespace App\Models\Food\Traits;

use App\Models\Food\Enums\FoodCategory;
use App\Models\Food\Enums\FoodStatus;

/**
 * FoodTrait
 *
 * - 会被生成直接覆盖，所以不要在这里写代码
 */
trait FoodTrait
{
    /**
     * 获取 分类 TXT
     */
    public function getFoodCategoryTxtAttribute(): ?string
    {
        try {
            return FoodCategory::from((int) $this->food_category)->label();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 获取 状态 TXT
     */
    public function getFoodStatusTxtAttribute(): ?string
    {
        try {
            return FoodStatus::from((int) $this->food_status)->label();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
