<?php

declare(strict_types=1);

use App\Http\Middleware\JWTAssignGuard;
use App\Http\Middleware\JWTAuthOrRefresh;
use App\Http\Middleware\JWTGuardAuth;
use App\Http\Middleware\OperationLog;
use App\Http\Middleware\SetLocale;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Mooeen\Scaffold\Exceptions\BaseException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__ . '/../routes/console.php',
        using: function (): void {
            // 健康检查（用了 using: 后框架的 health: 参数不生效，手动补一条）
            Route::get('up', static fn () => response('OK'));

            // web（保留 Laravel 默认 session/csrf）
            Route::middleware('web')->group(base_path('routes/web.php'));

            // 客户端（移动端）接口：前缀 app，中间件组 client
            Route::middleware('client')->prefix('app')->name('app.')->group(base_path('routes/api.php'));

            // 后台管理接口：前缀 api/admin，中间件组 admin
            Route::middleware('admin')->prefix('api/admin')->name('admin.')->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 仅生产用 Redis 承载限流计数器（throttle:*）。单机默认的 cache store 在多 php-fpm /
        // 多机 worker 下各算各的，限流形同虚设；Redis 是跨进程共享的原子计数真值源。
        // 只在 APP_ENV=production 打开：本地 / 测试（array / database cache）行为不变，
        // 无需为跑测试装 Redis（坑 0-2）。前提是生产 .env 配好 REDIS_* 且装了 predis/predis。
        if (env('APP_ENV') === 'production') {
            $middleware->throttleWithRedis();
        }

        $middleware->alias([
            'jwt.assign.guard' => JWTAssignGuard::class,
            'jwt.guard.auth'   => JWTGuardAuth::class,
            'jwt.auth.refresh' => JWTAuthOrRefresh::class,
            'set.locale'       => SetLocale::class,
        ]);

        // host 后台组：只指定 admin 守卫，不强制认证（放行公开登录路由 + 演示 food 接口）
        $middleware->group('admin', [
            'jwt.assign.guard:admin',
            'throttle:admin',
            'set.locale',
            SubstituteBindings::class,
            OperationLog::class,
        ]);

        // host 客户端（移动端）组：指定 user 守卫
        $middleware->group('client', [
            'jwt.assign.guard:user',
            'throttle:client',
            'set.locale',
            SubstituteBindings::class,
        ]);

        // moo-system 包路由专用组：完整 JWT 强制认证链
        // （config/moo-system.php 的 admin.middleware 指向这里）
        $middleware->group('moo-system', [
            'jwt.assign.guard:admin',
            'jwt.guard.auth:admin',
            'jwt.auth.refresh',
            'throttle:admin',
            'set.locale',
            SubstituteBindings::class,
            OperationLog::class,
        ]);

        // moo-feedback 管理面使用自己的完整认证组；匿名提交仍走包的 public.middleware。
        // 不能回落到为登录接口保留的 admin，也不借用 moo-system 的安全边界。
        $middleware->group('moo-feedback', [
            'jwt.assign.guard:admin',
            'jwt.guard.auth:admin',
            'jwt.auth.refresh',
            'throttle:admin',
            'set.locale',
            SubstituteBindings::class,
            OperationLog::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 这些异常属于预期控制流，不上报；同一异常只报一次
        $exceptions->dontReportDuplicates()->dontReport([
            JWTException::class,
            NotFoundHttpException::class,
            BaseException::class,
        ]);

        // 运行时异常采集:scaffold 3.9.0 起由 moo-monitor-laravel 的 MonitorProvider
        // 自动挂 reportable 钩子,无需手动接入(落盘 storage/moo-monitor/runtimes,推送上云后在云端查看)。

        // 上报节流：阈值放宽到 1000 条/分钟，避免高频 5xx 时关键日志被吞
        $exceptions->throttle(function (Throwable $e) {
            return Limit::perMinute(1000);
        });

        // 接口请求统一走 JSON 异常响应
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->is('app/*') || $request->expectsJson();
        });

        // 认证失败 → 401（同时留痕到 auth.log，方便回溯「谁在什么路由上认证失败」）
        $exceptions->render(function (UnauthorizedHttpException $e, Request $request) {
            logAuth('401 Unauthorized', "{$request->method()} {$request->path()} — {$e->getMessage()}");

            return response()->json(['message' => $e->getMessage()], 401);
        });

        // 校验失败 → { message: 第一条错误, errors: {字段: [...] } }（moo 体系响应约定）
        $exceptions->render(function (ValidationException $e) {
            $errors     = $e->errors();
            $firstError = reset($errors);

            return response()->json([
                'message' => $firstError[0] ?? '参数错误',
                'errors'  => $errors,
            ], $e->status);
        });
    })->create();
