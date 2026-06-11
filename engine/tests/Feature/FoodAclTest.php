<?php

declare(strict_types=1);
/*
 * ACL 鉴权链路守护测试（docs 第 6 章）。
 *
 * 完整闭环：无 token 401 → 有 token 无权限 403 → 角色授权后 200。
 * 顺带守住「系统管理员角色的 is_root 字面量 = 超级权限」这条 Gate 约定。
 */

namespace Tests\Feature;

use App\Admin\Controllers\Food\FoodController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mooeen\Scaffold\Foundation\Controller;
use Mooeen\System\Models\Personnel;
use Mooeen\System\Models\Role;
use Tests\TestCase;

class FoodAclTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    /** food.index 的 ACL key：aclPlainKey 生成明文 key，再按 scaffold.authorization.md5 取 md5 中段 */
    private function foodIndexAclKey(): string
    {
        $plain = Controller::aclPlainKey(FoodController::class.'::index');

        return substr(md5($plain), 8, 16);
    }

    private function tokenFor(string $mobile, string $password = 'admin888'): string
    {
        return $this->postJson('api/admin/authenticate', [
            'account' => $mobile,
            'password' => $password,
        ])->assertOk()->json('data.token');
    }

    /** 造一个挂「编辑员」角色（无任何授权动作）的人员 */
    private function makeEditor(): Personnel
    {
        $editor = Personnel::firstOrNew(['mobile' => '13900000000']);
        $editor->real_name = '编辑小王';
        $editor->staff_status = 7;
        $editor->account_status = 7;
        $editor->password = 'editor888';
        $editor->created_account_at = now();
        $editor->save();
        $editor->roles()->syncWithoutDetaching([Role::where('role_name', '编辑员')->first()->id]);

        return $editor;
    }

    public function test_food_requires_token(): void
    {
        $this->getJson('api/admin/food')->assertStatus(401);
    }

    public function test_admin_role_with_is_root_passes_acl(): void
    {
        $token = $this->tokenFor('13800000000');

        $this->getJson('api/admin/food?page=1&page_limit=10', ['Authorization' => "Bearer {$token}"])
            ->assertOk();
    }

    public function test_role_without_action_gets_403(): void
    {
        $this->makeEditor();
        $token = $this->tokenFor('13900000000', 'editor888');

        $this->getJson('api/admin/food?page=1&page_limit=10', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);
    }

    public function test_granting_action_to_role_turns_403_into_200(): void
    {
        $this->makeEditor();
        $token = $this->tokenFor('13900000000', 'editor888');

        // 授权前 403
        $this->getJson('api/admin/food?page=1&page_limit=10', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);

        // 给「编辑员」角色授 food.index（与后台「授权」模块勾选动作等效）
        $role = Role::where('role_name', '编辑员')->first();
        $role->role_actions = [$this->foodIndexAclKey()];
        $role->save();

        // 授权后 200；index 之外的动作（如 store）依然 403 —— 授权是按动作粒度的
        $this->getJson('api/admin/food?page=1&page_limit=10', ['Authorization' => "Bearer {$token}"])
            ->assertOk();
        $this->postJson('api/admin/food', ['food_name' => '苹果', 'food_category' => 1, 'price' => 100, 'food_status' => 1], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);
    }
}
