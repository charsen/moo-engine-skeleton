<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Date: 2026-06-11 15:33
 * @LastEditors: charsen
 * @LastEditTime: 2026-06-11 15:33
 * @Description: 食品 资源
 */

namespace App\Admin\Resources\Food;

use Illuminate\Http\Request;
use Mooeen\Scaffold\Foundation\BaseResource;

class FoodResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * 条件字段方法（全部来自 BaseResource / Laravel JsonResource，缺席的键不会出现在 JSON 里）：
     * - whenHas('x')         仅当查询 select 了该列才输出（窄 select 的列表不会出现 null 噪音）
     * - whenAppended('x')    仅当 ->append(['x']) 过才输出（枚举 _txt、options 动作列表）
     * - whenDate('x', fmt)   仅当列存在时输出，并按 fmt 格式化（默认 'Y-m-d H:i'）
     * - whenTrashed($v)      仅当链式调用过 ->trashed() 才输出（回收站专属字段）
     */
    public function toArray(Request $request): array
    {
        $data = collect([
            'id'                => $this->id,
            'food_name'         => $this->food_name,
            'food_category'     => $this->whenHas('food_category'),
            'food_category_txt' => $this->whenAppended('food_category_txt'),
            'price'             => $this->whenHas('price'),
            'stock'             => $this->whenHas('stock'),
            'calories'          => $this->whenHas('calories'),
            'food_status'       => $this->whenHas('food_status'),
            'food_status_txt'   => $this->whenAppended('food_status_txt'),
            'description'       => $this->whenHas('description'),
            'deleted_at'        => $this->whenTrashed($this->deleted_at?->format('Y-m-d H:i')),
            'created_at'        => $this->whenDate('created_at', 'Y-m-d H:i'),
            'updated_at'        => $this->whenHas('updated_at'),
            'options'           => $this->whenAppended('options'),
        ]);

        // 最后过 filterFields()，调用方仍可链 ->show('id,food_name') / ->hide('price') 二次裁剪
        return $this->filterFields($data);
    }
}
