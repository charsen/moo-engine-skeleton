<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Description: 移动端登录认证（JWT，user 守卫）。
 *
 * 与后台（Admin/AuthController）的三个差异：
 * 1. guard 用 'user'，并用 claims(['guard' => 'user']) 显式覆盖 ——
 *    moo-system 的 Personnel::getJWTCustomClaims() 硬编码 'admin'，不覆盖的话
 *    移动端 token 也带 guard=admin，过不了 jwt.guard.auth:user，守卫隔离就是空话
 *    （wisdomcity 因历史原因 app 路由也校验 admin，骨架把隔离做实）；
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
use Mooeen\System\Models\Personnel;

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
     */
    public function refresh(): JsonResponse
    {
        // forceForever=true：旧 token 直接 addForever 进黑名单，没有 90 秒宽限 —— 单设备登录语义；
        // resetClaims=false：保留 guard claim（persistent_claims 契约，见 config/jwt.php）
        $token = Auth::guard('user')->refresh(true, false);

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
