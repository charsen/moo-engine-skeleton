<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Moo\Scaffold\GetUserIdOperatorResolver;
use Illuminate\Auth\GenericUser;
use Mooeen\Scaffold\Contracts\OperatorResolver;
use Tests\TestCase;

/**
 * 操作人身份契约的 host 覆盖守护（1-1）。
 *
 * scaffold 包默认绑 GuardOperatorResolver（裸 auth()->id()）；host 在 App\Moo\Scaffold\ScaffoldServiceProvider
 * 覆盖成 GetUserIdOperatorResolver（getUserId 语义：读 config('auth.defaults.guard')，游客 null）。
 * 本测试钉死「覆盖已生效」+「游客 null / 登录返 id」两条契约。
 */
class OperatorResolverTest extends TestCase
{
    public function test_host_override_takes_effect(): void
    {
        // 容器解析到的是 host 实现，不是 scaffold 默认实现
        $this->assertInstanceOf(GetUserIdOperatorResolver::class, app(OperatorResolver::class));
    }

    public function test_it_returns_null_for_guests(): void
    {
        $this->assertNull(app(OperatorResolver::class)->id());
    }

    public function test_it_resolves_the_current_operator_from_the_default_guard(): void
    {
        // config('auth.defaults.guard') 默认 admin（见 config/auth.php），登录后 getUserId() 取该守卫的 id
        $this->be(new GenericUser(['id' => 17]), 'admin');

        $this->assertSame(17, app(OperatorResolver::class)->id());
    }
}
