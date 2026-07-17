<?php

declare(strict_types=1);
/*
 * 移动端第一个业务接口：Food 只读（docs 第 9 章 9.8 节）。
 *
 * 守住三件事：
 * 1. 只读裁剪：app/food 只有 index/show 两条 GET 路由，写方法 405（iResource 按方法注册）；
 * 2. JWT 接线：无 token 401，user token 200 + 分页 meta；
 * 3. 守卫隔离：admin token 调 app/food 一样 401（jwt.guard.auth:user 校验 guard claim）。
 */

namespace Tests\Feature;

use App\Models\Food\Food;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiFoodTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function makeFood(string $name = '测试苹果'): Food
    {
        return Food::create([
            'food_name'     => $name,
            'food_category' => 1,
            'price'         => 350,
            'stock'         => 10,
            'food_status'   => 1,
        ]);
    }

    public function test_app_food_without_token_returns_401(): void
    {
        $this->getJson('app/food?page=1&page_limit=10')->assertStatus(401);
    }

    public function test_app_food_index_with_user_token_returns_paginated_list(): void
    {
        $this->makeFood('苹果');
        $this->makeFood('香蕉');

        $token = $this->appLogin();

        $this->getJson('app/food?page=1&page_limit=10', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total', 'total_page']])
            ->assertJsonPath('meta.total', 2);
    }

    public function test_admin_token_cannot_access_app_food(): void
    {
        $food        = $this->makeFood();
        $admin_token = $this->adminLogin();

        // admin token（guard=admin）调移动端接口 → 401 守卫隔离
        $this->getJson('app/food?page=1&page_limit=10', ['Authorization' => "Bearer {$admin_token}"])
            ->assertStatus(401);
        $this->freshJwtProcess();

        $this->getJson("app/food/{$food->id}", ['Authorization' => "Bearer {$admin_token}"])
            ->assertStatus(401);
    }

    public function test_app_food_show_returns_whitelisted_fields(): void
    {
        $food  = $this->makeFood('白名单苹果');
        $token = $this->appLogin();

        $this->getJson("app/food/{$food->id}", ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.food_name', '白名单苹果')
            ->assertJsonPath('data.id', (string) $food->id)   // 雪花主键在 JSON 里是字符串
            ->assertJsonMissingPath('data.deleted_at');       // 移动端不暴露软删字段

        // 软删后对移动端就是 404（show 没有 withTrashed）
        $this->freshJwtProcess();
        $food->delete();
        $this->getJson("app/food/{$food->id}", ['Authorization' => "Bearer {$token}"])
            ->assertNotFound();
    }

    public function test_app_food_write_routes_do_not_exist(): void
    {
        $food    = $this->makeFood();
        $token   = $this->appLogin();
        $headers = ['Authorization' => "Bearer {$token}"];

        // 控制器删掉写方法后，iResource 按方法注册 → 写路由根本不存在（405 Method Not Allowed）
        $this->postJson('app/food', ['food_name' => '不该建成'], $headers)->assertStatus(405);
        $this->freshJwtProcess();
        $this->putJson("app/food/{$food->id}", ['food_name' => '不该改成'], $headers)->assertStatus(405);
    }
}
