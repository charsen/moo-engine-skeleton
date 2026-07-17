<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Description: JWT 多守卫校验：核对 token 里的 guard 自定义声明与路由要求的守卫是否一致
 *
 * 注意：没带 token 时直接放行（强制认证是 JWTAuthOrRefresh 的职责），
 * 这样登录路由可以和受保护路由共用一套中间件别名。
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class JWTGuardAuth
{
    public function __construct(protected JWTAuth $auth) {}

    public function handle(Request $request, Closure $next, $guard = null): mixed
    {
        $guard = $guard === null ? config('auth.defaults.guard') : $guard;

        try {
            $token_guard = $this->auth->parseToken()->getClaim('guard');
        } catch (TokenExpiredException $e) {
            // 过期 token 也必须校验 guard claim：getClaim 走完整校验会在 exp 上抛掉，
            // 若就此放行，「只挂本中间件」的 refresh 端点就能被另一守卫的过期 token 续签
            // （refresh 本身不区分守卫）。改用底层 provider 裸解码——验签名、不验 exp。
            try {
                $claims      = $this->auth->manager()->getJWTProvider()->decode((string) $request->bearerToken());
                $token_guard = $claims['guard'] ?? null;
            } catch (JWTException $e) {
                return $next($request);
            }
        } catch (JWTException $e) {
            // 请求里没有可用 token —— 放行，交给后续中间件决定是否强制认证
            return $next($request);
        }

        if ($token_guard !== $guard) {
            throw new UnauthorizedHttpException('jwt-auth', 'Guard Unverified');
        }

        return $next($request);
    }
}
