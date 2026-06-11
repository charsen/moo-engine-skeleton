<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mooeen\System\Models\Personnel;

abstract class TestCase extends BaseTestCase
{
    /**
     * 调后台登录接口拿 token（seed 出的管理员：13800000000 / admin888）
     */
    protected function adminLogin(string $account = '13800000000', string $password = 'admin888'): string
    {
        return $this->postJson('api/admin/authenticate', [
            'account' => $account,
            'password' => $password,
        ])->assertOk()->json('data.token');
    }

    /**
     * 调移动端登录接口拿 token（user 守卫）
     */
    protected function appLogin(string $account = '13800000000', string $password = 'admin888'): string
    {
        return $this->postJson('app/authenticate', [
            'account' => $account,
            'password' => $password,
        ])->assertOk()->json('data.token');
    }

    /**
     * 模拟真实跨进程请求。
     *
     * 坑：jwt-auth 的 payload 工厂（tymon.jwt.payload.factory，历史遗留命名）是单例，
     * 同一测试进程里前一次登录/解码残留的 claim 会喂给后续的签发与续签，掩盖
     * persistent_claims 等配置缺失 —— 真实世界两次请求是两个进程，没有这种残留。
     * 凡是「跨请求」断言（refresh 后再用新 token 等），两次请求之间都要调用本方法。
     */
    protected function freshJwtProcess(): void
    {
        $this->app->make('tymon.jwt.payload.factory')->emptyClaims();
    }

    /**
     * 手工签一个「已过期但仍在续期窗口内」的 token（refresh 过期路径专用）。
     *
     * 不能用 auth()->login() 配负数 ttl 造：签发后包内自检会直接抛 TokenExpiredException。
     */
    protected function makeExpiredToken(string $guard = 'admin'): string
    {
        $user = Personnel::where('mobile', '13800000000')->firstOrFail();

        $b64 = static fn (string $d): string => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $header = $b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $now = time();
        $payload = $b64(json_encode([
            'iss' => 'http://localhost/api/admin/authenticate',
            'iat' => $now - 7200,
            'exp' => $now - 3600,
            'nbf' => $now - 7200,
            'jti' => bin2hex(random_bytes(8)),
            'sub' => (string) $user->id,
            'prv' => sha1(Personnel::class), // lock_subject=true 时包会校验这个模型哈希
            'guard' => $guard,
        ]));
        $signature = $b64(hash_hmac('sha256', "{$header}.{$payload}", (string) config('jwt.secret'), true));

        return "{$header}.{$payload}.{$signature}";
    }
}
