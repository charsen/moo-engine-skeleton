# 第 3 章　JWT 登录认证（自建最简用户）

目标：**不依赖任何付费包**，用 Laravel 自带的 `users` 表 + 一个最简 User 模型，
把整套 JWT 登录跑通：登录拿 token → 无 token 401 → 带 token 200 → 刷新 → 登出。

> 这一章建立的认证骨架（中间件、守卫、路由结构）是后面所有章节的地基。
> 第 7 章接入 moo-system（进阶包）时，只是把**后台守卫的主体换成 Personnel**——
> 骨架一行不用动，这正是本章要体会的设计。

**前置与约定**（只读本章的话，先看这四条）：

- 本章接着第 2 章做：`routes/admin.php` 和 `bootstrap/app.php` 的 `then:` 挂载是第 2 章建好的；
  本地服务跑在 **8088 端口**，启动命令是第 2 章的
  `PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload`。
- 文中「坑 #N」指 [docs/README.md](./README.md) 里「踩过的坑速查」表的编号，本章不再展开。
- 文中的仓库文件路径（如 `app/Models/User.php`）都相对仓库的 **`engine/` 子目录**——
  仓库根目录只有 `docs/`、`engine/` 等，按根目录字面找 `app/`、`database/` 会落空。
- 📦 标注 = 仓库里的对应文件是**第 7 章之后的最终版**，与本章要写的版本不一致——
  看到 📦 就照文档写，别照仓库抄。

---

## 3.1 安装 jwt-auth

```bash
composer require "php-open-source-saver/jwt-auth:~2.8.3"
php artisan vendor:publish --provider="PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider"
php artisan jwt:secret --force        # 生成 JWT_SECRET 写入 .env
```

得到 `config/jwt.php`（先用默认值，第 4 章逐项加固它）。

> ⚠️ `jwt:secret --force` 会**无条件覆盖** `.env` 里已有的 `JWT_SECRET`——换密钥 =
> 所有已签发的 token 立刻全部作废、全员被踢重新登录（`config/jwt.php` 里的注释也这么写）。
> 全新项目随便跑；在已有环境上跑之前想清楚。
>
> 版本提示：这里用 `~2.8.3`，允许 2.8 系列的补丁更新，但不自动跨到 2.9。
> 原因是 2.9 起要求 PHP 8.3，而本骨架承诺 PHP 8.2+；若写成宽泛的 `^2.8`，
> Composer 会在 PHP 8.3 机器装到 2.9、在 PHP 8.2 机器装到 2.8，团队成员得到不同依赖树。
> 安装后运行 `composer show php-open-source-saver/jwt-auth`，本教程当前应为 **2.8.3**。

## 3.2 自建最简用户：User 实现 JWTSubject

Laravel 自带 `users` 表（id / name / email / password），直接用它当认证主体。

### 3.2.1 改造 User 模型

**编辑** `app/Models/User.php`，完整代码如下（含第 5 章 ACL 的 actions 列前置）：

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'actions'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'actions' => 'array',
        ];
    }

    // ========== JWT 接口实现 ==========

    /** token 的 sub 声明 = 主键 */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * 自定义声明：把「本 token 是哪个守卫签发的」写进 token。
     *
     * Auth::getDefaultDriver() 返回的是当前默认守卫名（如 admin），不是 jwt 这个 driver 名；
     * 「当前默认守卫」由 3.4 要写的 JWTAssignGuard 按路由指派（Auth::shouldUse）
     */
    public function getJWTCustomClaims(): array
    {
        return ['guard' => Auth::getDefaultDriver()];
    }

    // ========== ACL 最小实现（第 5 章）==========

    /**
     * 获取授权动作列表（第 5 章的 Gate 会调用）
     *
     * @return array<string>
     */
    public function getActions(): array
    {
        return $this->actions ?? [];
    }

    /** 是否超级管理员（actions 里有 'is_root' 字面量） */
    public function isRoot(): bool
    {
        return in_array('is_root', $this->getActions(), true);
    }
}
```

核心就两个 `JWTSubject` 接口方法：
- `getJWTIdentifier()`：token 的 sub 声明 = 主键
- `getJWTCustomClaims()`：自定义声明，把 `guard` 写进 token

`getActions()` 和 `isRoot()` 是第 5 章 ACL 的最小授权存储，本章先不用理解。

> 📦 仓库 `engine/app/Models/User.php` 的 `isRoot()` 实为 `return false`（注释：自增主键体系下不启用，
> 超级权限统一走 `actions` 里的 `is_root` 字面量，由第 5 章 host 的 `AuthServiceProvider` 在 Gate 里用
> `getActions()` 兜底判定）。两种写法对 `is_root` 用户的最终效果一致、本章也用不到该方法——这里按上面这版
> （`in_array` 更直观）写即可；仓库那行 `return false` 是第 7 章主体切到 Personnel 后定的最终形态。

### 3.2.2 给 users 表加 actions 列

第 5 章会给 User 加 `actions` 列（ACL 最小授权存储）——为了让下面的 UserSeeder 能运行，
现在先建好这个列。

**新建迁移文件** `database/migrations/2024_01_15_100000_add_actions_to_users_table.php`
（时间戳用当前时间，格式为 `年_月_日_时分秒`），完整代码如下：

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('actions')->nullable()->comment('授权动作列表（第 5 章 ACL）');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('actions');
        });
    }
};
```

执行迁移：

```bash
php artisan migrate
```

### 3.2.3 建第一个用户（UserSeeder）

**新建文件** `database/seeders/UserSeeder.php`，完整代码如下：

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrNew(['email' => 'admin@example.com']);
        $user->name = '管理员';
        $user->password = 'password';
        $user->actions = ['is_root'];  // 第 5 章 ACL：超级权限
        $user->email_verified_at = now();
        $user->save();

        $this->command?->info('UserSeeder：admin@example.com / password（is_root 超级权限）。');
    }
}
```

**编辑** `database/seeders/DatabaseSeeder.php`，修改 `run()` 方法只调用 UserSeeder
（📦 仓库版的 `DatabaseSeeder` 还列着第 7 章的四个 moo-system seeder——那四个依赖
moo-system 包的 Personnel 等模型，你的项目现在还没装包，照抄仓库版跑起来会报错——
本章先这样写，第 7 章再加回去）：

```php
public function run(): void
{
    $this->call([
        UserSeeder::class,
    ]);
}
```

执行 seeder：

```bash
php artisan db:seed --class=UserSeeder
# UserSeeder：admin@example.com / password（is_root 超级权限）
```

> 第 4 章测试的 `$seed = true` 跑的就是 `DatabaseSeeder`，所以它必须包含 UserSeeder。

## 3.3 配置守卫（guard）

先解释**守卫**：Laravel 里一个 guard 就是一条独立的"认证通道"（用什么方式认证、
查哪张用户表）。本骨架规划两条 JWT 通道：

- `admin`：后台接口（前缀 `api/admin`）；
- `user`：移动端接口（前缀 `app`）。

改 `config/auth.php`，**本章两条通道都先指向自建的 users**：

```php
'defaults' => ['guard' => env('AUTH_GUARD', 'admin'), 'passwords' => 'users'],
'guards' => [
    'web'   => ['driver' => 'session', 'provider' => 'users'],
    'admin' => ['driver' => 'jwt', 'provider' => 'users'],
    'user'  => ['driver' => 'jwt', 'provider' => 'users'],
],
'providers' => [
    'users' => ['driver' => 'eloquent', 'model' => App\Models\User::class],
],
```

> 📦 **第 7 章的变化**：接入 moo-system 后，`admin` 守卫的 provider 会切到
> `personnels`（包里的 Personnel 模型）；`user` 守卫**永久**用自建 User。
> 所以仓库里的 `config/auth.php` 是切换后的最终版，本章按上面的写。


## 3.4 三个 JWT 中间件 + 中间件组

需要 3 个中间件，完整代码如下：

| 文件 | 职责 |
|---|---|
| `JWTAssignGuard.php` | 按路由参数指定当前请求用哪个守卫（`Auth::shouldUse`） |
| `JWTGuardAuth.php` | 校验 token 里的 guard 声明和路由要求一致；没带 token 放行 |
| `JWTAuthOrRefresh.php` | 强制认证；token 过期但可续签时自动换新 token 放进响应头 |

> **到底谁兜底 401？** `JWTGuardAuth` 对「没带 token」是放行的——**强制认证和 401
> 全由 `jwt.auth.refresh`（`JWTAuthOrRefresh`）负责**：无 token、token 非法、续签失败，
> 它都抛 `UnauthorizedHttpException`，再由下文 `bootstrap/app.php` 的 render 渲染成
> JSON 401。所以 3.6 ① 的「无 token → 401」来自 `jwt.auth.refresh`，与表格并不矛盾。

### 3.4.1 JWTAssignGuard 中间件

**新建文件** `app/Http/Middleware/JWTAssignGuard.php`，完整代码：

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 按路由参数指派守卫（Auth::shouldUse）
 *
 * 用法：Route::middleware('jwt.assign.guard:admin')
 */
class JWTAssignGuard
{
    public function handle(Request $request, Closure $next, string $guard): Response
    {
        Auth::shouldUse($guard);

        return $next($request);
    }
}
```

### 3.4.2 JWTGuardAuth 中间件

**新建文件** `app/Http/Middleware/JWTGuardAuth.php`，完整代码：

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * JWT 守卫验证：校验 token 里的 guard 声明和路由要求一致
 *
 * 没带 token → 放行（401 由 jwt.auth.refresh 负责）
 * 带了 token 但 guard 不匹配 → 401 Guard Unverified
 */
class JWTGuardAuth
{
    public function __construct(protected JWTAuth $auth) {}

    public function handle(Request $request, Closure $next, $guard = null): mixed
    {
        $guard = $guard === null ? config('auth.defaults.guard') : $guard;

        try {
            $tokenGuard = $this->auth->parseToken()->getClaim('guard');
        } catch (TokenExpiredException $e) {
            // 过期 token 仍要核对 guard：底层 decode 会验签，但不因 exp 中断。
            try {
                $claims = $this->auth->manager()->getJWTProvider()
                    ->decode((string) $request->bearerToken());
                $tokenGuard = $claims['guard'] ?? null;
            } catch (JWTException $e) {
                return $next($request);
            }
        } catch (JWTException $e) {
            // 没有可用 token：放行，交给 jwt.auth.refresh 决定是否强制 401。
            return $next($request);
        }

        if ($tokenGuard !== $guard) {
            throw new UnauthorizedHttpException('jwt-auth', 'Guard Unverified');
        }

        return $next($request);
    }
}
```

> 这里不能在所有情况下直接调完整 `payload()` 后把异常统一变成 401。
> 否则过期 token 会在本中间件提前终止，后面 `JWTAuthOrRefresh` 的自动续签分支
> 永远无法执行。底层 provider 的 `decode()` 仍会验证签名，只是允许我们在过期时读取
> `guard` claim，再把真正的过期/续签处理交给下一个中间件。

### 3.4.3 JWTAuthOrRefresh 中间件

**新建文件** `app/Http/Middleware/JWTAuthOrRefresh.php`，完整代码：

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * JWT 强制认证 + 近过期自动续签（无感刷新）
 *
 * - 无 token / token 非法 → 401
 * - token 有效 → 放行
 * - token 过期但在刷新窗口内 → 自动续签，新 token 放进响应头 authorization
 *
 * 关键：认证门用 parseToken()->authenticate()，token 过期时它会**抛 TokenExpiredException**，
 * 下面的续签分支才进得去。若改用 auth()->check()，过期会被它内部吞成 false（永不抛异常），
 * catch(TokenExpiredException) 就成了永不可达的死代码——中间件看似有续签、实则从不续签。
 */
class JWTAuthOrRefresh
{
    public function __construct(protected JWTAuth $auth) {}

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            // token 有效则认证通过、直接放行；过期会抛 TokenExpiredException 落到下面续签
            if ($this->auth->parseToken()->authenticate()) {
                return $next($request);
            }
            throw new UnauthorizedHttpException('jwt-auth', 'Token not provided');
        } catch (TokenInvalidException $e) {
            throw new UnauthorizedHttpException('jwt-auth', $e->getMessage());
        } catch (TokenExpiredException $e) {
            // token 过期但在刷新窗口内 → 自动续签
            try {
                $token = $this->auth->refresh();

                // refresh() 只返回新 token，不会自动认证其中的用户。
                // 用新 token 再认证一次，后续业务才能读到当前用户；
                // 用户数据已不存在时（如开发期 reseed 删人）明确 401。
                if (! $this->auth->setToken($token)->authenticate()) {
                    throw new UnauthorizedHttpException('jwt-auth', 'Token subject not found');
                }
            } catch (JWTException $e) {
                throw new UnauthorizedHttpException('jwt-auth', $e->getMessage());
            }

            // 续签成功：把新 token 放进响应头 authorization（前端据此无感换 token）
            $response = $next($request);
            $response->headers->set('authorization', $token);

            return $response;
        } catch (JWTException $e) {
            throw new UnauthorizedHttpException('jwt-auth', $e->getMessage());
        }
    }
}
```

> `refresh()` 只负责签发新 token，不会自动把新 token 里的 `sub` 恢复成当前用户。
> 因此不能只在续签后检查 `$this->auth->user()`；必须用新 token 再跑一次
> `authenticate()`，同时验证用户仍存在并为本次业务请求建立认证上下文。

> 📦 第 7 章接入 moo-system 后，续签成功处会**再补一行**
> `if (! empty($old_token) && class_exists(UpdateLoginTokenJob::class)) { UpdateLoginTokenJob::dispatch($old_token, $token); }`
> （同步包里的登录管理记录）。它用 `class_exists` 守卫——未装 moo-system 时静默跳过，
> 所以本章这版**不依赖任何付费包、可直接抄**，与仓库 `engine/app/Http/Middleware/JWTAuthOrRefresh.php`
> 的差异仅此一行（仓库版即上面这段 + 这行 Job）。

### 3.4.4 注册中间件别名和组

编辑 `app/Providers/AppServiceProvider.php`，在 `boot()` 方法里添加（如果文件还没有 `boot()` 方法，就新增一个）：

```php
public function boot(): void
{
    $router = $this->app['router'];

    // 注册 JWT 中间件别名
    $router->aliasMiddleware('jwt.assign.guard', \App\Http\Middleware\JWTAssignGuard::class);
    $router->aliasMiddleware('jwt.guard.auth',   \App\Http\Middleware\JWTGuardAuth::class);
    $router->aliasMiddleware('jwt.auth.refresh', \App\Http\Middleware\JWTAuthOrRefresh::class);

    // 注册中间件组
    // admin 组：只指定守卫、不强制认证（放行登录路由、第 2 章的 food 接口）
    $router->middlewareGroup('admin', [
        'jwt.assign.guard:admin',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]);

    // client 组：移动端
    $router->middlewareGroup('client', [
        'jwt.assign.guard:user',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]);

    // moo-system 组：完整强制认证链（第 7 章给包路由用，现在先建好）
    $router->middlewareGroup('moo-system', [
        'jwt.assign.guard:admin',
        'jwt.guard.auth:admin',
        'jwt.auth.refresh',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]);
}
```

记得在文件顶部添加 use 语句（如果还没有的话）：

```php
use Illuminate\Routing\Middleware\SubstituteBindings;
```

> **命名对照（容易绕晕，先记下来）**——同一条通道在三处用了不同名字：
>
> | 通道 | 守卫名（config/auth.php） | 中间件组名（本节） | URL 前缀（bootstrap/app.php） |
> |---|---|---|---|
> | 后台 | `admin` | `admin` | `api/admin` |
> | 移动端 | `user` | `client` | `app` |
>
> 后台三处都叫 admin 最省心；移动端则是守卫 `user`、组 `client`、前缀 `app` 三个名字。
> 区分规则：`jwt.assign.guard:X` / `jwt.guard.auth:X` 冒号后的 X 永远是**守卫名**；
> `middlewareGroup('X', …)` 和 `Route::middleware('X')` 里的 X 是**组名**。

> **为什么写在 provider 的 `boot()` 而不是 `bootstrap/app.php` 的 `withMiddleware()`？**
> 后者注册的组只有「HTTP 内核」实例化时才同步到 router，artisan 命令走「Console 内核」
> 看不到——第 7 章的 `moo-system check` 自检就靠这一点才能通过（坑 #7）。
>
> 📦 第 4 章会往组里加 `throttle:*`（限流），第 7 章再加 `OperationLog`（操作日志）。
> 仓库最终版三个组并不一样：`admin` 和 `moo-system` 组两样都加了；`client` 组只加了
> `throttle:client`，**没有 OperationLog**（移动端不记操作日志）。

### 3.4.5 修改 bootstrap/app.php 路由挂载

编辑 `bootstrap/app.php`，**把第 2 章写的 `then:` 整段替换**成 `using:`——
区别是挂载点可以指定中间件组（第 2 章还没有组，现在有了）：

```php
->withRouting(
    commands: __DIR__.'/../routes/console.php',
    using: function (): void {
        // 健康检查（用了 using: 后框架的 health: 参数不生效，手动补一条）
        Route::get('up', static fn () => response('OK'));

        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::middleware('client')->prefix('app')->name('app.')->group(base_path('routes/api.php'));
        Route::middleware('admin')->prefix('api/admin')->name('admin.')->group(base_path('routes/admin.php'));
    },
)
```

### 3.4.6 配置异常 JSON 渲染

继续在 `bootstrap/app.php` 的 `withExceptions()` 里添加两条 JSON 渲染（完整版见仓库 `bootstrap/app.php`，第 4 章还会扩充）：

```php
->withExceptions(function (Exceptions $exceptions) {
    // API 路由强制返回 JSON
    $exceptions->shouldRenderJsonWhen(fn ($request, $e) =>
        $request->is('api/*') || $request->is('app/*') || $request->expectsJson());

    // 401 未授权
    $exceptions->render(fn (\Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException $e) =>
        response()->json(['message' => $e->getMessage()], 401));

    // 422 校验错误
    $exceptions->render(function (\Illuminate\Validation\ValidationException $e) {
        $errors = $e->errors();
        return response()->json([
            'message' => reset($errors)[0] ?? '参数错误',
            'errors' => $errors,
        ], $e->status);
    });
})
```

记得在文件顶部添加 use 语句：

```php
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Route;
```


## 3.5 登录控制器

**新建文件** `app/Admin/Controllers/AuthController.php`，完整代码如下：

```php
<?php

declare(strict_types=1);

namespace App\Admin\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController
{
    /** 登录：手动校验 → 签发 JWT（guard=admin 由 User::getJWTCustomClaims 动态写入） */
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

        $token = Auth::guard('admin')->login($user);

        return response()->json(['data' => [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'token' => $token,
            'expires_in' => Auth::guard('admin')->factory()->getTTL() * 60,
        ]]);
    }

    /** 当前登录人 */
    public function me(): JsonResponse
    {
        $user = Auth::guard('admin')->user();

        return response()->json(['data' => ['user' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
        ]]]);
    }

    /** 主动刷新 token（第 4 章会重构它：移出自动续签组 + 异常处理） */
    public function refresh(): JsonResponse
    {
        $token = Auth::guard('admin')->refresh(false, false);

        return response()->json(['data' => [
            'token' => $token,
            'expires_in' => Auth::guard('admin')->factory()->getTTL() * 60,
        ]]);
    }

    /** 退出（永久拉黑当前 token） */
    public function logout(): JsonResponse
    {
        Auth::guard('admin')->logout(true);

        return response()->json(['message' => 'ok']);
    }
}
```

> **三个「长得像 Laravel 却不是」的调用**——jwt-auth 用自己的 `JWTGuard` 整个替换了
> 守卫实现，API 语义跟着变了，不是上面的代码写错：
>
> - `login($user)` **返回 token 字符串**。Laravel 原生 `login()` 返回 void，这里能用
>   `$token =` 接住，是 JWTGuard 重写后的行为（签发并返回 token）；
> - `refresh(false, false)` 的两个参数是 `forceForever`（旧 token 是否永久拉黑）和
>   `resetClaims`（是否丢弃自定义声明）。`resetClaims = false` 是为了保住 token 里的
>   `guard` 声明——还要配合第 4 章的 `persistent_claims` 配置，否则续签出的 token
>   过不了 `JWTGuardAuth`（生产真坑，坑 #10，仓库 AuthController 的注释有详细说明）；
> - `logout(true)` 的 `true` 也是 `forceForever`：把当前 token **永久**拉黑。

**编辑** `routes/admin.php`，添加路由（记得文件顶部添加 `use App\Admin\Controllers\AuthController;`）：

```php
Route::post('authenticate', [AuthController::class, 'authenticate'])->name('authenticate');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

Route::group(['middleware' => ['jwt.guard.auth:admin', 'jwt.auth.refresh']], function () {
    Route::get('me/info', [AuthController::class, 'me'])->name('me.info');
    Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
});
```

> `logout` **故意放在保护组外**，采用幂等退出契约：无 token、垃圾 token、已拉黑 token
> 都统一返回 `200 {"message":"ok"}`，避免前端在本地会话已经残缺时还卡在退出流程。
> 这不表示这些请求“认证成功”——jwt-auth 的 `JWTGuard::logout()` 只是拉黑不了就当作已经退出。
> 因此不能拿 `logout` 自身的 200 判断退出是否生效；必须像 3.6 ⑤ 那样，先用一个仍有效的
> token 调退出，再拿同一个 token 访问受保护接口并确认变成 401。

> 📦 **这一段别照仓库抄**——仓库 `routes/admin.php` 是第 4 章重构后的最终版，有两处不同：
> ① 仓库的 `authenticate` 挂了 `throttle:login` 登录限流（第 4 章加的）；
> ② 仓库的 `refresh` 已**移出** `jwt.auth.refresh` 组、只挂 `jwt.guard.auth:admin`——
> 把 refresh 放进自动续签组，过期 token 会被中间件和控制器各续签一次，派生出一个永远
> 无法作废的「孤儿 token」（坑 #18）。本章先按上面的简单版写，第 4 章 §4.4 专门重构它；
> 下面 3.6 ④ 用的是**未过期**的 token，不会触发这个缺陷。

## 3.6 真机验证

服务起着（多 worker，命令见本章开头的前置约定，原因见坑 #4），逐条跑：

```bash
BASE=http://127.0.0.1:8088

# ① 无 token → 401
curl -s -o /dev/null -w "%{http_code}\n" $BASE/api/admin/me/info        # 401

# ② 登录拿 token
TOKEN=$(curl -s -X POST $BASE/api/admin/authenticate \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}' \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')

# ③ 带 token → 200
curl -s $BASE/api/admin/me/info -H "Authorization: Bearer $TOKEN"
# {"data":{"user":{"id":1,"name":"管理员","email":"admin@example.com"}}}

# ④ 刷新 → 旧 token 立即作废、新 token 可用
NEW_TOKEN=$(curl -s -X POST $BASE/api/admin/refresh -H "Authorization: Bearer $TOKEN" \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
curl -s -o /dev/null -w "%{http_code}\n" $BASE/api/admin/me/info -H "Authorization: Bearer $TOKEN"       # 401（旧 token 已被 refresh 拉黑）
curl -s -o /dev/null -w "%{http_code}\n" $BASE/api/admin/me/info -H "Authorization: Bearer $NEW_TOKEN"   # 200

# ⑤ 登出 → 该 token 立即 401
curl -s -X POST $BASE/api/admin/logout -H "Authorization: Bearer $NEW_TOKEN"                             # {"message":"ok"}
curl -s -o /dev/null -w "%{http_code}\n" $BASE/api/admin/me/info -H "Authorization: Bearer $NEW_TOKEN"   # 401
```

> 为什么 ④ 里旧 token **立即** 401：本章 `config/jwt.php` 全用默认值，黑名单宽限期
> `blacklist_grace_period` 默认是 0——refresh 一执行，旧 token 当场进黑名单。
> 一个容易误判的点：拿**已拉黑**的旧 token 去调 logout，照样返回 200——`JWTGuard`
> 的 `logout()` 对拉黑失败是吞掉异常照常登出的（幂等设计），所以「登出是否生效」
> 必须像 ⑤ 那样用一个**还有效的** token 验证。宽限期为什么生产上要调成 90 秒，
> 第 4 章讲（坑 #11）。

> 本节 5 条命令覆盖的是新手先要跑通的正常链路；`JWTAuthOrRefresh` 的“过期但仍在
> 续期窗口内 → 自动换新 token”分支，靠普通未过期 token 触发不了。第 4 章 4.9 会专门
> 构造过期 token，验证业务仍返回 200、响应头带新 token，以及错误 guard 不能跨端续签。

---

## 本章产出

- jwt-auth 直接依赖装好（不靠任何其它包传递）；
- 自建最简 User 实现 `JWTSubject`，第一个用户 `admin@example.com / password`；
- admin / user / moo-system 三个中间件组就位（后两个为后面章节预埋）；
- 登录 / me / 刷新 / 登出全链路真机通过（401 → 200 → 401）。

下一章：把这套"能跑"的 JWT 加固到"能上生产"。
