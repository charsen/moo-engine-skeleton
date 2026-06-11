<?php

declare(strict_types=1);
/*
 * @Author: charsen
 * @Description: 后台登录认证（JWT）。生产项目同款的手动校验 + Auth::login 签发方式。
 */

namespace App\Admin\Controllers;

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

        // 账号状态前置检查 —— 没有这步，被禁用/锁定的人员照样能登录拿 token。
        // 注意 account_status 是裸 int（本生态约定枚举不进 $casts），比较必须用 ->value；
        // 写成 `=== AccountStatus::FORBIDDEN`（enum 实例）永远为 false，检查会静默失效。
        if ($user->account_status === AccountStatus::FORBIDDEN->value) {
            throw ValidationException::withMessages(['account' => ['帐号已被禁止登录。']]);
        }
        if ($user->account_status === AccountStatus::LOCKED->value) {
            throw ValidationException::withMessages(['account' => ['帐号已被锁定，请联系管理员。']]);
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
     *
     * 本路由**故意不挂** jwt.auth.refresh（见 routes/admin.php）：那个中间件会先对过期
     * token 自动续签一次，控制器再续签第二次 —— 一个旧 token 派生出两个有效新 token，
     * 响应头和响应体各一个，前者永远不会被作废（孤儿 token）。refresh 自己就支持
     * 过期 token（只要在续期窗口内），这里独立完成唯一的一次续签。
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            // forceForever=false：旧 token 进黑名单但享受 90 秒宽限（并发请求不打架）；
            // resetClaims=false：保留自定义 claim —— 配合 persistent_claims=['guard']，
            // 新 token 才带 guard 声明，否则下次过 JWTGuardAuth 直接 401（生产环境踩过的真坑）。
            $token = Auth::guard('admin')->refresh(false, false);
        } catch (JWTException $e) {
            // 无 token / 伪造 / 超出续期窗口 / 已拉黑 → 重新登录
            throw new UnauthorizedHttpException('jwt-auth', $e->getMessage());
        }

        // 同步登录管理（system_logins）里记录的 token（原由 jwt.auth.refresh 中间件代劳）
        if (! empty($request->bearerToken())) {
            UpdateLoginTokenJob::dispatch($request->bearerToken(), $token);
        }

        return response()->json([
            'data' => [
                'token' => $token,
                'expires_in' => Auth::guard('admin')->factory()->getTTL() * 60,
            ],
        ]);
    }

    /**
     * 退出登录（拉黑当前 token）。
     *
     * 路由是公开的（不挂 JWT 中间件）。无 token / 垃圾 token 也不会 500：
     * JWTGuard::logout() 内部自己捕获了 JWTException（拉黑不了就当作已登出），
     * 所以本接口对任何输入都幂等返回 200——RegressionTest 守护了这个契约。
     */
    public function logout(): JsonResponse
    {
        Auth::guard('admin')->logout(true);

        return response()->json(['message' => 'ok']);
    }
}
