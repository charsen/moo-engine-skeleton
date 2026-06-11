<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Date: 2026-06-11 15:13
 * @LastEditors: charsen
 * @LastEditTime: 2026-06-11 15:13
 * @Description: FoodController's Trait（移动端只读，仅保留列表字段清单）
 */

namespace App\Api\Controllers\Food\Traits;

trait FoodTrait
{
    /**
     * 列表的查询字段（移动端白名单：不含 deleted_at）
     */
    private function getListFields(): array
    {
        return ['id', 'food_name', 'food_category', 'price', 'stock', 'calories', 'food_status', 'description', 'created_at', 'updated_at'];
    }
}
