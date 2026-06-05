# 第 3 章　安装 moo-system 系统管理模块（含 JWT 登录）

目标：接入 `charsen/moo-system`（部门 / 岗位 / 人员 / 角色 / 授权等系统管理模块），
并配好它依赖的 JWT 登录认证，让系统管理接口真正跑起来。

> moo-system 的接口默认全部需要登录，且它的认证主体就是「人员 Personnel」模型，
> 所以**安装 moo-system 必然要同时配好 JWT**（README 里的第 3、5 步在这里合并完成）。

---

## 3.1 接入 moo-system 包

和 moo-scaffold 一样用 path 模式（`engine/composer.json` 已声明 `system` 仓库）：

```json
"require": {
    "charsen/moo-scaffold": "dev-master as 3.999.0",
    "charsen/moo-system": "dev-master as 1.999.0"
},
"repositories": {
    "scaffold": { "type": "path", "url": "../../moo-scaffold" },
    "system":   { "type": "path", "url": "../../moo-system" }
}
```

安装（会自动带入 jwt-auth、kalnoy/nestedset、maatwebsite/excel、jenssegers/agent 等依赖）：

```bash
composer update charsen/moo-system --with-all-dependencies
```

> ⚠️ **第 1 个坑**：装完执行任何 artisan 命令会报 `Attribute [iResource] does not exist`。
> 因为 moo-system 在它的 ServiceProvider `boot()` 阶段就加载 `routes/admin.php` 调用了
> `Route::iResource`，而宏如果注册在 `AppServiceProvider::boot()` 里就太晚了。
> **把 `iResource` 宏的注册放到 `AppServiceProvider::register()`**（所有 provider 的
> `register()` 都先于任何 `boot()` 执行）即可。

## 3.2 提供 host 端契约（5 个 + 1 个全局函数）

moo-system 的控制器 `use App\Admin\Controllers\Traits\BaseActionTrait / UploaderTrait`，
模型 `use App\Models\Traits\MediaSynchronous`，还会用到 `App\Models\Notification`、
`App\Notifications\SendBlessMessage`。这些叫「host 契约」，需要 host 自己提供。

包里带了一份**空壳 stub**（`php artisan vendor:publish --tag=moo-system-stubs`），但它声明的
`BaseActionTrait` 会和第 2 章 scaffold 已生成的同名 trait **冲突**，而且空壳没有 moo-system
真正调用的方法。所以本教程**不用空壳**，而是从 `light-language-engine` 移植真实实现：

| 契约 | 做法 |
|---|---|
| `App\Admin\Controllers\Traits\BaseActionTrait` | 用 LLE 的完整版替换 scaffold 生成的精简版（多了 `getNodeAncestors` 等部门树方法）；记得 `use Closure;` |
| `App\Admin\Controllers\Traits\UploaderTrait` | 自己写精简版，只实现 moo-system 调到的 `getUploadImageControl` / `saveUploadFile`，不引入 LLE 的 UploaderController/Job/Attachment |
| `App\Models\Traits\MediaSynchronous` | 移植 LLE 版（`getMediaUrl`） |
| `App\Models\Notification` | `extends DatabaseNotification`，挂 scaffold 的 GetSerializeDate 等 + 雪花主键 |
| `App\Notifications\SendBlessMessage` | 精简版，`via()` 返回空数组（不实际推送） |

> 怎么知道 moo-system 到底调了哪些方法？在包目录里 grep 即可：
> ```bash
> grep -rhoE '\$this->(saveUploadFile|getUploadImageControl|getNodeAncestors|destroyBatchAction|restoreAction)\(' src
> ```

还差一个**全局辅助函数 `toLabelValue()`**（部门控制器在用）。新建 `app/Helpers/helpers.php`
放进去，并在 `composer.json` 里登记 `files` 自动加载：

```json
"autoload": {
    "psr-4": { "App\\": "app/", ... },
    "files": [ "app/Helpers/helpers.php" ]
}
```

```bash
composer dump-autoload
```

> ⚠️ **第 2 个坑**：不补 `toLabelValue()` 的话，调部门列表会报
> `Call to undefined function ...toLabelValue()`（HTTP 500）。

## 3.3 配置 JWT 认证

moo-system 已经带入 `php-open-source-saver/jwt-auth`，发布配置 + 生成密钥：

```bash
php artisan vendor:publish --provider="PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider"
php artisan jwt:secret --force
```

改 `config/auth.php`：默认守卫设为 `admin`（JWT），`personnels` provider 指向 moo-system 的
Personnel（`moo-system check` 会校验这个 FQN）：

```php
'defaults' => ['guard' => env('AUTH_GUARD', 'admin'), 'passwords' => 'users'],
'guards' => [
    'web'   => ['driver' => 'session', 'provider' => 'users'],
    'admin' => ['driver' => 'jwt', 'provider' => 'personnels', 'hash' => false],
    'user'  => ['driver' => 'jwt', 'provider' => 'personnels', 'hash' => false],
],
'providers' => [
    'users'      => ['driver' => 'eloquent', 'model' => App\Models\User::class],
    'personnels' => ['driver' => 'eloquent', 'model' => Mooeen\System\Models\Personnel::class],
],
```

## 3.4 JWT 中间件与路由分组

参考 wisdomcity 写 3 个中间件到 `app/Http/Middleware/`：
`JWTAssignGuard`（指定守卫）、`JWTGuardAuth`（校验 token 的 guard 声明）、
`JWTAuthOrRefresh`（强制认证 + 近过期续签）。

然后注册别名和**中间件组**。这里有个关键点：

> ⚠️ **第 3 个坑（很隐蔽）**：如果把中间件组写在 `bootstrap/app.php` 的 `withMiddleware()` 里，
> 这些组只有「HTTP 内核」实例化时才同步到 router。`php artisan moo-system check` 走的是
> 「Console 内核」，看不到这些组，于是 `admin middleware group 含 jwt.auth.refresh` 这项**永远 FAIL**。
> （wisdomcity 不报错是因为它 `route:cache` 过，缓存路由在 console 也会带出中间件组。）
>
> 解决：把别名和中间件组直接注册到 router——放在 `App\Providers\AppServiceProvider::boot()` 里，
> console / HTTP 都生效：

```php
// AppServiceProvider::boot()
$router = $this->app['router'];
$router->aliasMiddleware('jwt.assign.guard', JWTAssignGuard::class);
$router->aliasMiddleware('jwt.guard.auth',   JWTGuardAuth::class);
$router->aliasMiddleware('jwt.auth.refresh', JWTAuthOrRefresh::class);

// admin 组：只指定守卫、不强制认证（放行登录路由、第 2 章的 food 接口）
$router->middlewareGroup('admin', ['jwt.assign.guard:admin', SubstituteBindings::class]);
// client 组：移动端
$router->middlewareGroup('client', ['jwt.assign.guard:user', SubstituteBindings::class]);
// moo-system 组：完整强制认证链
$router->middlewareGroup('moo-system', [
    'jwt.assign.guard:admin', 'jwt.guard.auth:admin', 'jwt.auth.refresh', SubstituteBindings::class,
]);
```

`bootstrap/app.php` 的 `using:` 路由闭包把 `routes/admin.php` 挂到 `admin` 组、前缀 `api/admin`；
并在 `withExceptions()` 里把 `UnauthorizedHttpException → 401`、`ValidationException → {message,errors}`。

让 moo-system 的包路由走 `moo-system` 组——改 `config/moo-system.php`：

```php
'admin' => ['prefix' => 'api/admin', 'name' => 'admin.', 'middleware' => 'moo-system'],
```

## 3.5 登录控制器

写 `app/Admin/Controllers/AuthController.php`，沿用 wisdomcity 的「手动校验 + Auth::login 签发」：

```php
$user = Personnel::where('real_name', $account)->orWhere('mobile', $account)->first();
if (! $user || ! Hash::check($password, $user->password)) {
    throw ValidationException::withMessages(['account' => ['帐号或密码错误。']]);
}
$token = Auth::guard('admin')->login($user);   // guard=admin 会写进 token 自定义声明
return response()->json(['data' => ['user' => [...], 'token' => $token,
    'expires_in' => Auth::guard('admin')->factory()->getTTL() * 60]]);
```

`routes/admin.php` 里：`authenticate` / `logout` 公开；`me/info` / `refresh` 放进
`['jwt.guard.auth:admin', 'jwt.auth.refresh']` 的登录 group。

## 3.6 迁移 + 健康检查 + 建管理员

```bash
php artisan migrate              # 建 system_* 共 10 张表（包内 migration 自动加载）
php artisan moo-system check     # 6 项 host 集成自检，应全绿
```

`moo-system check` 全绿示意：

```
✓ Auth provider 配置真实 FQN
✓ admin middleware group 含 jwt.auth.refresh
✓ Composer classmap 不含已删的 App\Models\System\*
✓ Host 端 5 个必需契约 trait/class 全部存在
✓ Route::iResource macro 已注册
✓ config:cache 与 source 一致
🎉  All 6 required checks passed.
```

moo-system 不带 seeder，自己建第一个管理员人员（密码用 Hash 加密；id 是雪花算法生成）：

```bash
php artisan tinker --execute='
$p = \Mooeen\System\Models\Personnel::firstOrNew(["mobile" => "13800000000"]);
$p->real_name = "管理员";
$p->password = \Illuminate\Support\Facades\Hash::make("admin888");
$p->staff_status = 7; $p->account_status = 7; $p->save();
echo $p->id;
'
```

---

## 本章产出

- `moo-system` 接入并迁移出 10 张 `system_*` 表；
- 5 个 host 契约 + `toLabelValue()` 全局函数补齐；
- JWT 装好、配好（守卫 / 中间件 / 登录控制器）；
- `php artisan moo-system check` 6 项全绿；
- 建好第一个管理员人员。

下一章：登录拿 token，真机调试 moo-system 的接口（命令行 + scaffold 调试器）。
