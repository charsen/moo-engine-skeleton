<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
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
            'account'  => $account,
            'password' => $password,
        ])->assertOk()->json('data.token');
    }

    /**
     * 调移动端登录接口拿 token（user 守卫，主体是自建 User，email 登录）
     */
    protected function appLogin(string $email = 'admin@example.com', string $password = 'password'): string
    {
        return $this->postJson('app/authenticate', [
            'email'    => $email,
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
        // ① payload 工厂的 claim 残留（见上）；② auth 适配器（tymon.jwt.provider.auth）
        // 在首次解析时绑死当时的默认守卫——admin/user 两守卫 provider 不同后，
        // 跨守卫断言会用错 provider 查不到用户而 401。整条 jwt 服务链单例一起重置，
        // 下个请求会按其中间件 shouldUse 的守卫重新构建（与真实跨进程一致）。
        // 'auth.driver' 是 Laravel 对「默认守卫实例」的容器缓存——jwt 的 auth 适配器
        // 构造函数注入 Guard 契约时拿的就是它，不清掉的话适配器永远绑着首次请求的守卫。
        foreach ([
            'tymon.jwt', 'tymon.jwt.auth', 'tymon.jwt.manager',
            'tymon.jwt.provider.auth', 'tymon.jwt.payload.factory', 'tymon.jwt.blacklist',
            'auth.driver',
        ] as $id) {
            $this->app->forgetInstance($id);
        }
    }

    /**
     * 手工签一个「已过期但仍在续期窗口内」的 token（refresh 过期路径专用）。
     *
     * 不能用 auth()->login() 配负数 ttl 造：签发后包内自检会直接抛 TokenExpiredException。
     *
     * @param int|null $iat_ago 签发时刻距今多少秒（默认 7200 = 仍在续期窗口内；
     *                          传大于 refresh_ttl*60 的值可造「超出续期窗口」的 token）
     */
    protected function makeExpiredToken(string $guard = 'admin', ?int $iat_ago = null): string
    {
        // 守卫决定主体模型：admin → Personnel（moo-system）；user → 自建 User
        $user = $guard === 'user'
            ? User::where('email', 'admin@example.com')->firstOrFail()
            : Personnel::where('mobile', '13800000000')->firstOrFail();

        $b64    = static fn (string $d): string => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $header = $b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $now    = time();
        $iat_ago ??= 7200;
        $payload = $b64(json_encode([
            'iss'   => 'http://localhost/api/admin/authenticate',
            'iat'   => $now - $iat_ago,
            'exp'   => $now - ($iat_ago - 3600), // 签发 1 小时后过期
            'nbf'   => $now - $iat_ago,
            'jti'   => bin2hex(random_bytes(8)),
            'sub'   => (string) $user->id,
            'prv'   => sha1($guard === 'user' ? User::class : Personnel::class), // lock_subject 模型哈希
            'guard' => $guard,
        ]));
        $signature = $b64(hash_hmac('sha256', "{$header}.{$payload}", (string) config('jwt.secret'), true));

        return "{$header}.{$payload}.{$signature}";
    }
}
