<?php

declare(strict_types=1);

/*
 * @Author: charsen
 * @Date: 2026-06-11 15:13
 * @LastEditors: charsen
 * @LastEditTime: 2026-06-11 15:13
 * @Description: 食品控制器（移动端只读：index + show）
 */

namespace App\Api\Controllers\Food;

use App\Api\Controllers\Food\Traits\FoodTrait;
use App\Api\Controllers\Traits\BaseActionTrait;
use App\Api\Requests\Food\Food\IndexRequest;
use App\Models\Food\Food;
use Mooeen\Scaffold\Foundation\BaseResource;
use Mooeen\Scaffold\Foundation\BaseResourceCollection;
use Mooeen\Scaffold\Foundation\Controller;

/**
 * ACL
 *
 * @package_name {zh-CN: 客户端接口 | en: Api}
 *
 * @module_name {zh-CN: 食品管理 | en: Food}
 *
 * @controller_name {zh-CN: 食品 | en: Management Food}
 */
class FoodController extends Controller
{
    use BaseActionTrait;
    use FoodTrait;

    protected Food $model;

    public function __construct(Food $model)
    {
        $this->model = $model;
    }

    /**
     * 食品列表
     *
     * 移动端不需要后台的 columns / form_widgets / options（行动作列表），
     * 只返回数据 + 分页 meta。
     */
    public function index(IndexRequest $request): BaseResourceCollection
    {
        $validated = $request->validated();

        $result = $this->model->select($this->getListFields())
            ->filter($validated)
            ->latest('id')
            ->paginate(($validated['page_limit'] ?? null));

        return BaseResource::collection($result);
    }

    /**
     * 查看食品
     *
     * 字段白名单比后台精简：不暴露 deleted_at（移动端没有回收站概念），
     * 也不 withTrashed —— 软删的食品对移动端就是 404。
     */
    public function show(int|string $id): BaseResource
    {
        $fields = ['id', 'food_name', 'food_category', 'price', 'stock', 'calories', 'food_status', 'description', 'created_at', 'updated_at'];
        $result = $this->model->select($fields)->findOrFail($id);

        return BaseResource::make($result);
    }
}
