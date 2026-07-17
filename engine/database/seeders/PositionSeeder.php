<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Mooeen\System\Models\Department;
use Mooeen\System\Models\Position;

/**
 * 岗位。挂在 DepartmentSeeder 建好的部门下。
 *
 * Position.department_ids 是 json 字段，存「部门 id 数组」（用 whereJsonContains 反查），
 * 这里存成 ["<部门 id>"]。依赖 DepartmentSeeder 先跑。
 *
 * 运行：php artisan db:seed --class=PositionSeeder
 */
class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $tech   = Department::where('department_name', '技术部')->first();
        $market = Department::where('department_name', '市场部')->first();

        // [岗位名, 所属部门]
        $map = [
            ['后端工程师', $tech],
            ['前端工程师', $tech],
            ['市场专员', $market],
        ];

        foreach ($map as [$name, $dept]) {
            Position::firstOrCreate(
                ['position_name' => $name],
                ['department_ids' => $dept ? [(string) $dept->id] : null],
            );
        }

        $this->command?->info('PositionSeeder：已建 后端工程师 / 前端工程师 / 市场专员 三个岗位。');
    }
}
