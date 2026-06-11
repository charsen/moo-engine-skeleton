<?php

declare(strict_types=1);

/*
 * @Author: charsen
 * @Date: 2026-06-05 14:21
 * @LastEditors: Charsen
 * @LastEditTime: 2026-05-20 17:02
 * @Description: 食品控制器
 */

namespace App\Admin\Controllers\Food;

use App\Admin\Controllers\Food\Traits\FoodTrait;
use App\Admin\Controllers\Traits\BaseActionTrait;
use App\Admin\Requests\Food\Food\CreateRequest;
use App\Admin\Requests\Food\Food\DestroyBatchRequest;
use App\Admin\Requests\Food\Food\EditRequest;
use App\Admin\Requests\Food\Food\IndexRequest;
use App\Admin\Requests\Food\Food\StoreRequest;
use App\Admin\Requests\Food\Food\UpdateRequest;
use App\Admin\Resources\Food\FoodResource;
use App\Models\Food\Enums\FoodStatus;
use App\Models\Food\Food;
use Mooeen\Scaffold\Foundation\BaseResource;
use Mooeen\Scaffold\Foundation\BaseResourceCollection;
use Mooeen\Scaffold\Foundation\ColumnsCollection;
use Mooeen\Scaffold\Foundation\Controller;
use Mooeen\Scaffold\Foundation\FormWidgetCollection;

/**
 * ACL
 *
 * @package_name {zh-CN: 后台管理 | en: Admin}
 *
 * @module_name {zh-CN: 食品管理 | en: Food}
 *
 * @controller_name {zh-CN: 食品管理 | en: Management Food}
 */
class FoodController extends Controller
{
    use BaseActionTrait;
    use FoodTrait;

    protected Food $model;

    /**
     * 权限复用映射：toggleStatus 不单独注册 ACL 动作，
     * 鉴权时映射到 update 的权限（同 restore => trashed 的默认映射）
     */
    protected array $transform_methods = [
        'toggleStatus' => 'update',
    ];

    public function __construct(Food $model)
    {
        $this->model = $model;
    }

    /**
     * 执行 action 前先验证权限
     */
    public function boot(): void
    {
        $this->checkAuthorization();
    }

    /**
     * 食品列表
     *
     * @acl {zh-CN: 食品列表, en: Food List, desc: }
     */
    public function index(IndexRequest $request): BaseResourceCollection
    {
        $validated = $request->validated();

        $result = $this->model->select($this->getListFields())
            ->filter($validated)
            ->latest('id')
            ->paginate(($validated['page_limit'] ?? null));
        $result->append(['options']);

        return BaseResource::collection($result)
            ->additional([
                'columns' => $this->getListColumns(),
                'form_widgets' => $this->getListFormWidgets($request),
            ]);
    }

    /**
     * 食品回收站
     *
     * @acl {zh-CN: 食品回收站, en: Food Trashed, desc: }
     */
    public function trashed(IndexRequest $request): BaseResourceCollection
    {
        $validated = $request->validated();

        $result = $this->model->select($this->getListFields('trashed'))
            ->filter($validated)
            ->latest('deleted_at')
            ->onlyTrashed()
            ->paginate(($validated['page_limit'] ?? null));
        $result->append(['options']);

        // 专属 Resource + 链式 ->trashed()：deleted_at 只在回收站出现（见 FoodResource::whenTrashed）
        return FoodResource::collection($result)
            ->trashed()
            ->additional([
                'columns' => $this->getListColumns('trashed'),
                'form_widgets' => $this->getListFormWidgets($request, 'trashed'),
            ]);
    }

    /**
     * 创建食品
     *
     * @acl {zh-CN: 创建食品, en: Create Food, desc: }
     */
    public function store(StoreRequest $request): BaseResource
    {
        $validated = $request->validated();

        $result = $this->model->create($validated);

        return BaseResource::make($result);
    }

    /**
     * 更新食品
     *
     * @acl {zh-CN: 更新食品, en: Update Food, desc: }
     */
    public function update(UpdateRequest $request, int|string $id): BaseResource
    {
        $validated = $request->validated();

        $result = $this->model->findOrFail($id);
        $result->fill($validated);
        $result->save();

        return BaseResource::make($result);
    }

    /**
     * 查看食品
     *
     * @acl {zh-CN: 查看食品, en: Show Food, desc: }
     */
    public function show(int|string $id): BaseResource
    {
        $fields = ['id', 'food_name', 'food_category', 'price', 'stock', 'calories', 'food_status', 'description', 'deleted_at', 'created_at', 'updated_at'];
        $result = $this->model->select($fields)->withTrashed()->findOrFail($id);
        $result->append(['options']);

        $columns = ['id', 'food_name', 'food_category', 'price', 'stock', 'calories', 'food_status', 'description', 'deleted_at', 'created_at', 'updated_at'];

        // 专属 Resource：created_at 输出 'Y-m-d H:i'；详情查的是 withTrashed，
        // 已软删的记录链 ->trashed(true) 让 deleted_at 一并输出
        return FoodResource::make($result)
            ->trashed($result->trashed())
            ->additional(['columns' => ColumnsCollection::make($columns)]);
    }

    /**
     * 删除食品
     *
     * @acl {zh-CN: 删除食品, en: Destroy Food, desc: }
     */
    public function destroyBatch(DestroyBatchRequest $request): BaseResource
    {
        return $this->destroyBatchAction($request);
    }

    /**
     * 永久删除食品
     *
     * @acl {zh-CN: 永久删除食品, en: Destroy Forever Food, desc: }
     */
    public function forceDestroy(int|string $id): BaseResource
    {
        return $this->forceDestroyAction($id);
    }

    /**
     * 恢复食品
     */
    public function restore(DestroyBatchRequest $request): BaseResource
    {
        return $this->restoreAction($request);
    }

    /**
     * 创建表单
     */
    public function create(CreateRequest $request): FormWidgetCollection
    {
        return $this->getFormWidgets(new StoreRequest, 'create');
    }

    /**
     * 编辑表单
     */
    public function edit(EditRequest $request, int|string $id): BaseResource
    {
        $result = $this->model->findOrFail($id);

        return BaseResource::make($result)
            ->additional([
                'form_widgets' => $this->getFormWidgets(new UpdateRequest, 'edit'),
            ]);
    }

    /**
     * 上架/下架切换（权限复用 update，见 $transform_methods）
     */
    public function toggleStatus(int|string $id): BaseResource
    {
        $result = $this->model->findOrFail($id);
        $result->food_status = $result->food_status === FoodStatus::ON_SHELF->value
            ? FoodStatus::OFF_SHELF->value
            : FoodStatus::ON_SHELF->value;
        $result->save();

        return BaseResource::make($result);
    }
}
