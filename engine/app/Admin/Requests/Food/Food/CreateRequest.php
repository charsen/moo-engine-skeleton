<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Date: 2026-06-05 14:21
 * @LastEditors: charsen
 * @LastEditTime: 2026-06-05 14:21
 * @Description: CreateRequest
 */

namespace App\Admin\Requests\Food\Food;

use Mooeen\Scaffold\Foundation\FormRequest;

class CreateRequest extends FormRequest
{
    use FoodRequestTrait;

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
        ];
    }
}
