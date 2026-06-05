<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Date: 2026-06-05 14:18
 * @LastEditors: charsen
 * @LastEditTime: 2026-06-05 14:18
 * @Description: FoodFilter
 */

namespace App\Models\Food\Filters;

use App\Models\BaseFilter;

class FoodFilter extends BaseFilter
{
    /**
     * Related Models that have ModelFilters as well as the method on the ModelFilter
     * As [relationMethod => [input_key1, input_key2]].
     *
     * @var array
     */
    public $relations = [];

    public function food_name($str)
    {
        return $this->where('food_name', 'LIKE', "%{$str}%");
    }

    public function price($int)
    {
        $int = is_array($int) ? $int : [$int];

        return $this->whereIn('price', $int);
    }

    public function calories($int)
    {
        $int = is_array($int) ? $int : [$int];

        return $this->whereIn('calories', $int);
    }

    public function description($str)
    {
        return $this->where('description', 'LIKE', "%{$str}%");
    }

    public function deleted_at($date)
    {
        return $this->whereDate('deleted_at', $date);
    }

    public function created_at($date)
    {
        return $this->whereDate('created_at', $date);
    }

    public function updated_at($date)
    {
        return $this->whereDate('updated_at', $date);
    }

    public function food_category($int)
    {
        $int = is_array($int) ? $int : [$int];

        return $this->whereIn('food_category', $int);
    }

    public function food_status($int)
    {
        $int = is_array($int) ? $int : [$int];

        return $this->whereIn('food_status', $int);
    }
}
