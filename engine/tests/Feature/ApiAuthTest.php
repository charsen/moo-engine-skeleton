<?php

declare(strict_types=1);
/*
 * 移动端（user 守卫）登录链路 + 守卫隔离测试（docs 第 6 章）。
 * 主体是自建 App\Models\User（email 登录，不依赖 moo-system）。
 *
 * 两条主线：
 * 1. 守卫隔离：admin token 调移动端接口 401，user token 调后台接口 401
 *    （jwt.guard.auth 校验 token 里的 guard claim）；
 * 2. 严格轮换：移动端 refresh(true) 后本次旧 token 立即作废（无 90 秒宽限），
 *    与后台 refresh(false) 的宽限行为形成对照。
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_app_me_without_token_returns_401(): void
    {
        $this->getJson('app/me/info')->assertStatus(401);
    }

    public function test_app_login_and_me(): void
    {
        $token = $this->appLogin();

        $this->getJson('app/me/info', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'admin@example.com');
    }

    public function test_guard_isolation_between_admin_and_user_tokens(): void
    {
        $admin_token = $this->adminLogin();
        $this->freshJwtProcess();
        $app_token = $this->appLogin();
        $this->freshJwtProcess();

        // admin token（guard=admin）调移动端接口 → 401 Guard Unverified
        $this->getJson('app/me/info', ['Authorization' => "Bearer {$admin_token}"])
            ->assertStatus(401);
        $this->freshJwtProcess();

        // user token（guard=user）调后台接口 → 401
        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$app_token}"])
            ->assertStatus(401);
        $this->freshJwtProcess();

        // 各回各家正常（admin/user 两守卫 provider 不同，每个请求间都要模拟新进程）
        $this->getJson('app/me/info', ['Authorization' => "Bearer {$app_token}"])->assertOk();
        $this->freshJwtProcess();
        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$admin_token}"])->assertOk();
    }

    public function test_app_refresh_strictly_rotates_token(): void
    {
        $token = $this->appLogin();
        $this->freshJwtProcess();

        $new_token = $this->postJson('app/refresh', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->json('data.token');
        $this->assertNotSame($token, $new_token);
        $this->freshJwtProcess();

        // 新 token 带着 guard=user 正常使用（persistent_claims 契约）
        $this->getJson('app/me/info', ['Authorization' => "Bearer {$new_token}"])->assertOk();
        $this->freshJwtProcess();

        // 旧 token 被 forceForever 拉黑，**没有** 90 秒宽限，立即 401 —— 严格轮换
        $this->getJson('app/me/info', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_app_refresh_with_expired_token_yields_single_token(): void
    {
        $expired = $this->makeExpiredToken('user');

        $response = $this->postJson('app/refresh', [], ['Authorization' => "Bearer {$expired}"])
            ->assertOk();
        // 路由不挂 jwt.auth.refresh，响应头不得再出现第二个 token（孤儿 token 回归测试）
        $response->assertHeaderMissing('authorization');

        $new_token = $response->json('data.token');
        $this->freshJwtProcess();

        $this->getJson('app/me/info', ['Authorization' => "Bearer {$new_token}"])->assertOk();
    }
}
