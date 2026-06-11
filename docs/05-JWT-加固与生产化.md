# 第 5 章　JWT 加固与生产化（对齐 wisdomcity）

背景：wisdomcity（本骨架的上游参考项目）在 2026 年 6 月做了一轮「JWT 401 审计」，
修掉了几个**生产环境真实踩到**的坑（偶发 401、并发请求打架、跨域丢续签 token）。
本章把这批修复回灌进骨架，并顺手补齐生产形态：env 收敛、生产 composer、限流、
操作日志、第一批接口测试。每一项都在 8088 真机服务上验证过。

---

## 5.1 续签必须保住 guard claim（`persistent_claims`）

骨架的多守卫体系里，token 带一个自定义声明 `guard`（`Personnel::getJWTCustomClaims()`
注入），`JWTGuardAuth` 中间件靠它校验「这个 token 是发给哪个守卫的」。
**坑在续签**：换发新 token 时自定义 claim 不一定会带过去，丢了 guard 的新 token
下次过校验直接 401 `Guard Unverified` —— wisdomcity 生产环境的偶发 401 真因。

修法（`config/jwt.php`）：

```php
'persistent_claims' => [
    'guard',
],
```

**一个值得记下来的版本差异**（翻 vendor 源码核实过）：

- jwt-auth **2.8.x**（wisdomcity 在用）：不配 `persistent_claims`，续签**必丢** guard；
- jwt-auth **2.9.x**（骨架在用）：`Manager::refresh()` 先 `decode` 旧 token，旧 payload
  会残留在 payload 工厂单例里，`make(false)` 不清空 —— guard「碰巧」存活。

结论：2.9.x 上不配也能跑，但那是赌库的内部实现；`persistent_claims` 才是包文档
承诺的契约，**必须配**。配套行为测试见 5.10。

## 5.2 黑名单宽限期：并发请求不打架

页面同时发好几个请求，第一个触发续签后旧 token 立刻进黑名单 ——
没有宽限期的话，同批在途的其余请求全部 401。`config/jwt.php`：

```php
'blacklist_grace_period' => 90,   // 旧 token 进黑名单后 90 秒内仍放行
```

注意 `logout` 不受宽限期影响：`Auth::guard('admin')->logout(true)` 走 `forceForever`
永久拉黑，登出后立刻 401（真机验证过，见 5.11）。

## 5.3 滑动续期（`refresh_iat`）

包默认续期窗口（14 天）永远从「首次登录」起算 —— 天天在用也会在第 14 天被踢，
是后台「不经意 401」的另一个来源。改成滑动：

```php
'refresh_iat' => true,   // 每次续签把起算点拨到当下
```

代价是被盗 token 可以一直续，靠「登出即拉黑」兜底，内部管理系统可接受。

## 5.4 默认值固化进 config，env 只留密钥

原来 `config/jwt.php` 是 `env('JWT_TTL', 60)` —— 而 `.env.example` 里根本没有
`JWT_TTL`，新手拿到的实际是 60 分钟，跟文档说的"2 天"对不上。学 wisdomcity 的做法：
**默认值写死进 config，env 只留真正逐环境变化的键**（`JWT_SECRET`）。

```php
'ttl'         => (int) env('JWT_TTL', 2880),    // 2 天
'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 20160),  // 14 天
```

顺手把整个 `config/jwt.php` 的注释换成中文逐项说明（抄 wisdomcity，教程项目更该这么做）。

## 5.5 CORS：跨域必须暴露 authorization 响应头

无感续签的新 token 放在 `authorization` **响应头**里。Laravel 默认 CORS 配置
`exposed_headers` 是空的 —— 跨域场景（H5 / webview / 前后端分离本地调试）下浏览器
读不到未暴露的响应头，新 token 直接丢失，旧 token 出宽限窗后就是 401。

新建 `config/cors.php`（Laravel 12 的 HandleCors 默认就在全局栈里，发布配置即生效）：

```php
'paths' => ['api/*', 'app/*'],
'exposed_headers' => ['Authorization'],
```

## 5.6 异常采集与上报节流（`bootstrap/app.php`）

```php
$exceptions->dontReportDuplicates()->dontReport([...]);

// 一行接入 moo-scaffold 的运行时异常采集（落盘 storage/scaffold/runtimes）
$exceptions->reportable(function (Throwable $e): void {
    app(ExceptionDispatcher::class)->dispatch($e);
});

// 高频 5xx 时别把关键日志吞了
$exceptions->throttle(fn (Throwable $e) => Limit::perMinute(1000));
```

## 5.7 接口限流

`AppServiceProvider::boot()` 里定义，再挂进中间件组：

```php
RateLimiter::for('admin', fn (Request $r) => Limit::perMinute(300)->by($r->user()?->id ?: $r->ip()));
RateLimiter::for('client', fn (Request $r) => Limit::perMinute(1000)->by($r->user()?->id ?: $r->ip()));
```

`admin` / `moo-system` 组加 `'throttle:admin'`，`client` 组加 `'throttle:client'`。
验证：响应头出现 `X-RateLimit-Limit: 300`。

## 5.8 操作日志中间件

moo-system 提供了 `system_operation_logs` 表和 `AddOperationLogJob`，但**采集点是
host 的责任**。新建 `app/Http/Middleware/OperationLog.php`（仿 wisdomcity 精简）：
terminable 中间件，响应发出后才收集（不拖慢请求），密码/token 等敏感入参
`[FILTERED]`，root 不记录。挂到 `admin` / `moo-system` 组，开关在
`config/logging.php` 的 `'operation' => env('OPERATION_LOG', false)`。

> **坑（第 13 条）**：wisdomcity 的版本用了 `LARAVEL_START` 常量算耗时 —— 那是老版本
> Laravel 入口文件定义的，**Laravel 12 已经没有了**，跑起来直接
> `Undefined constant "LARAVEL_START"`。改用 `$request->server('REQUEST_TIME_FLOAT')`。

## 5.9 `.env.example` 重写 + 生产 composer

- `.env.example` 按教程预填（MariaDB `moo_skeleton`、8088、分组中文注释），删掉骨架
  用不到的死键（MAIL/AWS/MEMCACHED），补上 `JWT_SECRET`、`SCAFFOLD_AUTHOR`、
  `OPERATION_LOG` 占位。`QUEUE_CONNECTION=sync` —— 教程期登录记录/操作日志即时可见，
  生产换 redis + worker。
- 新增 `composer.production.json`：moo 包换成 `^3.0` / `^1.2` + Gitee VCS 仓库
  （SSH 部署公钥鉴权）。部署时 `cp composer.production.json composer.json` 再
  `composer install`，与第 2 章讲的「开发 path / 生产 vcs」双轨制闭环。

## 5.10 第一批接口测试（`tests/Feature/AuthTest.php`）

6 个用例守住整条登录链路：登录成功 / 错密码 422 / 无 token 401 / 带 token 200 /
**续签后新 token 仍可用** / 登出即拉黑。`phpunit.xml` 加了测试专用 `JWT_SECRET`
（sqlite :memory:，与真实库无关）。

```bash
php artisan test
# Tests:    8 passed (21 assertions)
```

> **坑（第 14 条）**：Feature 测试里 login 和 refresh 跑在**同一个进程**，
> `tymon.jwt.payload.factory` 是单例，登录残留的 claim 会喂给后面的 refresh ——
> 测出来永远是"没问题"。真实世界两次请求是两个进程。测试里要用
> `$this->app->make('tymon.jwt.payload.factory')->emptyClaims()` 模拟新进程。

## 5.11 真机验证全记录

服务照常起：`PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload`。

| # | 验证 | 结果 |
|---|---|---|
| 1 | 无 token 访问 `me/info` | 401 ✓ |
| 2 | 登录 → 带 token 访问 | 200 ✓ |
| 3 | `POST /refresh` → 新 token（独立请求 = 真实跨进程） | 新旧 token 不同 ✓ |
| 4 | 解码新 token payload | `"guard":"admin"` 在，`exp-iat=172800s`（2 天）✓ |
| 5 | 用新 token 访问 `me/info` | 200 ✓（5.1 的修复生效） |
| 6 | 手工构造**已过期** token 访问 | 200 + 响应头 `authorization: <新token>` ✓（无感续签） |
| 7 | 新 token 的 `iat` | = 当下（滑动续期生效）✓ |
| 8 | 已拉黑的旧 token 90 秒内再用 | 200 ✓（宽限期生效） |
| 9 | 登出后再用 | 401 ✓（forceForever 不吃宽限） |
| 10 | 带 `Origin` 的跨域请求 | `Access-Control-Expose-Headers: Authorization` ✓ |
| 11 | 任意 admin 接口响应头 | `X-RateLimit-Limit: 300` ✓ |
| 12 | `system_operation_logs` | 落库，含用户归属/响应码/耗时，401 也记 ✓ |
| 13 | `php artisan moo-system check` | 6/6 ✓ |

第 6 项的过期 token 构造（教程小技巧 —— `auth()->login()` 签出来就自检过期，没法直接造，
用 JWT_SECRET 手工签一个 `exp` 在过去、`iat` 在续期窗口内的）：

```bash
SECRET=$(grep "^JWT_SECRET=" .env | cut -d= -f2)
php -r '...HS256 手工拼 header.payload.signature，claims 带 guard/prv...' "$SECRET"
curl -s -D - http://127.0.0.1:8088/api/admin/me/info -H "Authorization: Bearer <过期token>"
# HTTP/1.1 200 OK
# authorization: eyJ0eXAiOiJKV1QiLCJhbGciO...   ← 无感续签的新 token
```
