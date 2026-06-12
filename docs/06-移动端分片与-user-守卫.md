# 第 6 章　移动端分片与 user 守卫

目标：启用一直空着的 `app/Api/` 分片（移动端，路由前缀 `app`），用 **user 守卫**登录，
做到两件事：① 后台 token 和移动端 token **互不通用**（双向隔离）；
② 移动端刷新是**单设备语义**（旧 token 立即作废，没有 90 秒宽限——宽限是什么，6.2 会解释）。

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

> 📦 **先说清「本章时间点，仓库哪些文件能直接抄」**（仓库代码都在 `engine/` 子目录下，
> 仓库根目录只有 docs、README 等）：
> `engine/app/Api/Controllers/AuthController.php` 和 `engine/tests/Feature/ApiAuthTest.php`
> 是最终版，直接用；`engine/routes/api.php`（混入了后续章节的路由，见 6.3 的 📦 注）、
> `engine/tests/TestCase.php` 和 `engine/app/Admin/Controllers/AuthController.php`
> （这两个都是第 7 章 Personnel 最终版）**不能照搬**，差异在用到处各有 📦 注。

新建 `app/Api/Controllers/AuthController.php`（完整文件见仓库
`engine/app/Api/Controllers/AuthController.php`，这就是最终版）。
骨架仍是熟悉的「查用户 → `Hash::check` → 签发」，与第 3 章的后台版相比有**三个差异**
（仓库文件头注释列的就是这三条）：

**① 主体是自建 User，用 email 登录** —— 不依赖 moo-system；guard claim 由
`User::getJWTCustomClaims()` 动态注入（client 组已 `shouldUse('user')`），无需任何内联覆盖。

**② 刷新用单设备语义：**

```php
$token = Auth::guard('user')->refresh(true, false);
```

两个参数**都不能随手改**：

- 第一个 `forceForever = true`：旧 token 直接**永久**进黑名单——新设备登录顶掉旧设备。
  > 「90 秒宽限」指 `config/jwt.php` 的 `blacklist_grace_period = 90`（第 4 章配的）：
  > 续签后旧 token 还能再用 90 秒，护住页面并发的在途请求。
  > 一句话记忆：**后台怕并发打架要宽限（传 `false`）；移动端要单设备，不能宽限（传 `true`）。**
- 第二个 `resetClaims = false`：续签时**保留** token 里的自定义声明。它配合第 4 章的
  `persistent_claims = ['guard']` 契约（见 `config/jwt.php`）保住 `guard` 声明——
  改成 `true` 的话，续签出的新 token 会丢 `guard`，下一个请求就过不了 `JWTGuardAuth`。

**③ 登录前置多一步激活检查**：`email_verified_at` 为空（邮箱未验证）直接 422 拒登。
第 3 章的后台版**没有**这一步；第 7 章换 Personnel 后，后台对应的是 `account_status` 枚举。

登出与后台**完全相同**（不算差异）：`Auth::guard('user')->logout(true);` 永久拉黑当前 token。
refresh 的 try/catch 写法也与第 4 章的后台版相同。

## 6.3 路由（`routes/api.php`）

```php
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

> 最后那行魔法注释是 **moo-scaffold 生成器的插入锚点**（仓库 `engine/routes/api.php`
> 顶部注释写明「供 moo-scaffold 生成器插入路由，勿删」）：第 9 章增量开发时，
> 生成器会把新模块的 `iResource` 路由自动写到这个位置——默认就落在保护圈里。
> 删了它不影响运行，但生成器会找不到插入点。

> 📦 仓库 `engine/routes/api.php` 比上面多两样**后续章节的产物**：顶部一条
> `Route::get('/')` 的 hello 路由，和保护圈里第 9 章生成的
> `Route::iResource('food', FoodController::class)`。按本章写就行，不用补。

写好后在 `/scaffold/routes` 切到「客户端接口」应用，能看到移动端路由：

![客户端接口路由](./images/07-scaffold-routes-app.png)

> 截图文件名的 `07` 是全教程截图的流水号（第 5 章用的是 `06-scaffold-routes-acl.png`），
> 不是章节号。另外截图摄于教程完结时，里面带 food 路由——你此刻的页面**没有**它们，
> 只有本章的四件套，对不上不是你做错了。

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

# ② 解码 payload 看 guard。JWT 用的是 base64url（- _ 替代 + /，且不带 padding），
#    先转回标准 base64，再按缺几位补几个 =（恰好不缺时一个不补）：
P=$(echo $APP_TOKEN | cut -d. -f2 | tr '_-' '/+')
P="$P$(printf '%*s' $(( (4 - ${#P} % 4) % 4 )) '' | tr ' ' '=')"
echo $P | base64 -d
# {"iss":...,"guard":"user",...}

# ③ 各回各家 200
curl -s -o /dev/null -w "%{http_code}\n" $BASE/app/me/info -H "Authorization: Bearer $APP_TOKEN"   # 200

# ④ 双向隔离 401（ADMIN_TOKEN 按第 3 章 3.6 的 ① 登录后台拿）
curl -s -o /dev/null -w "%{http_code}\n" $BASE/app/me/info -H "Authorization: Bearer $ADMIN_TOKEN"        # 401
curl -s -o /dev/null -w "%{http_code}\n" $BASE/api/admin/me/info -H "Authorization: Bearer $APP_TOKEN"    # 401

# ⑤ 单设备刷新：拿新 token 后，旧 token 立刻 401（无宽限）
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

测试守护：仓库 `engine/tests/Feature/ApiAuthTest.php` 5 个用例（401 / 登录 / 双向隔离 /
单设备刷新 / 过期 token 刷新只产生一个新 token），主体就是 User，本章时间点可直接用。

> 📦 它依赖的 `engine/tests/TestCase.php` 是第 7 章最终版——本章时间点抄来后要微调三处
> （都是「后台主体此刻还是 User」的缘故）：
> ① `adminLogin()` 现在发的是 `account` 字段（`13800000000` / `admin888`），
> 改回第 3 章的 email 登录（字段 `email`，`admin@example.com` / `password`）；
> ② `makeExpiredToken()` 里 admin 分支的主体查询和 `prv` 哈希也先用 `User`；
> ③ 文件顶部 `use Mooeen\System\Models\Personnel;` 一行删掉（该类第 7 章装包后才存在，留着直接 fatal）。
> 第 7 章换最终版时一并还原。改完：

```bash
php artisan test --filter=ApiAuthTest
# Tests: 5 passed
```

## 6.5 User 就是你的会员表雏形

真实项目的移动端用户往往要加昵称、头像、第三方 openid、手机验证码登录……
直接在这张 users 表上加列、在这个 User 模型上加方法即可——它从第 3 章起就是
为移动端准备的。后台如果需要完整的组织架构（部门 / 岗位 / 人员 / 角色 / 授权），
那才是下一章 moo-system 的事。

---

## 本章产出

- `Api/` 分片启用：登录 / me / 刷新 / 登出四件套（user 守卫，自建 User，登录挂 `throttle:login` 限流）；
- admin ↔ user token 双向隔离，移动端单设备刷新语义；
- 5 个测试守护，curl 真机验证通过。

下一章（可选 / 进阶）：接入 **moo-system**，后台升级成完整的系统管理
（部门 / 岗位 / 人员 / 角色 / 授权 / 操作日志）。
