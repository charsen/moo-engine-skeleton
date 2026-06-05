<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Description: 指定当前请求使用哪个 JWT 守卫（auth()->shouldUse($guard)）
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JWTAssignGuard
{
    public function handle(Request $request, Closure $next, $guard = null): mixed
    {
        if ($guard !== null) {
            Auth::shouldUse($guard);
        }

        return $next($request);
    }
}
