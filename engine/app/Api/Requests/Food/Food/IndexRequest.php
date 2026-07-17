<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Date: 2026-06-11 15:13
 * @LastEditors: charsen
 * @LastEditTime: 2026-06-11 15:13
 * @Description: IndexRequest
 */

namespace App\Api\Requests\Food\Food;

use Mooeen\Scaffold\Foundation\FormRequest;

class IndexRequest extends FormRequest
{
    use FoodRequestTrait;

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'food_name'     => ['nullable', 'string', 'max:128'],
            'food_category' => ['nullable', 'integer', $this->getInEnums($this->getValues('food_category'))],
            'food_status'   => ['nullable', 'integer', $this->getInEnums($this->getValues('food_status'))],
            'page'          => ['required', 'integer', 'min:1'],
            'page_limit'    => ['required', 'integer', 'min:1'],
        ];
    }
}
