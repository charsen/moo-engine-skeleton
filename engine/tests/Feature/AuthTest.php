<?php

declare(strict_types=1);
/*
 * JWT 登录链路守护测试。
 *
 * 重点是 test_refresh_token_keeps_guard_claim：守护「续签出的新 token 必须
 * 还能过 jwt.guard.auth:admin」这条行为。guard claim 的保留在 jwt-auth 2.8.x
 * 上完全依赖 config/jwt.php 的 persistent_claims=['guard']（生产环境踩过
 * 丢失后 401 的坑）；2.9.x 上库内部实现也会保留，但契约仍是 persistent_claims。
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mooeen\System\Models\Enums\AccountStatus;
use Mooeen\System\Models\Personnel;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** 跑 DatabaseSeeder：角色 → 部门 → 岗位 → 人员（手机 13800000000 / 密码 admin888） */
    protected $seed = true;

    public function test_authenticate_with_valid_credentials_returns_token(): void
    {
        $response = $this->postJson('api/admin/authenticate', [
            'account'  => '13800000000',
            'password' => 'admin888',
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame(config('jwt.ttl') * 60, $response->json('data.expires_in'));
        $this->assertSame('管理员', $response->json('data.user.real_name'));
    }

    public function test_authenticate_with_wrong_password_returns_422(): void
    {
        $this->postJson('api/admin/authenticate', [
            'account'  => '13800000000',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonStructure(['message', 'errors']);
    }

    public function test_disabled_account_cannot_login(): void
    {
        Personnel::where('mobile', '13800000000')
            ->update(['account_status' => AccountStatus::FORBIDDEN->value]);

        $this->postJson('api/admin/authenticate', [
            'account'  => '13800000000',
            'password' => 'admin888',
        ])->assertStatus(422);
    }

    public function test_me_without_token_returns_401(): void
    {
        $this->getJson('api/admin/me/info')->assertStatus(401);
    }

    public function test_me_with_token_returns_user(): void
    {
        $token = $this->adminLogin();

        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.user.mobile', '13800000000');
    }

    public function test_refresh_token_keeps_guard_claim(): void
    {
        $token = $this->adminLogin();
        $this->freshJwtProcess();

        // 主动刷新拿到新 token
        $new_token = $this->postJson('api/admin/refresh', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->json('data.token');

        $this->assertNotSame($token, $new_token);
        $this->freshJwtProcess();

        // 用新 token 过 jwt.guard.auth:admin —— 新 token 丢了 guard claim 的话这里就是 401
        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$new_token}"])
            ->assertOk()
            ->assertJsonPath('data.user.mobile', '13800000000');
    }

    public function test_refresh_with_expired_token_yields_single_token(): void
    {
        $expired = $this->makeExpiredToken();

        // 过期但在续期窗口内 → refresh 成功；
        // 路由不挂 jwt.auth.refresh，响应头不得再出现第二个 token（孤儿 token 回归测试）
        $response = $this->postJson('api/admin/refresh', [], ['Authorization' => "Bearer {$expired}"])
            ->assertOk();
        $response->assertHeaderMissing('authorization');

        $new_token = $response->json('data.token');
        $this->freshJwtProcess();

        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$new_token}"])
            ->assertOk();
    }

    public function test_refresh_with_garbage_token_returns_401(): void
    {
        $this->postJson('api/admin/refresh', [], ['Authorization' => 'Bearer not-a-jwt'])
            ->assertStatus(401);
    }

    public function test_logout_blacklists_token(): void
    {
        $token = $this->adminLogin();

        $this->postJson('api/admin/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        // logout(true) 强制拉黑（forceForever），不受 90 秒宽限期影响，立即 401
        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(401);
    }

    /**
     * 黑名单执行开关默认必须为 true（坑 #28）：为 false 时被拉黑的 token 会被
     * 静默放行——moo-system 撤销会话 / 改密踢人「看似成功、实际无效」。低成本
     * 断言默认值，防止精简配置时漏键或误关。
     */
    public function test_blacklist_exception_defaults_true(): void
    {
        $this->assertTrue(config('jwt.blacklist_enabled'), 'blacklist_enabled 必须开，否则登出形同虚设');
        $this->assertTrue(config('jwt.show_black_list_exception'), 'show_black_list_exception 默认必须为 true，否则拉黑静默失效');
    }
}
