<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Description: 移动端登录认证（JWT，user 守卫，主体是自建的 App\Models\User）。
 *
 * 与后台（Admin/AuthController）的三个差异：
 * 1. 主体是自建 User（email 登录）——不依赖 moo-system；guard claim 由
 *    User::getJWTCustomClaims() 动态注入（client 组已 shouldUse('user')），
 *    无需任何内联覆盖；
 * 2. refresh 用 (true, false)：forceForever —— 移动端单设备登录，旧 token 永久作废，
 *    不享受 90 秒黑名单宽限；
 * 3. 登录前置检查用 email_verified_at（自建表的最简状态位）；后台 Personnel
 *    （第 7 章）对应的是 account_status 枚举。
 */

namespace App\Api\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthController
{
    /**
     * 登录：校验邮箱密码 → 签发 user 守卫的 JWT。
     *
     * @throws ValidationException
     */
    public function authenticate(Request $request): JsonResponse
    {
        $params = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $params['email'])->first();

        if ($user === null || ! Hash::check($params['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['email' => ['帐号或密码错误。']]);
        }

        // 最简状态检查：未验证邮箱不允许登录（自建表的"激活"语义）
        if ($user->email_verified_at === null) {
            throw ValidationException::withMessages(['email' => ['帐号尚未激活（邮箱未验证）。']]);
        }

        // guard claim 由 User::getJWTCustomClaims() 动态注入（client 组已 shouldUse('user')）
        $token = Auth::guard('user')->login($user);

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
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
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
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

        return response()->json([
            'data' => [
                'token' => $token,
                'expires_in' => Auth::guard('user')->factory()->getTTL() * 60,
            ],
        ]);
    }

    /**
     * 退出登录（永久拉黑当前 token）。
     *
     * 路由是公开的（不挂 JWT 中间件）。无 token / 垃圾 token 也不会 500：
     * JWTGuard::logout() 内部自己捕获了 JWTException（拉黑不了就当作已登出），
     * 所以本接口对任何输入都幂等返回 200——RegressionTest 守护了这个契约。
     */
    public function logout(): JsonResponse
    {
        Auth::guard('user')->logout(true);

        return response()->json(['message' => 'ok']);
    }
}
