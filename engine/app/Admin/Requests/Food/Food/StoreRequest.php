<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Date: 2026-06-05 14:21
 * @LastEditors: charsen
 * @LastEditTime: 2026-06-05 14:21
 * @Description: StoreRequest
 */

namespace App\Admin\Requests\Food\Food;

use Mooeen\Scaffold\Foundation\FormRequest;

class StoreRequest extends FormRequest
{
    use FoodRequestTrait;

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'food_name'     => ['required', 'string', 'min:2', 'max:128', $this->getUnique($this->getTable(), 'food_name')],
            'food_category' => ['required', 'integer', $this->getInEnums($this->getValues('food_category'))],
            'price'         => ['required', 'integer', 'min:0'],
            'stock'         => ['sometimes', 'required', 'integer', 'min:0'],
            'calories'      => ['nullable', 'integer', 'min:0'],
            'food_status'   => ['required', 'integer', $this->getInEnums($this->getValues('food_status'))],
            'description'   => ['nullable', 'string', 'max:255'],
        ];
    }

    public function formLayout(): array
    {
        return [
            ['food_name'],
            ['food_category'],
            ['price'],
            ['stock'],
            ['calories'],
            ['food_status'],
            ['description'],
        ];
    }
}
