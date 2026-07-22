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

                // refresh() 只签发新 token，不会自动恢复认证用户。
                // 用新 token 再认证一次，既确认主体仍存在，也让后续业务拿到当前用户。
                if (! $this->auth->setToken($token)->authenticate()) {
                    throw new UnauthorizedHttpException('jwt-auth', 'Token subject not found');
                }

                // 同步 moo-system 登录管理记录（第 7 章装包后自动生效；未装包时静默跳过，
                // 本中间件因此不依赖任何付费包，第 3 章"直接抄"成立）
                if (! empty($old_token) && class_exists(UpdateLoginTokenJob::class)) {
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
