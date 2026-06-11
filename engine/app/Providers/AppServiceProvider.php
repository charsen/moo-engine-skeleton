<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\JWTAssignGuard;
use App\Http\Middleware\JWTAuthOrRefresh;
use App\Http\Middleware\JWTGuardAuth;
use App\Http\Middleware\OperationLog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // iResource 宏：moo-scaffold 生成的控制器路由、moo-system 包路由都依赖它。
        // 放在 register()（早于任何 provider 的 boot()），确保 moo-system 在 boot() 里
        // 加载 routes/admin.php 调用 iResource 时宏已就绪。
        Route::macro('iResource', function (string $name, string $controller, array $options = []) {
            Route::get($name.'/trashed', $controller.'@trashed')->name($name.'.trashed');
            Route::delete($name.'/forever/{id}', $controller.'@forceDestroy')->name($name.'.forceDestroy');
            Route::delete($name.'/batch', $controller.'@destroyBatch')->name($name.'.destroyBatch');
            Route::patch($name.'/restore', $controller.'@restore')->name($name.'.restore');
            Route::resource($name, $controller, $options);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];

        // 接口限流（生产项目实践值：登录在内的后台接口 300 次/分钟，移动端 1000 次/分钟；
        // 已登录按用户 ID 计数，未登录按 IP）
        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });
        RateLimiter::for('client', function (Request $request) {
            return Limit::perMinute(1000)->by($request->user()?->id ?: $request->ip());
        });

        // JWT 中间件别名
        $router->aliasMiddleware('jwt.assign.guard', JWTAssignGuard::class);
        $router->aliasMiddleware('jwt.guard.auth', JWTGuardAuth::class);
        $router->aliasMiddleware('jwt.auth.refresh', JWTAuthOrRefresh::class);

        // 中间件组在这里注册（而非只写在 bootstrap/app.php 的 withMiddleware）。
        // 原因：withMiddleware 的组只有在「HTTP 内核」实例化时才同步到 router；artisan 命令
        // 走「Console 内核」，不会同步，导致 `moo-system check` 在命令行看不到这些组。
        // 在 provider boot() 注册则 console / HTTP 都生效（生产若 route:cache 也无妨）。

        // host 后台组：只指定 admin 守卫，不强制认证（放行公开登录路由 + 演示 food 接口）
        $router->middlewareGroup('admin', [
            'jwt.assign.guard:admin',
            'throttle:admin',
            SubstituteBindings::class,
            OperationLog::class,
        ]);

        // host 客户端（移动端）组：指定 user 守卫
        $router->middlewareGroup('client', [
            'jwt.assign.guard:user',
            'throttle:client',
            SubstituteBindings::class,
        ]);

        // moo-system 包路由专用组：完整 JWT 强制认证链
        // （config/moo-system.php 的 admin.middleware 指向这里）
        $router->middlewareGroup('moo-system', [
            'jwt.assign.guard:admin',
            'jwt.guard.auth:admin',
            'jwt.auth.refresh',
            'throttle:admin',
            SubstituteBindings::class,
            OperationLog::class,
        ]);
    }
}
