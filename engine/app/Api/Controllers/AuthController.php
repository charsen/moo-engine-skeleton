<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Description: 移动端登录认证（JWT，user 守卫）。
 *
 * 与后台（Admin/AuthController）的三个差异：
 * 1. guard 用 'user'，并用 claims(['guard' => 'user']) 显式声明 ——
 *    moo-system 的 getJWTCustomClaims() 已动态化（跟随 Auth::getDefaultDriver()，
 *    fix/dynamic-guard-claim），本组路由经 client 组 shouldUse('user') 后包侧即返回
 *    'user'，这里的内联声明是冗余保险：不依赖包的合并节奏，包回退到旧版（硬编码
 *    'admin'）时守卫隔离也不会静默失效；
 * 2. refresh 用 (true, false)：forceForever —— 移动端单设备登录，旧 token 永久作废，
 *    不享受 90 秒黑名单宽限；
 * 3. 真实项目移动端主体通常是会员表（Member），这里复用 Personnel 仅作演示。
 */

namespace App\Api\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mooeen\System\Jobs\UpdateLoginTokenJob;
use Mooeen\System\Models\Enums\AccountStatus;
use Mooeen\System\Models\Personnel;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthController
{
    /**
     * 登录：校验帐号密码 → 签发 user 守卫的 JWT。
     *
     * @throws ValidationException
     */
    public function authenticate(Request $request): JsonResponse
    {
        $params = $request->validate([
            'account' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = Personnel::where('mobile', $params['account'])->first();

        if ($user === null || ! Hash::check($params['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['account' => ['帐号或密码错误。']]);
        }

        // 账号状态前置检查（裸 int 比较用 ->value，详见 Admin/AuthController 同处注释）
        if ($user->account_status === AccountStatus::FORBIDDEN->value) {
            throw ValidationException::withMessages(['account' => ['帐号已被禁止登录。']]);
        }
        if ($user->account_status === AccountStatus::LOCKED->value) {
            throw ValidationException::withMessages(['account' => ['帐号已被锁定，请联系管理员。']]);
        }

        // 显式把 guard claim 覆盖成 user（见类注释第 1 点）
        $token = Auth::guard('user')->claims(['guard' => 'user'])->login($user);

        return response()->json([
            'data' => [
                'user' => [
                    'id' => (string) $user->id,
                    'real_name' => $user->real_name,
                ],
                'token' => $token,
                'expires_in' => Auth::guard('user')->factory()->getTTL() * 60,
            ],
        ]);
    }

    /**
     * 当前登录人信息
     */
    public function me(): JsonResponse
    {
        $user = Auth::guard('user')->user();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => (string) $user->id,
                    'real_name' => $user->real_name,
                    'mobile' => $user->mobile,
                ],
            ],
        ]);
    }

    /**
     * 主动刷新 token（移动端单设备：旧 token 永久作废）
     *
     * 与后台一样**故意不挂** jwt.auth.refresh（见 routes/api.php）：否则过期 token 会被
     * 中间件先续签一次、这里再续签一次，凭一个旧 token 派生出两个有效新 token，
     * 「单设备」承诺被孤儿 token 打破。
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            // forceForever=true：旧 token 直接 addForever 进黑名单，没有 90 秒宽限 —— 单设备登录语义；
            // resetClaims=false：保留 guard claim（persistent_claims 契约，见 config/jwt.php）
            $token = Auth::guard('user')->refresh(true, false);
        } catch (JWTException $e) {
            throw new UnauthorizedHttpException('jwt-auth', $e->getMessage());
        }

        if (! empty($request->bearerToken())) {
            UpdateLoginTokenJob::dispatch($request->bearerToken(), $token);
        }

        return response()->json([
            'data' => [
                'token' => $token,
                'expires_in' => Auth::guard('user')->factory()->getTTL() * 60,
            ],
        ]);
    }

    /**
     * 退出登录（永久拉黑当前 token）
     */
    public function logout(): JsonResponse
    {
        Auth::guard('user')->logout(true);

        return response()->json(['message' => 'ok']);
    }
}
