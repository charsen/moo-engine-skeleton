# 第 3 章　JWT 登录认证（自建最简用户）

目标：使用 Laravel 自带的 User 跑通 JWT 登录、鉴权、刷新和登出，不依赖商业包。

前置：已完成第 2 章；命令均在 `engine/` 执行，服务端口为 `8088`。
仓库保存最终代码，与本章不同时以正文为准。

---

## 3.1 安装 jwt-auth

```bash
composer require "php-open-source-saver/jwt-auth:~2.8.3"
php artisan vendor:publish --provider="PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider"
php artisan jwt:secret --force        # 生成 JWT_SECRET 写入 .env
```

得到 `config/jwt.php`（先用默认值，第 4 章逐项加固它）。

> `jwt:secret --force` 会覆盖旧密钥，使现有 token 全部失效；只在新项目或计划换密时执行。
> `~2.8.3` 将依赖限制在支持 PHP 8.2 的 2.8 系列。

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

> 第 7 章接入 Personnel 后会调整 `isRoot()`；本章按上面的 User 版本实现。

### 3.2.2 给 users 表加 actions 列

第 5 章会给 User 加 `actions` 列（ACL 最小授权存储）——为了让下面的 UserSeeder 能运行，
现在先建好这个列。

先确认项目里没有同用途迁移，并查看当前迁移账本：

```bash
find database/migrations -name '*add_actions_to_users_table.php' -print
php artisan migrate:status
php artisan tinker --execute="var_dump(Schema::hasColumn('users', 'actions'));"
```

按结果处理：

- 没有同名迁移且输出 `bool(false)`：继续执行下面的 `make:migration`。
- 已有迁移、状态为 `Pending` 且输出 `bool(false)`：不要重复创建，直接执行该迁移。
- 已有迁移、状态为 `Ran` 且输出 `bool(true)`：本节已经完成，跳到 3.2.3。
- 迁移为 `Pending` 但列已存在：迁移账本与表结构不一致。不要在迁移里用
  `Schema::hasColumn()` 静默跳过；按第 1.3 节换一个独立空库后重新迁移。

确认需要新建后，让 Artisan 自动生成当前时间戳文件：

```bash
php artisan make:migration add_actions_to_users_table --table=users
```

打开命令输出的 `database/migrations/*_add_actions_to_users_table.php`，写入：

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

**编辑** `database/seeders/DatabaseSeeder.php`，让 `run()` 只调用 `UserSeeder`。
moo-system 的 seeder 等第 7 章安装包后再加入：

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

> 第 7 章会把 `admin` provider 改为 Personnel；`user` 仍使用自建 User。


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

> 第 7 章会在续签成功后同步 moo-system 的登录记录；本章暂不依赖商业包。

### 3.4.4 注册中间件别名和组

编辑 `bootstrap/app.php`，在 `withMiddleware()` 回调里注册别名和组：

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'jwt.assign.guard' => \App\Http\Middleware\JWTAssignGuard::class,
        'jwt.guard.auth'   => \App\Http\Middleware\JWTGuardAuth::class,
        'jwt.auth.refresh' => \App\Http\Middleware\JWTAuthOrRefresh::class,
    ]);

    // 注册中间件组
    // admin 组：只指定守卫、不强制认证（放行登录路由、第 2 章的 food 接口）
    $middleware->group('admin', [
        'jwt.assign.guard:admin',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]);

    // client 组：移动端
    $middleware->group('client', [
        'jwt.assign.guard:user',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]);

    // moo-system 组：完整强制认证链（第 7 章给包路由用，现在先建好）
    $middleware->group('moo-system', [
        'jwt.assign.guard:admin',
        'jwt.guard.auth:admin',
        'jwt.auth.refresh',
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ]);
})
```

记得在 `bootstrap/app.php` 顶部添加 use 语句（如果还没有的话）：

```php
use Illuminate\Foundation\Configuration\Middleware;
```

> **命名对照**：
>
> | 通道 | 守卫名（config/auth.php） | 中间件组名（本节） | URL 前缀（bootstrap/app.php） |
> |---|---|---|---|
> | 后台 | `admin` | `admin` | `api/admin` |
> | 移动端 | `user` | `client` | `app` |
>
> `jwt.assign.guard:X` / `jwt.guard.auth:X` 中的 X 是**守卫名**；
> `$middleware->group('X', …)` 和 `Route::middleware('X')` 里的 X 是**组名**。

`withMiddleware()` 是 HTTP 中间件配置的唯一入口。不过 Laravel 12 只有在解析 HTTP Kernel
时才会把这里的组同步到 Router；普通 Artisan 命令只解析 Console Kernel。为了让第 7 章的
`moo-system check` 也能读取同一份组配置，在 `AppServiceProvider.php` 顶部加入：

```php
use Illuminate\Contracts\Http\Kernel as HttpKernel;
```

并在 `boot()` 开头加入：

```php
if ($this->app->runningInConsole()) {
    $this->app->make(HttpKernel::class);
}
```

这里只负责让 Console 解析 HTTP Kernel，不重复定义别名或组。第 4 章会在现有组中加入限流，
第 7 章再加入后台操作日志。

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

> jwt-auth 的 `JWTGuard` 与 Laravel session guard 有三处不同：
>
> - `login($user)` **返回 token 字符串**。Laravel 原生 `login()` 返回 void，这里能用
>   `$token =` 接住，是 JWTGuard 重写后的行为（签发并返回 token）；
> - `refresh(false, false)` 的两个参数是 `forceForever`（旧 token 是否永久拉黑）和
>   `resetClaims`（是否丢弃自定义声明）。`resetClaims = false` 是为了保住 token 里的
>   `guard` 声明——还要配合第 4 章的 `persistent_claims` 配置，否则续签出的 token
>   否则续签 token 会丢失 guard，无法通过 `JWTGuardAuth`；
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

> 第 4 章会为登录加限流，并把 `refresh` 移出自动续签组，避免过期 token 被续签两次。

## 3.6 真机验证

保持服务运行，逐条验证：

```bash
BASE=http://127.0.0.1:8088

# ① 无 token → 401（这是预期结果，不是获取 token 的步骤）
# 同时显示响应正文和 HTTP 状态，出错时不要只留下一个数字。
curl -sS $BASE/api/admin/me/info -w '\nHTTP %{http_code}\n'

# ② 登录拿 token
# 先完整显示响应和状态，再提取 data.token；失败时 TOKEN 不会悄悄变成空字符串。
login_for_token() {
  if ! LOGIN_RESULT=$(curl -sS -w '\n%{http_code}' -X POST "$BASE/api/admin/authenticate" \
    -H 'Content-Type: application/json' \
    -d '{"email":"admin@example.com","password":"password"}'); then
    echo '登录请求发送失败：请确认服务仍在运行、端口是 8088。' >&2
    return 1
  fi

  LOGIN_STATUS=${LOGIN_RESULT##*$'\n'}
  LOGIN_BODY=${LOGIN_RESULT%$'\n'*}
  printf '%s\nHTTP %s\n' "$LOGIN_BODY" "$LOGIN_STATUS"

  if [ "$LOGIN_STATUS" != 200 ]; then
    echo '登录失败：请根据上面的 message / errors 排查，先不要继续下一步。' >&2
    return 1
  fi

  TOKEN=$(printf '%s' "$LOGIN_BODY" | php -r '
    $json = json_decode(stream_get_contents(STDIN), true);
    echo is_string($json["data"]["token"] ?? null) ? $json["data"]["token"] : "";
  ')

  if [ -z "$TOKEN" ]; then
    echo '登录虽返回 200，但响应中没有 data.token；请检查 AuthController 的返回结构。' >&2
    return 1
  fi

  printf '已拿到 token（长度 %s）。\n' "${#TOKEN}"
}
login_for_token

# ③ 带 token → 200
curl -sS "$BASE/api/admin/me/info" \
  -H "Authorization: Bearer $TOKEN" \
  -w '\nHTTP %{http_code}\n'
# {"data":{"user":{"id":1,"name":"管理员","email":"admin@example.com"}}}
# HTTP 200

# ④ 刷新 → 旧 token 立即作废、新 token 可用
refresh_for_token() {
  if ! REFRESH_RESULT=$(curl -sS -w '\n%{http_code}' -X POST "$BASE/api/admin/refresh" \
    -H "Authorization: Bearer $TOKEN"); then
    echo '刷新请求发送失败：请确认服务仍在运行。' >&2
    return 1
  fi

  REFRESH_STATUS=${REFRESH_RESULT##*$'\n'}
  REFRESH_BODY=${REFRESH_RESULT%$'\n'*}
  printf '%s\nHTTP %s\n' "$REFRESH_BODY" "$REFRESH_STATUS"

  if [ "$REFRESH_STATUS" != 200 ]; then
    echo '刷新失败：请根据上面的 message 排查，先不要继续下一步。' >&2
    return 1
  fi

  NEW_TOKEN=$(printf '%s' "$REFRESH_BODY" | php -r '
    $json = json_decode(stream_get_contents(STDIN), true);
    echo is_string($json["data"]["token"] ?? null) ? $json["data"]["token"] : "";
  ')

  if [ -z "$NEW_TOKEN" ]; then
    echo '刷新虽返回 200，但响应中没有 data.token。' >&2
    return 1
  fi

  printf '已拿到新 token（长度 %s）。\n' "${#NEW_TOKEN}"
}
refresh_for_token

curl -sS "$BASE/api/admin/me/info" -H "Authorization: Bearer $TOKEN" -w '\nHTTP %{http_code}\n'       # HTTP 401（旧 token 已被 refresh 拉黑）
curl -sS "$BASE/api/admin/me/info" -H "Authorization: Bearer $NEW_TOKEN" -w '\nHTTP %{http_code}\n'   # HTTP 200

# ⑤ 登出 → 该 token 立即 401
curl -sS -X POST "$BASE/api/admin/logout" -H "Authorization: Bearer $NEW_TOKEN" -w '\nHTTP %{http_code}\n' # {"message":"ok"} + HTTP 200
curl -sS "$BASE/api/admin/me/info" -H "Authorization: Bearer $NEW_TOKEN" -w '\nHTTP %{http_code}\n' # HTTP 401
```

如果第 ② 步失败，直接看它打印出的 HTTP 状态和响应正文：

| 现象 | 优先检查 |
|---|---|
| `HTTP 500`，`Target class [AuthController] does not exist` | `routes/admin.php` 顶部是否有 `use App\Admin\Controllers\AuthController;` |
| `HTTP 422`，提示字段必填 | JSON 是否使用本章的 `email` / `password` 字段 |
| `HTTP 422`，提示帐号或密码错误 | 是否执行过 `php artisan db:seed --class=UserSeeder` |
| 无法连接 `127.0.0.1:8088` | 启动命令是否仍在运行、实际端口是否为 `8088` |

> 本章黑名单宽限期为 0，因此 refresh 后旧 token 立即失效。`logout` 是幂等接口，
> 判断退出是否生效要再次访问受保护接口，不能只看 logout 返回的 200。

> 第 4 章会继续验证过期 token 自动续签和跨守卫拦截。

---

## 本章产出

- jwt-auth 直接依赖装好（不靠任何其它包传递）；
- 自建最简 User 实现 `JWTSubject`，第一个用户 `admin@example.com / password`；
- admin / user / moo-system 三个中间件组就位（后两个为后面章节预埋）；
- 登录 / me / 刷新 / 登出全链路真机通过（401 → 200 → 401）。

下一章：把这套"能跑"的 JWT 加固到"能上生产"。
