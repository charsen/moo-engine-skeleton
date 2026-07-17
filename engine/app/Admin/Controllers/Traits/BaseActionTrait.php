<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Description: 基础模型操作 Trait（host 契约：moo-system 与 scaffold 生成的控制器都依赖它）
 *
 * 移植自作者生产项目，提供 destroyBatchAction / restoreAction / forceDestroyAction
 * 以及 moo-system 的 Department 树需要的 getNodeAncestors 等方法。
 */

namespace App\Admin\Controllers\Traits;

use Closure;
use Illuminate\Validation\ValidationException;
use Mooeen\Scaffold\Foundation\BaseResource;
use Mooeen\System\Models\Department;
use Mooeen\System\Models\Enums\StaffStatus;
use Mooeen\System\Models\Position;

trait BaseActionTrait
{
    /**
     * 删除
     */
    private function destroyAction(int|string $id): BaseResource
    {
        $result = $this->model->findOrFail($id);
        $result->delete();

        return BaseResource::make($result);
    }

    /**
     * 批量删除
     *
     * @throws ValidationException
     */
    private function destroyBatchAction($request, ?Closure $afterDelete = null): BaseResource
    {
        $validated = $request->validated();
        $model_ids = is_array($validated['ids']) ? $validated['ids'] : [$validated['ids']];

        $data   = $this->model->whereKey($model_ids)->get();
        $result = $data->map(function ($item) use ($afterDelete) {
            if ($item->delete()) {
                if ($afterDelete) {
                    $afterDelete($item);
                }

                return $item;
            }
        });

        if (count($result) < 1) {
            throw ValidationException::withMessages(['ids' => ['No batch operation results.']]);
        }

        return BaseResource::make($result);
    }

    /**
     * 永久删除
     */
    private function forceDestroyAction(int|string $id): BaseResource
    {
        $result = $this->model->onlyTrashed()->findOrFail($id);
        $result->forceDelete();

        return BaseResource::make($result);
    }

    /**
     * 恢复
     *
     * @throws ValidationException
     */
    private function restoreAction($request): BaseResource
    {
        $validated = $request->validated();
        $model_ids = is_array($validated['ids']) ? $validated['ids'] : [$validated['ids']];

        $data   = $this->model->onlyTrashed()->whereKey($model_ids)->get();
        $result = $data->map(function ($item) {
            if ($item->restore()) {
                return $item;
            }
        });

        if (count($result) < 1) {
            throw ValidationException::withMessages(['ids' => ['No batch operation results.']]);
        }

        return BaseResource::make($result);
    }

    /**
     * 获取表单 select 控件配置
     */
    private function getSelectForForm($model, string $field = 'category_name', bool $multiple = false, $control = null): array
    {
        $data = is_string($model)
            ? resolve($model)->get()->toArray()
            : $model->toArray();

        $res = [
            'type'     => 'select',
            'multiple' => $multiple,
            'options'  => $data,
            'filter'   => ['label-value', 'id', $field],
        ];

        if ($control !== null) {
            $res['control'] = $control;
        }

        return $res;
    }

    /**
     * 获取表单树状控件的配置
     */
    private function getCascaderForForm($model, string $field = 'category_name', bool $multiple = true, bool $strictly = false, bool $array = false, $control = null, $tip = null): array
    {
        $data = is_string($model)
            ? resolve($model)->defaultOrder()->get()->toTree()->toArray()
            : $model->toTree()->toArray();

        $res = [
            'type'     => 'cascader',
            'multiple' => $multiple,
            'strictly' => $strictly,
            'options'  => $data,
            'array'    => $array,
            'tip'      => $tip,
            'filter'   => ['label-value', 'id', $field],
        ];

        if ($control !== null) {
            $res['control'] = $control;
        }

        return $res;
    }

    /**
     * 获取人员关联部门级联表单控件
     */
    private function getPersonnelCascader($model = null, $multiple = false, $array = true, $strictly = false): array
    {
        if ($model === null) {
            $departments = Department::defaultOrder();
            $model       = $departments->with(['personnels' => function ($query) {
                $query->where('system_personnels.staff_status', StaffStatus::ON_JOB->value);
            }])->get();
        }

        return [
            'type'     => 'cascader',
            'multiple' => $multiple,
            'strictly' => $strictly,
            'array'    => $array,
            'options'  => $model->toTree()->toArray(),
            'filter'   => ['label-value', 'id', 'department_name', '', ['personnels', 'id', 'real_name', '@']],
        ];
    }

    /**
     * 获取所有祖先及自己 IDs 数组（Department 树用）
     */
    private function getNodeAncestors($model, $id, bool $only_ids = true, bool $self = true, bool $parent_null = true): mixed
    {
        $query = $parent_null
            ? resolve($model)->defaultOrder()
            : resolve($model)->defaultOrder()->whereNotNull('parent_id');

        $res = $self ? $query->ancestorsAndSelf($id) : $query->ancestorsOf($id);

        return $only_ids ? $res->pluck('id') : $res;
    }

    /**
     * 获取部门及其职位的级联表单控件
     */
    private function getPositionCascader($multiple = false, $array = false, $strictly = false): array
    {
        $departments = Department::defaultOrder();

        $model = $departments->get()->map(function ($item) {
            $item->positions = Position::whereJsonContains('department_ids', $item->id)->get();

            return $item;
        });

        return [
            'type'     => 'cascader',
            'multiple' => $multiple,
            'strictly' => $strictly,
            'array'    => $array,
            'options'  => $model->toTree()->toArray(),
            'filter'   => ['label-value', 'id', 'display_name', '', ['positions', 'id', 'position_name']],
        ];
    }
}
