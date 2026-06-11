<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Mooeen\Scaffold\Exceptions\BaseException;
use Mooeen\Scaffold\Support\ExceptionDispatcher;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        using: function (): void {
            // web（保留 Laravel 默认 session/csrf）
            Route::middleware('web')->group(base_path('routes/web.php'));

            // 客户端（移动端）接口：前缀 app，中间件组 client
            Route::middleware('client')->prefix('app')->name('app.')->group(base_path('routes/api.php'));

            // 后台管理接口：前缀 api/admin，中间件组 admin
            Route::middleware('admin')->prefix('api/admin')->name('admin.')->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // JWT 中间件别名与各路由中间件组在 App\Providers\AppServiceProvider::boot() 里
        // 直接注册到 router —— 这样 artisan 命令（console 内核）也能看到这些组，
        // `php artisan moo-system check` 才能正确校验。详见该 provider 的注释。
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 这些异常属于预期控制流，不上报；同一异常只报一次
        $exceptions->dontReportDuplicates()->dontReport([
            JWTException::class,
            NotFoundHttpException::class,
            BaseException::class,
        ]);

        // 接入 moo-scaffold 的运行时异常采集（落盘 storage/scaffold/runtimes，
        // 可在 /scaffold 开发 UI 查看；配 moo-scaffold-cloud 后可推云端）
        $exceptions->reportable(function (Throwable $e): void {
            app(ExceptionDispatcher::class)->dispatch($e);
        });

        // 上报节流：阈值放宽到 1000 条/分钟，避免高频 5xx 时关键日志被吞
        $exceptions->throttle(function (Throwable $e) {
            return Limit::perMinute(1000);
        });

        // 接口请求统一走 JSON 异常响应
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->is('app/*') || $request->expectsJson();
        });

        // 认证失败 → 401
        $exceptions->render(function (UnauthorizedHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        });

        // 校验失败 → { message: 第一条错误, errors: {字段: [...] } }（moo 体系响应约定）
        $exceptions->render(function (ValidationException $e) {
            $errors = $e->errors();
            $firstError = reset($errors);

            return response()->json([
                'message' => $firstError[0] ?? '参数错误',
                'errors' => $errors,
            ], $e->status);
        });
    })->create();
