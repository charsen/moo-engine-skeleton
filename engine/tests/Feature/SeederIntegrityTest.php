<?php

declare(strict_types=1);
/*
 * Seeder 数据完整性守护。
 *
 * 重点是嵌套集树：DatabaseSeeder 一旦（无意中）用了 WithoutModelEvents，
 * kalnoy/nestedset 靠模型事件维护的 _lft/_rgt 会整树建坏——接口不报错、
 * toTree() 返回空，非常隐蔽（踩坑表 #9）。这里用包自带的 isBroken() 做回归闸门。
 */

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mooeen\System\Models\Department;
use Mooeen\System\Models\Personnel;
use Mooeen\System\Models\Position;
use Mooeen\System\Models\Role;
use Tests\TestCase;

class SeederIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** 跑 DatabaseSeeder：User → 角色 → 部门 → 岗位 → 人员 */
    protected $seed = true;

    public function test_department_nested_set_tree_is_valid(): void
    {
        // kalnoy/nestedset 自带的坏树检测（_lft/_rgt 错乱、孤儿节点都会命中）
        $this->assertFalse(Department::isBroken(), '部门嵌套集树结构损坏（多半是 seeder 静默了模型事件）');

        $root = Department::whereIsRoot()->first();
        $this->assertNotNull($root, '应有唯一根部门');

        // 5 个节点的满树：根的边界是 1..10（2 × 节点数）
        $this->assertSame(1, (int) $root->getLft());
        $this->assertSame(10, (int) $root->getRgt());

        $tech = Department::where('department_name', '技术部')->first();
        $this->assertCount(2, $tech->children, '技术部下应有两个团队');
    }

    public function test_position_department_ids_supports_json_query(): void
    {
        $tech = Department::where('department_name', '技术部')->first();

        // department_ids 必须存成 JSON 数组（["<id>"]），whereJsonContains 才能命中——
        // moo-system 的 getPositionCascader 就靠这个查询挂部门
        $count = Position::whereJsonContains('department_ids', (string) $tech->id)->count();

        $this->assertSame(2, $count, '技术部下应挂两个岗位');
    }

    public function test_seeded_accounts_exist_for_both_guards(): void
    {
        // admin 守卫主体：Personnel（手机登录）
        $admin = Personnel::where('mobile', '13800000000')->first();
        $this->assertNotNull($admin);
        $this->assertNotNull($admin->department_id, '管理员应挂到部门');
        $this->assertNotNull($admin->position_id, '管理员应挂到岗位');

        // user 守卫主体：自建 User（email 登录）
        $this->assertNotNull(User::where('email', 'admin@example.com')->first());
    }

    public function test_admin_role_carries_is_root_super_permission(): void
    {
        $role = Role::where('role_name', '系统管理员')->first();

        $this->assertSame(['is_root'], $role->role_actions, '系统管理员应持有 is_root 超级权限');
    }
}
