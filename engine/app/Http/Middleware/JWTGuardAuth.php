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
