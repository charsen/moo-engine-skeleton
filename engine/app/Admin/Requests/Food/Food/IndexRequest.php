<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Date: 2026-06-05 14:21
 * @LastEditors: charsen
 * @LastEditTime: 2026-06-05 14:21
 * @Description: IndexRequest
 */

namespace App\Admin\Requests\Food\Food;

use Mooeen\Scaffold\Foundation\FormRequest;

class IndexRequest extends FormRequest
{
    use FoodRequestTrait;

    /**
     * Get the validation rules that apply to the request.
     *
     * 注意：控制器是 ->filter($request->validated())——这里没放行的键到不了 ModelFilter，
     * 对应的筛选方法就是死代码。所以 rules 必须与 FoodFilter 的方法一一对齐。
     */
    public function rules(): array
    {
        return [
            'food_name'     => ['nullable', 'string', 'max:128'],
            'description'   => ['nullable', 'string', 'max:255'],
            'price'         => ['nullable', 'integer', 'min:0'],
            'calories'      => ['nullable', 'integer', 'min:0'],
            'food_category' => ['nullable', 'integer', $this->getInEnums($this->getValues('food_category'))],
            'food_status'   => ['nullable', 'integer', $this->getInEnums($this->getValues('food_status'))],
            'created_at'    => ['nullable', 'date'],
            'updated_at'    => ['nullable', 'date'],
            'deleted_at'    => ['nullable', 'date'],
            'page'          => ['required', 'integer', 'min:1'],
            'page_limit'    => ['required', 'integer', 'min:1', 'max:200'],
        ];
    }
}
