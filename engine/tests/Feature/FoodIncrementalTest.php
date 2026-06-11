<?php

declare(strict_types=1);
/*
 * 增量开发工作流守护测试（docs 增量开发章）。
 *
 * 守住两件事：
 * 1. yaml 增量字段 stock：迁移 + $fillable + Request 规则 全链路打通（创建即落库）；
 * 2. moo:adder 加的自定义 action toggleStatus：上架/下架来回切换，
 *    且权限走 $transform_methods 复用 update（is_root 管理员直接可用）。
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoodIncrementalTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_stock_field_and_toggle_status_round_trip(): void
    {
        $token = $this->adminLogin();
        $headers = ['Authorization' => "Bearer {$token}"];

        // 创建带 stock 的食品（stock 是增量字段：迁移 + fillable + 校验规则）
        $id = $this->postJson('api/admin/food', [
            'food_name' => '增量苹果',
            'food_category' => 1,
            'price' => 100,
            'stock' => 50,
            'food_status' => 1,
        ], $headers)->assertCreated()->json('data.id');

        $this->assertDatabaseHas('foods', ['id' => $id, 'stock' => 50, 'food_status' => 1]);

        // 第一次切换：上架(1) → 下架(2)
        $this->postJson("api/admin/food/{$id}/toggle-status", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.food_status', 2);

        // 第二次切换：下架(2) → 上架(1)
        $this->postJson("api/admin/food/{$id}/toggle-status", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.food_status', 1);
    }
}
