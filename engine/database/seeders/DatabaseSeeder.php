<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * 顺序：自建用户（第 3 章 JWT 主体）→ 角色 → 部门 → 岗位 → 人员（人员要引用前三者）。
     *
     * 注意：不要用 WithoutModelEvents —— Department 的嵌套集树（kalnoy/nestedset）
     * 依赖 creating/saving 模型事件维护 _lft/_rgt，静默事件会建出坏树。
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            RoleSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            PersonnelSeeder::class,
        ]);
    }
}
