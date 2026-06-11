# 第 3 章　JWT 登录认证（自建最简用户）

目标：**不依赖任何付费包**，用 Laravel 自带的 `users` 表 + 一个最简 User 模型，
把整套 JWT 登录跑通：登录拿 token → 无 token 401 → 带 token 200 → 刷新 → 登出。

> 这一章建立的认证骨架（中间件、守卫、路由结构）是后面所有章节的地基。
> 第 7 章接入 moo-system（进阶包）时，只是把**后台守卫的主体换成 Personnel**——
> 骨架一行不用动，这正是本章要体会的设计。

---

## 3.1 安装 jwt-auth

```bash
composer require "php-open-source-saver/jwt-auth:^2.8"
php artisan vendor:publish --provider="PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider"
php artisan jwt:secret --force        # 生成 JWT_SECRET 写入 .env
```

得到 `config/jwt.php`（先用默认值，第 4 章逐项加固它）。

## 3.2 自建最简用户：User 实现 JWTSubject

Laravel 自带 `users` 表（id / name / email / password），直接用它当认证主体。
改造 `app/Models/User.php`（**完整文件见仓库，直接抄**），核心就两个接口方法：

```php
class User extends Authenticatable implements JWTSubject
{
    // token 的 sub 声明 = 主键
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    // 自定义声明：guard 跟随当前请求指派的守卫（下文的 JWTAssignGuard 已 shouldUse）
    public function getJWTCustomClaims(): array
    {
        return ['guard' => Auth::getDefaultDriver()];
    }
}
```

> 仓库版还多了 `actions` 列和 `getActions()/isRoot()` 两个方法——那是第 5 章 ACL
> 的最小授权存储，先照抄不用管。配套迁移（给 users 加 `actions` 列）也在仓库：
> `database/migrations/*_add_actions_to_users_table.php`，抄完跑 `php artisan migrate`。

建第一个用户（`database/seeders/UserSeeder.php`，抄仓库），并把 `DatabaseSeeder`
的 `run()` 改成只调它（📦 仓库版还列着第 7 章的四个 moo-system seeder，现在还没有那些类，
照抄会报错——本章先这样写，第 7 章再加回去）：

```php
public function run(): void
{
    $this->call([
        UserSeeder::class,
    ]);
}
```

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
    'admin' => ['driver' => 'jwt', 'provider' => 'users', 'hash' => false],
    'user'  => ['driver' => 'jwt', 'provider' => 'users', 'hash' => false],
],
'providers' => [
    'users' => ['driver' => 'eloquent', 'model' => App\Models\User::class],
],
```

> `hash => false`：密码由登录控制器手动 `Hash::check()`，不走守卫自动校验。
>
> 📦 **第 7 章的变化**：接入 moo-system 后，`admin` 守卫的 provider 会切到
> `personnels`（包里的 Personnel 模型）；`user` 守卫**永久**用自建 User。
> 所以仓库里的 `config/auth.php` 是切换后的最终版，本章按上面的写。

## 3.4 三个 JWT 中间件 + 中间件组

需要 3 个中间件（**完整代码在仓库 `engine/app/Http/Middleware/`，与主体模型无关，直接抄**）：

| 文件 | 职责 |
|---|---|
| `JWTAssignGuard.php` | 按路由参数指定当前请求用哪个守卫（`Auth::shouldUse`） |
| `JWTGuardAuth.php` | 校验 token 里的 guard 声明和路由要求一致；没带 token 放行 |
| `JWTAuthOrRefresh.php` | 强制认证；token 过期但可续签时自动换新 token 放进响应头 |

注册别名和中间件组，写在 `App\Providers\AppServiceProvider::boot()`：

```php
// use Illuminate\Routing\Middleware\SubstituteBindings;（路由模型绑定，Laravel 自带）
$router = $this->app['router'];
$router->aliasMiddleware('jwt.assign.guard', JWTAssignGuard::class);
$router->aliasMiddleware('jwt.guard.auth',   JWTGuardAuth::class);
$router->aliasMiddleware('jwt.auth.refresh', JWTAuthOrRefresh::class);

// admin 组：只指定守卫、不强制认证（放行登录路由、第 2 章的 food 接口）
$router->middlewareGroup('admin', ['jwt.assign.guard:admin', SubstituteBindings::class]);
// client 组：移动端
$router->middlewareGroup('client', ['jwt.assign.guard:user', SubstituteBindings::class]);
// moo-system 组：完整强制认证链（第 7 章给包路由用，现在先建好）
$router->middlewareGroup('moo-system', [
    'jwt.assign.guard:admin', 'jwt.guard.auth:admin', 'jwt.auth.refresh', SubstituteBindings::class,
]);
```

> **为什么写在 provider 的 `boot()` 而不是 `bootstrap/app.php` 的 `withMiddleware()`？**
> 后者注册的组只有「HTTP 内核」实例化时才同步到 router，artisan 命令走「Console 内核」
> 看不到——第 7 章的 `moo-system check` 自检就靠这一点才能通过（坑 #7）。
>
> 📦 第 4 章会往组里加 `throttle:*`（限流），第 7 章再加 `OperationLog`（操作日志）——
> 仓库的最终版包含全部三样。

然后改 `bootstrap/app.php`：**把第 2 章写的 `then:` 整段替换**成 `using:`——
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

并在 `withExceptions()` 里加两条 JSON 渲染（完整版见仓库 `bootstrap/app.php`，
第 4 章还会扩充）：

```php
$exceptions->shouldRenderJsonWhen(fn ($request, $e) =>
    $request->is('api/*') || $request->is('app/*') || $request->expectsJson());

$exceptions->render(fn (UnauthorizedHttpException $e) =>
    response()->json(['message' => $e->getMessage()], 401));

$exceptions->render(function (ValidationException $e) {
    $errors = $e->errors();
    return response()->json(['message' => reset($errors)[0] ?? '参数错误', 'errors' => $errors], $e->status);
});
```

## 3.5 登录控制器

写 `app/Admin/Controllers/AuthController.php`——本章是 **User（email 登录）版**，
完整代码如下（📦 第 7 章会把主体换成 Personnel，仓库里是 Personnel 最终版，
所以这一份照着敲或复制下面的）：

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

`routes/admin.php` 里挂上（`authenticate`/`logout` 公开；`me`/`refresh` 进登录 group）：

```php
// 文件顶部记得：use App\Admin\Controllers\AuthController;
Route::post('authenticate', [AuthController::class, 'authenticate'])->name('authenticate');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

Route::group(['middleware' => ['jwt.guard.auth:admin', 'jwt.auth.refresh']], function () {
    Route::get('me/info', [AuthController::class, 'me'])->name('me.info');
    Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
});
```

## 3.6 真机验证

服务起着（多 worker，见坑 #4），逐条跑：

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

# ④ 刷新 → 新 token；登出 → 旧 token 立即 401
curl -s -X POST $BASE/api/admin/refresh -H "Authorization: Bearer $TOKEN"
curl -s -X POST $BASE/api/admin/logout  -H "Authorization: Bearer $TOKEN"
curl -s -o /dev/null -w "%{http_code}\n" $BASE/api/admin/me/info -H "Authorization: Bearer $TOKEN"   # 401
```

---

## 本章产出

- jwt-auth 直接依赖装好（不靠任何其它包传递）；
- 自建最简 User 实现 `JWTSubject`，第一个用户 `admin@example.com / password`；
- admin / user / moo-system 三个中间件组就位（后两个为后面章节预埋）；
- 登录 / me / 刷新 / 登出全链路真机通过（401 → 200 → 401）。

下一章：把这套"能跑"的 JWT 加固到"能上生产"。
