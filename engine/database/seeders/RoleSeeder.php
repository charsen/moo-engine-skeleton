<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Mooeen\System\Models\Role;

/**
 * 授权角色。
 *
 * ACL 校验已开启（config('scaffold.authorization.check')=true，docs 第 5 章）：
 * - 「系统管理员」授 is_root 字面量 = 超级权限（雪花主键下不存在 id=1 的天然 root，
 *   靠它兜底，Gate 见 App\Providers\AuthServiceProvider）；
 * - 「开发 / 编辑员」不预置动作，按需到后台「授权」模块或用 tinker 勾选，
 *   勾选结果以 md5 key 形式写进 role_next_actions（逗号拼接）。
 *
 * 运行：php artisan db:seed --class=RoleSeeder
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['系统管理员', '开发', '编辑员'] as $name) {
            Role::firstOrCreate(['role_name' => $name]);
        }

        // 超级权限（role_actions 的 mutator 会写到 role_next_actions 列）
        $admin_role = Role::where('role_name', '系统管理员')->first();
        $admin_role->role_actions = ['is_root'];
        $admin_role->save();

        $this->command?->info('RoleSeeder：已建 系统管理员（is_root 超级权限）/ 开发 / 编辑员 三个角色。');
    }
}
