<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Description: JWT 强制认证 + 近过期自动续签
 *
 * - token 有效：认证通过，继续；
 * - token 过期：尝试 refresh，成功则把新 token 放进响应头 authorization，并更新登录记录；
 * - refresh 也过期 / token 非法：抛 401，前端需重新登录。
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mooeen\System\Jobs\UpdateLoginTokenJob;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class JWTAuthOrRefresh
{
    public function __construct(protected JWTAuth $auth) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $old_token = $request->bearerToken();

        try {
            if ($this->auth->parseToken()->authenticate()) {
                return $next($request);
            }
            throw new UnauthorizedHttpException('jwt-auth', 'Token not provided');
        } catch (TokenInvalidException $e) {
            throw new UnauthorizedHttpException('jwt-auth', $e->getMessage());
        } catch (TokenExpiredException $e) {
            try {
                $token = $this->auth->refresh();

                // token 有效但用户数据丢失（如开发期 reseed）→ 让前端清缓存重登
                if ($this->auth->user() === null) {
                    throw new UnauthorizedHttpException('jwt-auth', 'Token not provided');
                }

                if (! empty($old_token)) {
                    UpdateLoginTokenJob::dispatch($old_token, $token);
                }
            } catch (JWTException $e) {
                throw new UnauthorizedHttpException('jwt-auth', $e->getMessage());
            }
        } catch (JWTException $e) {
            throw new UnauthorizedHttpException('jwt-auth', $e->getMessage());
        }

        // 续签成功：把新 token 放进响应头
        $response = $next($request);
        $response->headers->set('authorization', $token);

        return $response;
    }
}
