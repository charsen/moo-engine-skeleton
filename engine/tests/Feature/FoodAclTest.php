<?php

declare(strict_types=1);
/*
 * ACL 鉴权链路守护测试（docs 第 5 章）。
 *
 * 完整闭环：无 token 401 → 有 token 无权限 403 → 角色授权后 200。
 * 顺带守住「系统管理员角色的 is_root 字面量 = 超级权限」和
 * 「个人中心白名单：零授权角色也能自助」这两条 Gate 约定。
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

    /** food.index 的 ACL key：明文 key 是否做 md5 取中段，跟随 scaffold.authorization.md5 开关 */
    private function foodIndexAclKey(): string
    {
        $plain = Controller::aclPlainKey(FoodController::class . '::index');

        return config('scaffold.authorization.md5') ? substr(md5($plain), 8, 16) : $plain;
    }

    /** 造一个挂「编辑员」角色（无任何授权动作）的人员 */
    private function makeEditor(): Personnel
    {
        $editor                     = Personnel::firstOrNew(['mobile' => '13900000000']);
        $editor->real_name          = '编辑小王';
        $editor->staff_status       = 7;
        $editor->account_status     = 7;
        $editor->password           = 'editor888';
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
        $token = $this->adminLogin();

        $this->getJson('api/admin/food?page=1&page_limit=10', ['Authorization' => "Bearer {$token}"])
            ->assertOk();
    }

    public function test_role_without_action_gets_403(): void
    {
        $this->makeEditor();
        $token = $this->adminLogin('13900000000', 'editor888');

        $this->getJson('api/admin/food?page=1&page_limit=10', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);
    }

    public function test_zero_action_role_can_still_use_personal_center(): void
    {
        // 守护（坑 #25）：moo:auth 会整文件重写 config/actions.php，把手动合并进
        // whitelist 的个人中心 8 个 key 冲掉（它只自动放行「无 @acl」的 action）。
        // 丢 key = 零授权角色被锁死在门外（坑 #20），所以先断言 8 个 key 都在。
        $whitelist = config('actions.admin.whitelist');
        foreach ([
            '84470713dcb9a7c9', // admin-system-admin-index
            'f6d488cc41bea74a', // admin-system-admin-edit
            'b00ef1ce449c970b', // admin-system-admin-update
            'cbc32275c4bdb06c', // admin-system-admin-password-form
            '88e610dbb210a3dc', // admin-system-admin-password
            '1fcbfd9524aebb83', // admin-system-admin-avatar-form
            'd59a5622ff031201', // admin-system-admin-avatar
            'e389e65e330e8af2', // admin-system-admin-logins
        ] as $key) {
            $this->assertContains($key, $whitelist, "个人中心白名单 key {$key} 丢失——moo:auth 重写 config/actions.php 后未把手动段合并回去（坑 #25）");
        }

        $this->makeEditor();
        $token = $this->adminLogin('13900000000', 'editor888');

        // 个人中心（moo-system AdminController::index）在 config/actions.php 白名单里，
        // 零授权角色也能查看本人信息 —— 白名单缺了这组 key，这里就是 403（自锁门外）
        $this->getJson('api/admin/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk();
    }

    public function test_granting_action_to_role_turns_403_into_200(): void
    {
        $this->makeEditor();
        $token = $this->adminLogin('13900000000', 'editor888');

        // 授权前 403
        $this->getJson('api/admin/food?page=1&page_limit=10', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);

        // 给「编辑员」角色授 food.index（与后台「授权」模块勾选动作等效）
        $role               = Role::where('role_name', '编辑员')->first();
        $role->role_actions = [$this->foodIndexAclKey()];
        $role->save();

        // 授权后 200；index 之外的动作（如 store）依然 403 —— 授权是按动作粒度的
        $this->getJson('api/admin/food?page=1&page_limit=10', ['Authorization' => "Bearer {$token}"])
            ->assertOk();
        $this->postJson('api/admin/food', ['food_name' => '苹果', 'food_category' => 1, 'price' => 100, 'food_status' => 1], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);
    }
}
