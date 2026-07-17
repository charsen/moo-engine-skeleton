<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * helpers 基座扩充的单测（1-2）。守住 guard 感知取值与日志通道路由。
 */
class HelpersTest extends TestCase
{
    public function test_get_user_id_is_null_for_guests(): void
    {
        $this->assertNull(getUserId());
    }

    public function test_get_user_id_reads_the_default_guard(): void
    {
        // config('auth.defaults.guard') 默认 admin
        $this->be(new GenericUser(['id' => 42]), 'admin');

        $this->assertSame(42, getUserId());
    }

    public function test_get_user_is_null_for_guests(): void
    {
        $this->assertNull(getUser());
    }

    public function test_get_user_returns_the_authenticated_model(): void
    {
        $this->be(new GenericUser(['id' => 42]), 'admin');

        $this->assertInstanceOf(Authenticatable::class, getUser());
    }

    public function test_log_dev_writes_to_the_dev_channel(): void
    {
        Log::shouldReceive('channel')->once()->with('dev')->andReturnSelf();
        Log::shouldReceive('info')->once();

        logDev('title', ['k' => 'v']); // 数组入参走 json 序列化分支
    }

    public function test_log_auth_writes_to_the_auth_channel(): void
    {
        Log::shouldReceive('channel')->once()->with('auth')->andReturnSelf();
        Log::shouldReceive('info')->once();

        logAuth('401 Unauthorized', 'GET api/admin/me/info — token expired');
    }

    public function test_log_auth_uses_error_level_when_requested(): void
    {
        Log::shouldReceive('channel')->once()->with('auth')->andReturnSelf();
        Log::shouldReceive('error')->once();

        logAuth('auth failure', 'boom', useError: true);
    }
}
