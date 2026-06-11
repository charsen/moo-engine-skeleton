<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Date: 2026-06-05 14:21
 * @LastEditors: Charsen
 * @LastEditTime: 2026-05-10 13:48
 * @Description: FoodController's Trait
 */

namespace App\Admin\Controllers\Food\Traits;

use Mooeen\Scaffold\Foundation\FormRequest;
use Mooeen\Scaffold\Foundation\FormWidgetCollection;
use Mooeen\Scaffold\Foundation\TableColumnsCollection;

trait FoodTrait
{
    /**
     * 列表的查询字段
     */
    private function getListFields(string $action = 'index'): array
    {
        $fields = ['id', 'food_name', 'food_category', 'price', 'stock', 'calories', 'food_status', 'description', 'created_at'];

        if ($action === 'index') {
            $append = ['updated_at'];
        } else {
            $append = ['deleted_at'];
        }

        return [...$fields, ...$append];
    }

    /**
     * 列表的表头
     */
    private function getListColumns(string $action = 'index'): TableColumnsCollection
    {
        $columns = [
            'food_name',
            'food_category_txt',
            'price',
            'stock',
            'calories',
            'food_status_txt',
            'description',
        ];

        return TableColumnsCollection::makeColumns($columns, $action);
    }

    /**
     * 列表的表单控件
     */
    private function getListFormWidgets(FormRequest $request, string $action = 'index', array $reset = []): FormWidgetCollection
    {
        return FormWidgetCollection::makeSearch($request, $reset);
    }

    /**
     * Create|Edit 的表单控件
     */
    private function getFormWidgets(FormRequest $request, string $method, array $reset = []): FormWidgetCollection
    {
        $default = [
            ...$reset,
        ];

        return FormWidgetCollection::makeForm($request, $default, $method === 'create');
    }
}
