<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\JWTAssignGuard;
use App\Http\Middleware\JWTAuthOrRefresh;
use App\Http\Middleware\JWTGuardAuth;
use App\Http\Middleware\OperationLog;
use App\Http\Middleware\SetLocale;
use Exception;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
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
        //
        // 必须用反射「按 action 真实存在且 public」逐条注册，而不是无脑 Route::resource：
        // 这套生态的控制器普遍没有 destroy（统一走 destroyBatch）、部分没有 show/index，
        // 无条件注册会产出大量「幻影路由」——调用即 Call to undefined method（500），
        // 还会污染 /scaffold/routes 的 ACL 工具（生产实践的同名宏就是按方法注册的）。
        Route::macro('iResource', function (string $name, string $controller) {
            $hasAction = static function (string $action) use ($controller): bool {
                if (! class_exists($controller)) {
                    return false;
                }

                $reflection = new \ReflectionClass($controller);

                return $reflection->hasMethod($action) && $reflection->getMethod($action)->isPublic();
            };

            if ($hasAction('index')) {
                Route::get($name, [$controller, 'index'])->name($name . '.index');
            }
            if ($hasAction('create')) {
                Route::get($name . '/create', [$controller, 'create'])->name($name . '.create');
            }
            if ($hasAction('store')) {
                Route::post($name, [$controller, 'store'])->name($name . '.store');
            }
            if ($hasAction('trashed')) {
                // 固定段 /trashed 必须先于 show 的 /{id} 注册，否则会被 /{id} 抢匹配（当成 show('trashed')）
                Route::get($name . '/trashed', [$controller, 'trashed'])->name($name . '.trashed');
            }
            if ($hasAction('show')) {
                Route::get($name . '/{id}', [$controller, 'show'])->name($name . '.show');
            }
            if ($hasAction('edit')) {
                Route::get($name . '/{id}/edit', [$controller, 'edit'])->name($name . '.edit');
            }
            if ($hasAction('update')) {
                Route::put($name . '/{id}', [$controller, 'update'])->name($name . '.update');
            }
            // 固定段 DELETE 路由（/forever/{id}、/batch）必须先于 destroy 的 /{id} 注册，
            // 否则 `DELETE {name}/batch` 会被 /{id} 抢匹配成 destroy('batch')，destroyBatch 永不命中
            // （坑 0-1：审查复现的路由顺序 bug；另有 boot() 里的 Route::pattern('id',...) 做第二道保险）。
            if ($hasAction('forceDestroy')) {
                Route::delete($name . '/forever/{id}', [$controller, 'forceDestroy'])->name($name . '.forceDestroy');
            }
            if ($hasAction('destroyBatch')) {
                Route::delete($name . '/batch', [$controller, 'destroyBatch'])->name($name . '.destroyBatch');
            }
            if ($hasAction('destroy')) {
                Route::delete($name . '/{id}', [$controller, 'destroy'])->name($name . '.destroy');
            }
            if ($hasAction('restore')) {
                Route::patch($name . '/restore', [$controller, 'restore'])->name($name . '.restore');
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];

        // Collection 表单宏三件套：moo-scaffold 的 getFormConfig / moo-system 包控制器
        // 构建 form_widgets 时会在 Collection 上调 putMore/default/forgetMore —— 这是
        // host 侧必须注册的宏契约，缺了则 departments/create、personnels/{id}/edit 等
        // 表单端点直接 500「Collection::putMore does not exist」（坑 #29，冒烟三件套实测暴露）。
        // 语义：putMore 按点号路径写入（最多三段）；default 是 putMore 的 default 快捷；
        // forgetMore 按点号路径批量移除。
        Collection::macro('putMore', function (string $key, $value) {
            if (count(explode('.', $key)) > 3) {
                throw new Exception('Collection putMore $key error');
            }

            data_set($this->items, $key, $value);

            return $this;
        });

        Collection::macro('default', function (string $field, $value) {
            return $this->putMore("{$field}.default", $value);
        });

        Collection::macro('forgetMore', function ($keys) {
            if (! is_array($keys)) {
                $keys = array_map('trim', explode(',', $keys));
            }

            foreach ($keys as $key) {
                if (count(explode('.', $key)) > 3) {
                    throw new Exception('Collection forgetMore $keys error');
                }

                data_forget($this->items, $key);
            }

            return $this;
        });

        // 全局把 {id} 路由参数钉成正整数（雪花/自增主键都满足）。第二道保险：即便 iResource 宏
        // 的注册顺序被改回旧法，`{name}/batch`、`{name}/forever/x` 也不会被 destroy 的 /{id} 抢匹配
        // （'batch' 不匹配 [1-9][0-9]*）。副作用可控：本生态所有 {id} 均为正整数主键（坑 0-1）。
        Route::pattern('id', '[1-9][0-9]*');

        // 接口限流（生产实践值：登录在内的后台接口 300 次/分钟，移动端 1000 次/分钟；
        // 已登录按用户 ID 计数，未登录按 IP）
        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });
        RateLimiter::for('client', function (Request $request) {
            return Limit::perMinute(1000)->by($request->user()?->id ?: $request->ip());
        });

        // 登录接口专用限流：组限流的 300 次/分钟对 /authenticate 来说防不了爆破。
        // 按「账号 + IP」计数（拿不到账号字段就按 IP），5 次/分钟——锁账号尝试也锁分布式换号。
        RateLimiter::for('login', function (Request $request) {
            $account = (string) ($request->input('account') ?: $request->input('email') ?: '');

            return Limit::perMinute(5)->by(sha1($account . '|' . $request->ip()));
        });

        // JWT 中间件别名
        $router->aliasMiddleware('jwt.assign.guard', JWTAssignGuard::class);
        $router->aliasMiddleware('jwt.guard.auth', JWTGuardAuth::class);
        $router->aliasMiddleware('jwt.auth.refresh', JWTAuthOrRefresh::class);

        // 本地化：按请求头切 app locale（各业务组都挂，见下）
        $router->aliasMiddleware('set.locale', SetLocale::class);

        // 中间件组在这里注册（而非只写在 bootstrap/app.php 的 withMiddleware）。
        // 原因：withMiddleware 的组只有在「HTTP 内核」实例化时才同步到 router；artisan 命令
        // 走「Console 内核」，不会同步，导致 `moo-system check` 在命令行看不到这些组。
        // 在 provider boot() 注册则 console / HTTP 都生效（生产若 route:cache 也无妨）。

        // host 后台组：只指定 admin 守卫，不强制认证（放行公开登录路由 + 演示 food 接口）
        $router->middlewareGroup('admin', [
            'jwt.assign.guard:admin',
            'throttle:admin',
            'set.locale',
            SubstituteBindings::class,
            OperationLog::class,
        ]);

        // host 客户端（移动端）组：指定 user 守卫
        $router->middlewareGroup('client', [
            'jwt.assign.guard:user',
            'throttle:client',
            'set.locale',
            SubstituteBindings::class,
        ]);

        // moo-system 包路由专用组：完整 JWT 强制认证链
        // （config/moo-system.php 的 admin.middleware 指向这里）
        $router->middlewareGroup('moo-system', [
            'jwt.assign.guard:admin',
            'jwt.guard.auth:admin',
            'jwt.auth.refresh',
            'throttle:admin',
            'set.locale',
            SubstituteBindings::class,
            OperationLog::class,
        ]);
    }
}
