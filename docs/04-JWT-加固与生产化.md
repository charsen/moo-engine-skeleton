# 第 4 章　JWT 加固与生产化

目标：第 3 章的 JWT 是"能跑"的最小版。这一章把它加固到"能上生产"：
改好 2 个配置文件、给登录和刷新补上安全细节、加上限流、
准备好生产用的 composer 文件，最后写第一批接口测试守住这一切。

> 本章的每一处改动，都对应作者真实项目在生产里踩过的坑。
> 跟着做就好，每一步只需要知道"改什么 + 为什么"各一句话。

---

## 4.1 加固 `config/jwt.php`（5 处）

打开 `engine/config/jwt.php`，逐处核对（**完整文件见仓库**，注释已全部中文化，
与认证主体无关、抄了就能用）：

**① 续签时保留 guard 声明** —— 不配它，换发的新 token 会丢掉 `guard`，
下次请求过 `JWTGuardAuth` 直接 401（坑 #10，生产偶发 401 的真因）：

```php
'persistent_claims' => [
    'guard',
],
```

**② 黑名单宽限期 90 秒** —— 页面并发请求时，第一个触发续签后旧 token 立刻进黑名单，
没有宽限期同批在途请求会全部 401（坑 #11）：

```php
'blacklist_grace_period' => 90,
```

**③ 滑动续期** —— 包默认续期窗口永远从首次登录起算，天天在用也会在第 14 天被踢：

```php
'refresh_iat' => true,   // 每次续签把起算点拨到当下
```

**④ 有效期默认值固化进 config** —— `.env` 里没有 `JWT_TTL` 时，包默认只有 60 分钟。
把团队约定写死进 config，env 只留真正逐环境变化的 `JWT_SECRET`：

```php
'ttl'         => (int) env('JWT_TTL', 2880),           // 2 天
'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 20160),  // 14 天
```

**⑤ 黑名单异常开关显式写上** —— 名字像日志开关，实际控制"已拉黑的 token 是否被拒"。
包的代码级默认是关，全靠包内配置回填才是开，这里显式写死不赌运气：

```php
'show_black_list_exception' => true,
```

## 4.2 新建 `config/cors.php`

无感续签的新 token 放在 `authorization` **响应头**里。CORS 默认不暴露任何响应头，
跨域场景（H5 / 前后端分离调试）下浏览器拿不到新 token，旧 token 出宽限期就 401（坑 #12）。

新建 `engine/config/cors.php`（Laravel 12 的 HandleCors 默认就在全局中间件里，发布配置即生效）：

```php
'paths' => ['api/*', 'app/*'],
'exposed_headers' => ['Authorization'],   // 关键行
```

其余键照抄 Laravel 默认即可（完整文件见仓库）。

## 4.3 登录补账号状态检查

第 3 章的登录只校验了密码——被停用的账号照样能登录。自建 users 表最现成的状态位
是 `email_verified_at`，在 `Hash::check` 之后补一段：

```php
// 最简状态检查：未验证邮箱不允许登录（自建表的"激活"语义）
if ($user->email_verified_at === null) {
    throw ValidationException::withMessages(['email' => ['帐号尚未激活（邮箱未验证）。']]);
}
```

> 📦 第 7 章换成 Personnel 后，对应的是 `account_status` 枚举检查——那里有个
> 大坑（裸 int 和枚举实例比较恒为 false，坑 #19），到时再讲。

## 4.4 `/refresh` 路由单独挂中间件

第 3 章把 `refresh` 放进了 `jwt.auth.refresh` 那个组——**要移出来**（坑 #18）：
那个中间件会对过期 token 先自动续签一次（新 token 放响应头），控制器再续签第二次
（放响应体），一个旧 token 就派生出两个有效新 token，响应头那个永远不会作废。

改 `routes/admin.php`：

```php
// 主动刷新：只校验 guard claim，不挂 jwt.auth.refresh
Route::post('refresh', [AuthController::class, 'refresh'])
    ->middleware('jwt.guard.auth:admin')->name('refresh');

// 需要登录（JWT 强制认证 + 近过期续签）
Route::group(['middleware' => ['jwt.guard.auth:admin', 'jwt.auth.refresh']], function () {
    Route::get('me/info', [AuthController::class, 'me'])->name('me.info');
});
```

控制器里 `refresh()` 对应改成"自己处理异常"：

```php
// use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
// use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
public function refresh(): JsonResponse
{
    try {
        // forceForever=false：旧 token 走 90 秒宽限；resetClaims=false：保留 guard
        $token = Auth::guard('admin')->refresh(false, false);
    } catch (JWTException $e) {
        // 无 token / 伪造 / 超出续期窗口 / 已拉黑 → 重新登录
        throw new UnauthorizedHttpException('jwt-auth', $e->getMessage());
    }

    return response()->json(['data' => [
        'token' => $token,
        'expires_in' => Auth::guard('admin')->factory()->getTTL() * 60,
    ]]);
}
```

> refresh 本身就接受"过期但在续期窗口内"的 token，不需要前置强制认证。
> 📦 第 7 章接入 moo-system 后，这里还会补一行 `UpdateLoginTokenJob`（同步包里的登录记录）。

## 4.5 异常采集与节流（`bootstrap/app.php`）

在 `withExceptions()` 里补三段（完整文件见仓库 `engine/bootstrap/app.php`）：

```php
// use Illuminate\Cache\RateLimiting\Limit;
// use Mooeen\Scaffold\Support\ExceptionDispatcher;

// 第 3 章写过的渲染之外，再加：同一异常只报一次 + 预期异常不上报
$exceptions->dontReportDuplicates()->dontReport([
    JWTException::class,
    NotFoundHttpException::class,
    BaseException::class,          // moo-scaffold 的业务异常（522）
]);

// 一行接入 moo-scaffold 的运行时异常采集（落盘 storage/scaffold/runtimes，/scaffold 可看）
$exceptions->reportable(function (Throwable $e): void {
    app(ExceptionDispatcher::class)->dispatch($e);
});

// 高频 5xx 时别把关键日志吞了
$exceptions->throttle(fn (Throwable $e) => Limit::perMinute(1000));
```

## 4.6 接口限流

`AppServiceProvider::boot()` 里先定义，再挂进中间件组：

```php
// use Illuminate\Cache\RateLimiting\Limit;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\RateLimiter;
RateLimiter::for('admin', fn (Request $r) => Limit::perMinute(300)->by($r->user()?->id ?: $r->ip()));
RateLimiter::for('client', fn (Request $r) => Limit::perMinute(1000)->by($r->user()?->id ?: $r->ip()));
```

`admin` / `moo-system` 组加一行 `'throttle:admin'`，`client` 组加 `'throttle:client'`。

**登录接口要再单独限**：组限流的 300 次/分钟对 `/authenticate` 等于不设防
（爆破一分钟能试 300 个密码）。按「账号 + IP」5 次/分钟单独限——锁单账号尝试、
也锁同 IP 换号扫射——两条登录路由挂 `->middleware('throttle:login')`：

```php
RateLimiter::for('login', function (Request $r) {
    $account = (string) ($r->input('account') ?: $r->input('email') ?: '');

    return Limit::perMinute(5)->by(sha1($account.'|'.$r->ip()));
});
```

验证：同一账号连错 5 次密码，第 6 次返回 **429**（RegressionTest 有守护用例）。

组限流的验证：随便调一个 admin 接口，响应头出现 `X-RateLimit-Limit: 300` 即生效。
在 scaffold 调试器的 Response Headers 标签里能同时看到限流头和 4.2 节的 CORS 暴露头：

![调试器响应头：限流 + CORS 暴露](./images/05-debugger-response-headers.png)

## 4.7 `.env.example` 与生产 composer

- 把 `.env.example` 改成"复制即可用"：预填 MariaDB `moo_skeleton`、8088 端口、
  分组中文注释，补 `JWT_SECRET` / `SCAFFOLD_AUTHOR` 占位，
  删掉骨架用不到的 MAIL/AWS 等死键（完整文件见仓库）；
- 新建 `composer.production.json`：moo-* 包换成版本约束 + VCS 仓库。
  部署时 `cp composer.production.json composer.json && composer install --no-dev`，
  和第 2 章讲的"开发 path / 生产 vcs"双轨制闭环。
  （📦 仓库版里有 moo-system 的行——没装第 7 章的包就先删掉那两处。）

## 4.8 第一批接口测试

先给 `engine/phpunit.xml` 加一行测试专用密钥（测试跑在 sqlite 内存库上，不读你的 `.env`）：

```xml
<env name="JWT_SECRET" value="testing-secret-do-not-use-in-production"/>
```

新建 `tests/Feature/AuthTest.php`。本章是 **User 版**（📦 第 7 章接入 moo-system 后
整套换成仓库的最终版，用例一一对应、只是登录字段不同），完整代码：

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;   // 跑 DatabaseSeeder（UserSeeder 在里面）

    private function login(): string
    {
        return $this->postJson('api/admin/authenticate', [
            'email' => 'admin@example.com', 'password' => 'password',
        ])->assertOk()->json('data.token');
    }

    public function test_login_ok(): void
    {
        $this->assertNotEmpty($this->login());
    }

    public function test_wrong_password_422(): void
    {
        $this->postJson('api/admin/authenticate', [
            'email' => 'admin@example.com', 'password' => 'nope',
        ])->assertStatus(422);
    }

    public function test_unverified_email_cannot_login(): void
    {
        User::where('email', 'admin@example.com')->update(['email_verified_at' => null]);
        $this->postJson('api/admin/authenticate', [
            'email' => 'admin@example.com', 'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_me_without_token_401(): void
    {
        $this->getJson('api/admin/me/info')->assertStatus(401);
    }

    public function test_me_with_token_200(): void
    {
        $token = $this->login();
        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJsonPath('data.user.email', 'admin@example.com');
    }

    public function test_refresh_then_new_token_works(): void
    {
        $token = $this->login();
        $new = $this->postJson('api/admin/refresh', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()->json('data.token');
        $this->assertNotSame($token, $new);
        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$new}"])->assertOk();
    }

    public function test_logout_blacklists_token(): void
    {
        $token = $this->login();
        $this->postJson('api/admin/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }
}
```

```bash
php artisan test
# Tests: 9 passed   ← AuthTest 7 个 + Laravel 自带 Example 2 个
```

> ⚠️ 坑 #14：Feature 测试里两次请求跑在**同一个进程**，jwt 的服务链是单例
> （payload 工厂的 claim 残留、auth 适配器绑死首个守卫），跨守卫/跨请求断言会
> 测出假结果。仓库 `tests/TestCase.php` 的 `freshJwtProcess()` 演示了怎么彻底
> 重置模拟真实跨进程——写更复杂的用例（续签保 claim、双守卫隔离）时要用它。

## 4.9 真机验证清单

| # | 验证 | 期望 |
|---|---|---|
| 1 | 无 token 访问 `me/info` | 401 |
| 2 | 登录 → 带 token 访问 | 200 |
| 3 | `POST /refresh` → 解码新 token | `"guard":"admin"` 在，`exp-iat=172800s` |
| 4 | 用新 token 访问 `me/info` | 200 |
| 5 | 手工构造**已过期** token 访问受保护接口 | 200 + 响应头 `authorization: <新token>`（无感续签） |
| 6 | 过期 token 调 `/refresh` | 200，且响应头**没有** authorization（不产生孤儿 token） |
| 7 | 已拉黑的旧 token 90 秒内再用 | 200（宽限期生效） |
| 8 | 登出后再用 | 401（forceForever 不吃宽限） |
| 9 | 带 `Origin` 的跨域请求 | 响应头 `Access-Control-Expose-Headers: Authorization` |
| 10 | 任意 admin 接口 | 响应头 `X-RateLimit-Limit: 300` |

过期 token 没法用 `auth()->login()` 直接造（签完自检就抛异常），手工签一个
`exp` 在过去、`iat` 在续期窗口内的即可——完整可运行实现见仓库
`tests/TestCase.php` 的 `makeExpiredToken()`。

> 第 5~8 项新手手工做不动也没关系——这几条已由仓库的 AuthTest / JwtAutoRefreshTest /
> RegressionTest 自动守护（`php artisan test`），手工复现属进阶选做。

> 📦 仓库版的 admin 分支主体是 Personnel（第 7 章最终态）。本章时间点后台主体
> 还是 User——照抄时把主体查询和 `prv`（`sha1(User::class)`）都换成 User，
> 否则签出的 token 过不了 `lock_subject` 的模型哈希校验。

## 已知局限（记录在案，不修）

对"已过期但仍在续期窗口内"的 token 调 `logout` 会返回 200 但**没有真正拉黑**
（jwt-auth 的 `logout()` 静默吞掉过期解码异常）。被盗的过期 token 在窗口内无法主动吊销，
只能等窗口关闭或换 `JWT_SECRET` 全员下线。这是 jwt-auth 的设计局限；
敏感场景靠缩短 `refresh_ttl` 缓解。

> 进阶小知识（可跳过）：`persistent_claims` 在 jwt-auth **2.8.x** 上不配必丢 guard；
> **2.9.x** 因内部实现"碰巧"保留。不管哪个版本都该配——契约写在文档里，不赌内部实现。

---

## 本章产出

- `config/jwt.php` 5 处加固 + `config/cors.php` 暴露续签响应头；
- 登录有状态检查、`/refresh` 不再产生孤儿 token；
- 限流（admin 300/分钟）；`.env.example` 复制即可用，生产 composer 双轨闭环；
- 9 个接口测试全绿，10 项真机验证通过。

下一章：把第 2 章故意公开的 `food` 接口锁进 JWT，并启用**动作级 ACL 授权**。
