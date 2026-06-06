<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Mooeen\System\Models\Department;
use Mooeen\System\Models\Enums\DepartmentType;

/**
 * 根部门（总公司）+ 一棵小组织树。
 *
 * moo-system 的「总公司」根部门有专门的初始化语义（department_type=1，且系统里只有一个），
 * 走接口不好建（store 要求 cascader 的 parent_id），所以用 seeder 落初始数据。
 * Department 是嵌套集树（kalnoy/nestedset）：根节点 parent_id 为空，子节点用 appendToNode()。
 *
 * 运行：php artisan db:seed --class=DepartmentSeeder
 */
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        // 幂等：已有部门（含软删）则跳过，避免重复建根
        if (Department::withTrashed()->exists()) {
            $this->command?->warn('system_departments 已有数据，跳过 DepartmentSeeder。');

            return;
        }

        // 1) 根部门：总公司（HEAD_OFFICE = 1）
        $root = Department::create([
            'department_name' => '猫途科技',
            'department_code' => 'HQ',
            'department_type' => DepartmentType::HEAD_OFFICE->value,
        ]);

        // 2) 一级部门（DEPARTMENT_OFFICE = 3）
        $tech = $this->child('技术部', DepartmentType::DEPARTMENT_OFFICE, $root, 'TECH');
        $this->child('市场部', DepartmentType::DEPARTMENT_OFFICE, $root, 'MKT');

        // 3) 技术部下的团队（TEAM = 4）
        $this->child('后端组', DepartmentType::TEAM, $tech, 'TECH-BE');
        $this->child('前端组', DepartmentType::TEAM, $tech, 'TECH-FE');

        // 人员挂到哪个部门由 PersonnelSeeder 负责（这里只管组织树）
        $this->command?->info('DepartmentSeeder：已建「猫途科技」根部门 + 4 个子部门。');
    }

    /**
     * 在 $parent 下追加一个子部门（维护嵌套集树 _lft/_rgt）。
     */
    private function child(string $name, DepartmentType $type, Department $parent, ?string $code = null): Department
    {
        $node = new Department([
            'department_name' => $name,
            'department_code' => $code,
            'department_type' => $type->value,
        ]);
        $node->appendToNode($parent)->save();

        return $node;
    }
}
