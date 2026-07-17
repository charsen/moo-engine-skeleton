<?php

declare(strict_types=1);
/*
 * 2026-06 全面审查修复的回归守护：
 * ① iResource 宏按「action 真实存在且 public」注册——不再产出幻影路由（destroy 等调用即 500）；
 * ② 公开的 logout 对无 token / 垃圾 token 幂等返回 200（JWTGuard::logout 内部吞掉 JWTException，
 *    审查曾误判为 500——这里把真实契约钉死，vendor 行为变化时能第一时间发现）；
 * ③ 过期 token 也要过 guard claim 校验——跨守卫的过期 token 不能在对方的 refresh 端点续签；
 * ④ Food IndexRequest 与 FoodFilter 字段对齐（price 等筛选不再是死代码）+ page_limit 上限。
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegressionTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_phantom_destroy_route_is_not_registered(): void
    {
        $token = $this->adminLogin();
        $food  = $this->postJson('api/admin/food', [
            'food_name' => '幻影测试苹果', 'food_category' => 1, 'price' => 100, 'food_status' => 1,
        ], ['Authorization' => "Bearer {$token}"])->assertCreated()->json('data');

        // 控制器没有 destroy 方法，宏不应注册 DELETE /{id}。
        // 同一 URI 还有 GET（show），所以是 405 Method Not Allowed 而非 404——重点是不再 500
        $this->deleteJson('api/admin/food/' . $food['id'], [], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(405);
    }

    public function test_logout_without_token_is_idempotent_200(): void
    {
        $this->postJson('api/admin/logout')->assertOk();
    }

    public function test_logout_with_garbage_token_is_idempotent_200(): void
    {
        $this->postJson('app/logout', [], ['Authorization' => 'Bearer not-a-jwt'])->assertOk();
    }

    public function test_expired_user_token_cannot_refresh_on_admin_endpoint(): void
    {
        $expired = $this->makeExpiredToken('user');

        // guard claim 校验对过期 token 同样生效（裸解码读 claim），不能跨守卫续签
        $this->postJson('api/admin/refresh', [], ['Authorization' => "Bearer {$expired}"])
            ->assertStatus(401);
    }

    public function test_expired_admin_token_cannot_refresh_on_app_endpoint(): void
    {
        $expired = $this->makeExpiredToken('admin');

        $this->postJson('app/refresh', [], ['Authorization' => "Bearer {$expired}"])
            ->assertStatus(401);
    }

    public function test_food_price_filter_is_alive(): void
    {
        $token   = $this->adminLogin();
        $headers = ['Authorization' => "Bearer {$token}"];
        foreach ([['苹果', 350], ['西兰花', 480]] as [$name, $price]) {
            $this->postJson('api/admin/food', [
                'food_name' => $name, 'food_category' => 1, 'price' => $price, 'food_status' => 1,
            ], $headers)->assertCreated();
        }

        // IndexRequest 未放行的键到不了 ModelFilter——price 曾是死代码，这里守住对齐
        $this->getJson('api/admin/food?page=1&page_limit=10&price=350', $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.food_name', '苹果');
    }

    public function test_page_limit_is_capped(): void
    {
        $token = $this->adminLogin();

        $this->getJson('api/admin/food?page=1&page_limit=10000', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422);
    }

    public function test_login_is_rate_limited_per_account_and_ip(): void
    {
        // 组限流 300 次/分钟防不了爆破：登录单独按「账号+IP」5 次/分钟，第 6 次 429
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('api/admin/authenticate', [
                'account' => '13800000000', 'password' => 'wrong-' . $i,
            ])->assertStatus(422);
        }

        $this->postJson('api/admin/authenticate', [
            'account' => '13800000000', 'password' => 'wrong-6',
        ])->assertStatus(429);
    }
}
