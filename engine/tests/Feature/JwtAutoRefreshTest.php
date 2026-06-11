<?php

declare(strict_types=1);
/*
 * jwt.auth.refresh 中间件「过期 → 自动续签」的正向分支守护。
 *
 * AuthTest 已覆盖主动 refresh 端点（路由不挂 jwt.auth.refresh，孤儿 token 回归）；
 * 这里补的是另一条路径：**挂了 jwt.auth.refresh 的业务路由**（如 me/info）收到
 * 过期但仍在续期窗口内的 token 时，中间件应静默续签——请求照常 200，新 token 通过
 * `authorization` 响应头下发，前端无感换签。这条分支此前一直没有测试背书。
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JwtAutoRefreshTest extends TestCase
{
    use RefreshDatabase;

    /** 跑 DatabaseSeeder（管理员 13800000000 / admin888） */
    protected $seed = true;

    public function test_middleware_auto_refreshes_expired_token_on_protected_route(): void
    {
        $expired = $this->makeExpiredToken();

        // 过期 token 打挂 jwt.auth.refresh 的路由 → 中间件续签，业务照常返回
        $response = $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$expired}"])
            ->assertOk();

        // 新 token 经 authorization 响应头下发，且不同于旧 token
        $new_token = (string) $response->headers->get('authorization');
        $this->assertNotEmpty($new_token, '自动续签后响应头应携带新 token');
        $this->assertNotSame($expired, $new_token);

        // 续签出的 token 真实可用（guard claim 保留，能再过一遍完整中间件链）
        $this->freshJwtProcess();
        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$new_token}"])
            ->assertOk();
    }

    public function test_token_beyond_refresh_window_returns_401(): void
    {
        // iat 早于 refresh_ttl 窗口起点 → 续签也救不回来，只能重新登录
        $dead = $this->makeExpiredToken('admin', (int) config('jwt.refresh_ttl') * 60 + 7200);

        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$dead}"])
            ->assertStatus(401);
    }
}
