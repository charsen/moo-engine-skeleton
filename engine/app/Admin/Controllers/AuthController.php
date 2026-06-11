<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Description: 后台登录认证（JWT）。参考 wisdomcity 的手动校验 + Auth::login 签发方式。
 */

namespace App\Admin\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mooeen\System\Models\Personnel;

class AuthController
{
    /**
     * 登录：校验帐号密码 → 手动签发 JWT。
     *
     * @throws ValidationException
     */
    public function authenticate(Request $request): JsonResponse
    {
        $params = $request->validate([
            'account' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // 支持用姓名或手机号登录
        $user = Personnel::where('real_name', $params['account'])
            ->orWhere('mobile', $params['account'])
            ->first();

        if ($user === null || ! Hash::check($params['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['account' => ['帐号或密码错误。']]);
        }

        // 手动签发 token（guard=admin 会写进 token 的自定义声明，供 JWTGuardAuth 校验）
        $token = Auth::guard('admin')->login($user);

        $user->forceFill([
            'login_times' => (int) $user->login_times + 1,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => (string) $user->id,
                    'real_name' => $user->real_name,
                    'avatar' => $user->avatar_url ?? null,
                    'login_times' => $user->login_times,
                ],
                'token' => $token,
                'expires_in' => Auth::guard('admin')->factory()->getTTL() * 60,
            ],
        ]);
    }

    /**
     * 当前登录人信息
     */
    public function me(): JsonResponse
    {
        $user = Auth::guard('admin')->user();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => (string) $user->id,
                    'real_name' => $user->real_name,
                    'mobile' => $user->mobile,
                    'avatar' => $user->avatar_url ?? null,
                ],
            ],
        ]);
    }

    /**
     * 主动刷新 token
     */
    public function refresh(): JsonResponse
    {
        // forceForever=false：旧 token 进黑名单但享受 90 秒宽限（并发请求不打架，见 config/jwt.php）；
        // resetClaims=false：保留自定义 claim —— 配合 persistent_claims=['guard']，
        // 新 token 才带 guard 声明，否则下次过 JWTGuardAuth 直接 401（对齐 wisdomcity 修复）。
        $token = Auth::guard('admin')->refresh(false, false);

        return response()->json([
            'data' => [
                'token' => $token,
                'expires_in' => Auth::guard('admin')->factory()->getTTL() * 60,
            ],
        ]);
    }

    /**
     * 退出登录（拉黑当前 token）
     */
    public function logout(): JsonResponse
    {
        Auth::guard('admin')->logout(true);

        return response()->json(['message' => 'ok']);
    }
}
