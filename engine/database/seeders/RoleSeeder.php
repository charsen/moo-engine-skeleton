<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Mooeen\System\Models\Role;

/**
 * 授权角色。
 *
 * 仿 light-language-engine 的 RoleSeeder：只按名字建角色，不预置 ACL 动作
 * （骨架的 ACL 校验默认关闭 config('scaffold.authorization.check')=false；
 *  真要启用时，去 /scaffold 的「授权」页或后台勾选动作，会写回 role_next_actions 的 md5）。
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

        $this->command?->info('RoleSeeder：已建 系统管理员 / 开发 / 编辑员 三个角色。');
    }
}
