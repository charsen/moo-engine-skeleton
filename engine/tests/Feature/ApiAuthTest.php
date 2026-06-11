<?php

declare(strict_types=1);
/*
 * 移动端（user 守卫）登录链路 + 守卫隔离测试（docs 第 7 章）。
 *
 * 两条主线：
 * 1. 守卫隔离：admin token 调移动端接口 401，user token 调后台接口 401
 *    （jwt.guard.auth 校验 token 里的 guard claim）；
 * 2. 单设备语义：移动端 refresh(true) 后旧 token 立即作废（无 90 秒宽限），
 *    与后台 refresh(false) 的宽限行为形成对照。
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function appLogin(): string
    {
        return $this->postJson('app/authenticate', [
            'account' => '13800000000',
            'password' => 'admin888',
        ])->assertOk()->json('data.token');
    }

    private function adminLogin(): string
    {
        return $this->postJson('api/admin/authenticate', [
            'account' => '13800000000',
            'password' => 'admin888',
        ])->assertOk()->json('data.token');
    }

    /** 模拟真实跨请求：清掉 payload 工厂单例残留的 claim（见 AuthTest 的说明） */
    private function freshProcess(): void
    {
        $this->app->make('tymon.jwt.payload.factory')->emptyClaims();
    }

    public function test_app_me_without_token_returns_401(): void
    {
        $this->getJson('app/me/info')->assertStatus(401);
    }

    public function test_app_login_and_me(): void
    {
        $token = $this->appLogin();

        $this->getJson('app/me/info', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.user.mobile', '13800000000');
    }

    public function test_guard_isolation_between_admin_and_user_tokens(): void
    {
        $admin_token = $this->adminLogin();
        $this->freshProcess();
        $app_token = $this->appLogin();
        $this->freshProcess();

        // admin token（guard=admin）调移动端接口 → 401 Guard Unverified
        $this->getJson('app/me/info', ['Authorization' => "Bearer {$admin_token}"])
            ->assertStatus(401);

        // user token（guard=user）调后台接口 → 401
        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$app_token}"])
            ->assertStatus(401);

        // 各回各家正常
        $this->getJson('app/me/info', ['Authorization' => "Bearer {$app_token}"])->assertOk();
        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$admin_token}"])->assertOk();
    }

    public function test_app_refresh_is_single_device(): void
    {
        $token = $this->appLogin();
        $this->freshProcess();

        $new_token = $this->postJson('app/refresh', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->json('data.token');
        $this->assertNotSame($token, $new_token);
        $this->freshProcess();

        // 新 token 带着 guard=user 正常使用（persistent_claims 契约）
        $this->getJson('app/me/info', ['Authorization' => "Bearer {$new_token}"])->assertOk();
        $this->freshProcess();

        // 旧 token 被 forceForever 拉黑，**没有** 90 秒宽限，立即 401 —— 单设备登录
        $this->getJson('app/me/info', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }
}
