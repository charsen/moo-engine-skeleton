<?php

declare(strict_types=1);

/*
 * iResource 宏的路由顺序守护（2026-07 审查修复 0-1）。
 *
 * 坑：destroy 的 DELETE /{id} 若注册在固定段 /batch、/forever/{id} 之前，
 * 且 {id} 无约束，`DELETE food/batch` 会被 /{id} 抢匹配成 destroy('batch')——
 * destroyBatch 永远命不中。修法两道保险：① 固定段先于动态 /{id} 注册；
 * ② Route::pattern('id','[1-9][0-9]*') 把 {id} 钉成正整数，'batch' 天然不匹配。
 * 本测试同时钉死两者：把 fixture 控制器的三条 DELETE 路由都解析一遍。
 */

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteMacroTest extends TestCase
{
    public function test_fixed_delete_routes_register_before_dynamic_destroy(): void
    {
        // 同时具备 destroy / forceDestroy / destroyBatch 的控制器——三条 DELETE 路由共存时最易撞车
        $controller = new class
        {
            public function destroy(): void {}

            public function forceDestroy(): void {}

            public function destroyBatch(): void {}
        };

        Route::iResource('route-macro-fixtures', $controller::class);

        $routes = Route::getRoutes();

        $this->assertSame(
            'route-macro-fixtures.destroyBatch',
            $routes->match(Request::create('/route-macro-fixtures/batch', 'DELETE'))->getName(),
            'DELETE /batch 必须命中 destroyBatch，而不是被 /{id} 抢成 destroy(\'batch\')',
        );
        $this->assertSame(
            'route-macro-fixtures.forceDestroy',
            $routes->match(Request::create('/route-macro-fixtures/forever/17', 'DELETE'))->getName(),
            'DELETE /forever/{id} 必须命中 forceDestroy',
        );
        $this->assertSame(
            'route-macro-fixtures.destroy',
            $routes->match(Request::create('/route-macro-fixtures/17', 'DELETE'))->getName(),
            'DELETE /{id}（正整数）仍命中 destroy',
        );
    }
}
