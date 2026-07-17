<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Mooeen\System\Models\Department;
use Mooeen\System\Models\Personnel;
use Mooeen\System\Models\Position;
use Mooeen\System\Models\Role;

/**
 * 管理员人员。让 `migrate:fresh --seed` 之后就有一个能登录的账号，无需再 tinker。
 *
 * - 手机 13800000000 / 密码 admin888（Personnel 的 setPasswordAttribute 会自动 bcrypt）
 * - 挂到「技术部」+「后端工程师」岗位，并授予「系统管理员」角色
 * - 依赖 DepartmentSeeder / PositionSeeder / RoleSeeder 先跑
 *
 * 运行：php artisan db:seed --class=PersonnelSeeder
 */
class PersonnelSeeder extends Seeder
{
    public function run(): void
    {
        $department = Department::where('department_name', '技术部')->first();
        $position   = Position::where('position_name', '后端工程师')->first();
        $role       = Role::where('role_name', '系统管理员')->first();

        $admin                     = Personnel::firstOrNew(['mobile' => '13800000000']);
        $admin->real_name          = '管理员';
        $admin->department_id      = $department?->id;
        $admin->position_id        = $position?->id;
        $admin->staff_status       = 7; // 在职
        $admin->account_status     = 7; // 正常
        $admin->password           = 'admin888'; // 明文，mutator 自动 hash
        $admin->created_account_at = now();
        $admin->save();

        // 授予角色（多对多，幂等）
        if ($role !== null) {
            $admin->roles()->syncWithoutDetaching([$role->id]);
        }

        $this->command?->info('PersonnelSeeder：管理员 13800000000 / admin888（技术部·后端工程师·系统管理员）。');
    }
}
