# 第 6 章　移动端分片与 user 守卫

目标：启用一直空着的 `app/Api/` 分片（移动端，路由前缀 `app`），用 **user 守卫**登录，
做到两件事：① 后台 token 和移动端 token **互不通用**（双向隔离）；
② 移动端刷新是**无宽限严格轮换**（被刷新的旧 token 立即作废，没有 90 秒宽限——
宽限是什么，6.2 会解释）。

> 移动端的主体就是第 3 章自建的 User——它**永久**属于移动端，
> 第 7 章接入 moo-system 后只有后台换 Personnel，这边一行不动。

---

## 6.1 地基早就铺好了

第 3 章接线时这些已经就位，本章只是用起来：

- `config/auth.php`：`user` 守卫（jwt + users provider）；
- `client` 中间件组：`jwt.assign.guard:user` + 限流，挂在 `app` 前缀上；
- `jwt.guard.auth:user`：校验 token 里的 guard 声明必须是 `user`；
- User 的 `getJWTCustomClaims()` 动态返回当前守卫——经 client 组签发的 token
  天然带 `guard=user`，**不需要任何额外处理**。

## 6.2 写移动端登录控制器

> 📦 **先说清「本章时间点，仓库哪些文件可参考」**（仓库代码都在 `engine/` 子目录下，
> 仓库根目录只有 docs、README 等）：
> `engine/app/Api/Controllers/AuthController.php` 和 `engine/tests/Feature/ApiAuthTest.php`
> 是最终版，直接用；`engine/routes/api.php`（混入了后续章节的路由，见 6.3 的 📦 注）、
> `engine/tests/TestCase.php` 和 `engine/app/Admin/Controllers/AuthController.php`
> （这两个都是第 7 章 Personnel 最终版）**不能照搬**，差异在用到处各有 📦 注。

新建 `app/Api/Controllers/AuthController.php`。不要只根据下面三条差异自己拼，先使用这份
与本章时间点完全匹配的**完整文件**：

```php
<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthController
{
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

        if ($user->email_verified_at === null) {
            throw ValidationException::withMessages(['email' => ['帐号尚未激活（邮箱未验证）。']]);
        }

        $token = Auth::guard('user')->login($user);

        return response()->json(['data' => [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'token' => $token,
            'expires_in' => Auth::guard('user')->factory()->getTTL() * 60,
        ]]);
    }

    public function me(): JsonResponse
    {
        $user = Auth::guard('user')->user();

        return response()->json(['data' => ['user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]]]);
    }

    public function refresh(): JsonResponse
    {
        try {
            $token = Auth::guard('user')->refresh(true, false);
        } catch (JWTException $e) {
            throw new UnauthorizedHttpException('jwt-auth', $e->getMessage());
        }

        return response()->json(['data' => [
            'token' => $token,
            'expires_in' => Auth::guard('user')->factory()->getTTL() * 60,
        ]]);
    }

    public function logout(): JsonResponse
    {
        Auth::guard('user')->logout(true);

        return response()->json(['message' => 'ok']);
    }
}
```

先做语法与类加载检查：

```bash
php -l app/Api/Controllers/AuthController.php
php artisan tinker --execute="var_dump(class_exists(App\\Api\\Controllers\\AuthController::class));"
# bool(true)
```

骨架仍是熟悉的「查用户 → `Hash::check` → 签发」。现在再理解它与后台版的**三个差异**：

**① 主体是自建 User，用 email 登录** —— 不依赖 moo-system；guard claim 由
`User::getJWTCustomClaims()` 动态注入（client 组已 `shouldUse('user')`），无需任何内联覆盖。

**② 刷新用无宽限严格轮换：**

```php
$token = Auth::guard('user')->refresh(true, false);
```

两个参数**都不能随手改**：

- 第一个 `forceForever = true`：这次拿来刷新的旧 token 直接**永久**进黑名单。
  > 「90 秒宽限」指 `config/jwt.php` 的 `blacklist_grace_period = 90`（第 4 章配的）：
  > 续签后旧 token 还能再用 90 秒，护住页面并发的在途请求。
  > 一句话记忆：**后台怕并发打架要宽限（传 `false`）；移动端刷新采用严格轮换，不留宽限（传 `true`）。**
  > 这只能保证“一个旧 token 不能重复刷新/继续使用”，**不是完整的单设备登录**：
  > 同一用户在另一台设备重新登录得到的其它 token 不会被自动吊销。真要实现跨设备互踢，
  > 还需要服务端会话表或 `token_version` / 设备会话 ID，并在每次认证时校验。
- 第二个 `resetClaims = false`：续签时**保留** token 里的自定义声明。它配合第 4 章的
  `persistent_claims = ['guard']` 契约（见 `config/jwt.php`）保住 `guard` 声明——
  改成 `true` 的话，续签出的新 token 会丢 `guard`，下一个请求就过不了 `JWTGuardAuth`。

**③ 登录状态位仍使用 User 的邮箱验证时间**：`email_verified_at` 为空直接 422 拒登。
第 4 章已经给当前后台 User 登录补了同样检查；第 7 章后台换成 Personnel 后，
后台状态位改为 `account_status` 枚举，而移动端继续使用 `email_verified_at`。

登出与后台**完全相同**（不算差异）：`Auth::guard('user')->logout(true);` 永久拉黑当前 token。
refresh 的 try/catch 写法也与第 4 章的后台版相同。

## 6.3 路由（`routes/api.php`）

把 `routes/api.php` 完整整理成下面这样。这里把 `declare`、控制器 import 和第 3 章已有的
hello 路由一起列出，避免只复制中间片段后出现 `Class "AuthController" not found`：

```php
<?php

declare(strict_types=1);

use App\Api\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => 'Hello app api ~');

// 公开：登录 / 退出。登录单独限流（账号 + IP，5 次/分钟防爆破）——
// throttle:login 限流器第 4 章已在 AppServiceProvider 里定义好，这里只管挂
Route::post('authenticate', [AuthController::class, 'authenticate'])
    ->middleware('throttle:login')->name('authenticate');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

// 主动刷新：单独挂 guard 校验，不进 jwt.auth.refresh —— 否则过期 token 会被中间件
// 和控制器各续签一次，凭一个旧 token 派生出两个有效新 token（孤儿 token，详见第 4.4 节）
Route::post('refresh', [AuthController::class, 'refresh'])
    ->middleware('jwt.guard.auth:user')->name('refresh');

Route::group(['middleware' => ['jwt.guard.auth:user', 'jwt.auth.refresh']], function () {
    Route::get('me/info', [AuthController::class, 'me'])->name('me.info');

    // :insert_code_here:do_not_delete
});
```

先让 Laravel 展开真实中间件链：

```bash
php artisan route:list --path=app -v
# authenticate 有 client + throttle:login
# refresh 有 client + JWTGuardAuth:user
# me/info 有 client + JWTGuardAuth:user + JWTAuthOrRefresh
```

> 最后那行魔法注释是 **moo-scaffold 生成器的插入锚点**（仓库 `engine/routes/api.php`
> 顶部注释写明「供 moo-scaffold 生成器插入路由，勿删」）：第 9 章增量开发时，
> 生成器会把新模块的 `iResource` 路由自动写到这个位置——默认就落在保护圈里。
> 删了它不影响运行，但生成器会找不到插入点。

> 📦 仓库最终态的保护圈里还多了第 9 章生成的
> `Route::iResource('food', FoodController::class)`；本章不要提前补。

写好后在 `/scaffold/routes` 切到「客户端接口」应用，能看到移动端路由：

![客户端接口路由](./images/07-scaffold-routes-app.png)

> 截图文件名的 `07` 是全教程截图的流水号（第 5 章用的是 `06-scaffold-routes-acl.png`），
> 不是章节号。另外截图摄于教程完结时，里面带 food 路由——你此刻的页面**没有**它们，
> 只会看到登录/退出/刷新/me 四件套，对不上不是你做错了。`/app`
> 的 hello 是闭包路由，Laravel 的 `route:list` 中存在、浏览器也能访问，但 Scaffold
> 这个按控制器整理的路由页不会展示它。可以再执行
> `curl http://127.0.0.1:8088/app`，应输出 `Hello app api ~`。

> 此时切到 Scaffold 的「接口调试」页，「客户端接口」左侧会是空的：调试器只读
> `scaffold/api/api/` 里已生成的接口元数据，本章手写的 AuthController 还没有这份元数据。
> 这不影响路由和业务代码，下一节用 `curl` 真实调用；第 9 章由生成器产出的 Food
> 客户端接口会正常出现在调试器中。

## 6.4 真机验证

前置两件事：① 开发服务器跑在 **8088 端口**（第 1 章的约定端口，否则下面的 curl 全是
connection refused）；② `UserSeeder` 已执行——本章控制器多了激活检查，
`admin@example.com` 的 `email_verified_at` 必须非空，否则登录直接 422「帐号尚未激活」
（seeder 里填的是 `now()`，没动过就没事）。

```bash
BASE=http://127.0.0.1:8088

# ① 移动端登录（注意前缀是 app，不是 api/admin）
APP_TOKEN=$(curl -s -X POST $BASE/app/authenticate \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}' \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')

# 同时拿一枚后台 token，下面才能完整验证「各回各家 + 双向隔离」
ADMIN_TOKEN=$(curl -s -X POST $BASE/api/admin/authenticate \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}' \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')

# ② 解码 payload 看 guard。JWT 用的是 base64url（- _ 替代 + /，且不带 padding），
#    先转回标准 base64，再按缺几位补几个 =（恰好不缺时一个不补）：
P=$(echo $APP_TOKEN | cut -d. -f2 | tr '_-' '/+')
P="$P$(printf '%*s' $(( (4 - ${#P} % 4) % 4 )) '' | tr ' ' '=')"
echo $P | base64 -d
# {"iss":...,"guard":"user",...}

# ③ 各回各家 200
curl -s -o /dev/null -w "%{http_code}\n" $BASE/app/me/info -H "Authorization: Bearer $APP_TOKEN"          # 200
curl -s -o /dev/null -w "%{http_code}\n" $BASE/api/admin/me/info -H "Authorization: Bearer $ADMIN_TOKEN" # 200

# ④ 双向隔离 401
curl -s -o /dev/null -w "%{http_code}\n" $BASE/app/me/info -H "Authorization: Bearer $ADMIN_TOKEN"        # 401
curl -s -o /dev/null -w "%{http_code}\n" $BASE/api/admin/me/info -H "Authorization: Bearer $APP_TOKEN"    # 401

# ⑤ 无宽限严格轮换：拿新 token 后，被刷新的旧 token 立刻 401
NEW=$(curl -s -X POST $BASE/app/refresh -H "Authorization: Bearer $APP_TOKEN" \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
curl -s -o /dev/null -w "%{http_code}\n" $BASE/app/me/info -H "Authorization: Bearer $NEW"          # 200
curl -s -o /dev/null -w "%{http_code}\n" $BASE/app/me/info -H "Authorization: Bearer $APP_TOKEN"    # 401
```

> 📦 ④ 的 ADMIN_TOKEN：按教程顺序写到这里的读者，后台还是第 3 章的 email 版，
> 用 `{"email":"admin@example.com","password":"password"}` 登录即可。但**直接跑仓库代码**
> 的人注意：仓库 `engine/app/Admin/Controllers/AuthController.php` 已是第 7 章 Personnel
> 最终版，登录字段是 `account`，拿 email 去打会 422——改发
> `{"account":"13800000000","password":"admin888"}`（第 7 章的种子管理员）。

最后把上面的手工验证固化成测试。不要直接抄仓库最终态的 `tests/TestCase.php`：
那个文件已经进入第 7 章，会引用你此刻还没安装的 `Personnel`。本章时点请把
`tests/TestCase.php` 完整整理成下面这份可直接运行的 User 版：

```php
<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function adminLogin(): string
    {
        return $this->postJson('api/admin/authenticate', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertOk()->json('data.token');
    }

    protected function appLogin(): string
    {
        return $this->postJson('app/authenticate', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertOk()->json('data.token');
    }

    protected function freshJwtProcess(): void
    {
        foreach ([
            'tymon.jwt', 'tymon.jwt.auth', 'tymon.jwt.manager',
            'tymon.jwt.provider.auth', 'tymon.jwt.payload.factory', 'tymon.jwt.blacklist',
            'auth.driver',
        ] as $id) {
            $this->app->forgetInstance($id);
        }
    }

    protected function makeExpiredToken(string $guard = 'admin'): string
    {
        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $b64 = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $header = $b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $now = time();
        $payload = $b64(json_encode([
            'iss' => 'http://localhost/'.$guard.'/authenticate',
            'iat' => $now - 7200,
            'exp' => $now - 3600,
            'nbf' => $now - 7200,
            'jti' => bin2hex(random_bytes(8)),
            'sub' => (string) $user->id,
            'prv' => sha1(User::class),
            'guard' => $guard,
        ]));
        $signature = $b64(hash_hmac('sha256', "{$header}.{$payload}", (string) config('jwt.secret'), true));

        return "{$header}.{$payload}.{$signature}";
    }
}
```

新建 `tests/Feature/ApiAuthTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_app_me_without_token_returns_401(): void
    {
        $this->getJson('app/me/info')->assertUnauthorized();
    }

    public function test_app_login_and_me(): void
    {
        $token = $this->appLogin();

        $this->getJson('app/me/info', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'admin@example.com');
    }

    public function test_guard_isolation_between_admin_and_user_tokens(): void
    {
        $adminToken = $this->adminLogin();
        $this->freshJwtProcess();
        $appToken = $this->appLogin();
        $this->freshJwtProcess();

        $this->getJson('app/me/info', ['Authorization' => "Bearer {$adminToken}"])
            ->assertUnauthorized();
        $this->freshJwtProcess();

        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$appToken}"])
            ->assertUnauthorized();
        $this->freshJwtProcess();

        $this->getJson('app/me/info', ['Authorization' => "Bearer {$appToken}"])->assertOk();
        $this->freshJwtProcess();
        $this->getJson('api/admin/me/info', ['Authorization' => "Bearer {$adminToken}"])->assertOk();
    }

    public function test_app_refresh_strictly_rotates_token(): void
    {
        $token = $this->appLogin();
        $this->freshJwtProcess();

        $newToken = $this->postJson('app/refresh', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->json('data.token');
        $this->assertNotSame($token, $newToken);
        $this->freshJwtProcess();

        $this->getJson('app/me/info', ['Authorization' => "Bearer {$newToken}"])->assertOk();
        $this->freshJwtProcess();
        $this->getJson('app/me/info', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_expired_app_token_refresh_yields_one_new_token(): void
    {
        $expired = $this->makeExpiredToken('user');

        $response = $this->postJson('app/refresh', [], ['Authorization' => "Bearer {$expired}"])
            ->assertOk()
            ->assertHeaderMissing('authorization');

        $newToken = $response->json('data.token');
        $this->freshJwtProcess();
        $this->getJson('app/me/info', ['Authorization' => "Bearer {$newToken}"])->assertOk();
    }
}
```

现在运行本章的 5 个用例，然后再跑一次全量回归：

```bash
php artisan test --filter=ApiAuthTest
# Tests: 5 passed (18 assertions)

php artisan test
# 本轮从第 1 章顺序做到这里的实测：20 passed (46 assertions)
```

> 数量是本轮实操记录：如果以后生成器又多产出测试，以「全部绿色」为验收标准，
> 不要为了凑固定数字删用例。`freshJwtProcess()` 不是业务逻辑，它只是清掉测试进程里
> JWT 单例的跨请求残留，让 Feature 测试更接近真实 PHP 请求的独立进程。

## 6.5 User 就是你的会员表雏形

真实项目的移动端用户往往要加昵称、头像、第三方 openid、手机验证码登录……
直接在这张 users 表上加列、在这个 User 模型上加方法即可——它从第 3 章起就是
为移动端准备的。后台如果需要完整的组织架构（部门 / 岗位 / 人员 / 角色 / 授权），
那才是下一章 moo-system 的事。

---

## 本章产出

- `Api/` 分片启用：登录 / me / 刷新 / 登出四件套（user 守卫，自建 User，登录挂 `throttle:login` 限流）；
- admin ↔ user token 双向隔离，移动端刷新采用无宽限严格轮换；
- 5 个测试守护，curl 真机验证通过。

下一章（可选 / 进阶）：接入 **moo-system**，后台升级成完整的系统管理
（部门 / 岗位 / 人员 / 角色 / 授权 / 操作日志）。
