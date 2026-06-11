# 第 7 章　移动端分片与 user 守卫（守卫隔离 + 单设备登录）

目标：启用一直空着的 `app/Api/` 分片（移动端，路由前缀 `app`），用 **user 守卫**发 token，
做到两件事：① 后台 token 和移动端 token **互相不通用**（守卫隔离）；
② 移动端 refresh 是**单设备语义**（旧 token 立即作废，没有 90 秒宽限）。

---

## 7.1 已有的地基

第 3 章接线时其实都铺好了，本章只是把它们用起来：

- `config/auth.php`：`user` 守卫（jwt + personnels provider）早已定义；
- `client` 中间件组（`AppServiceProvider::boot()`）：`jwt.assign.guard:user` + 限流，
  挂在 `app` 前缀上（`bootstrap/app.php`）；
- `JWTGuardAuth` 中间件天生支持参数化：`jwt.guard.auth:user` 就是校验
  token 的 guard claim 必须是 `user`。

## 7.2 移动端 AuthController 的三个关键差异

新建 `app/Api/Controllers/AuthController.php`，与后台版的差异：

**① guard claim 必须显式覆盖。** moo-system 的 `Personnel::getJWTCustomClaims()`
硬编码 `['guard' => 'admin']` —— 直接 `Auth::guard('user')->login()` 发出来的 token
还是带 `guard=admin`，过不了 `jwt.guard.auth:user`，所谓隔离就是空话。
用内联 claims 覆盖（合并顺序：subject claims 先、内联后，后者赢）：

```php
$token = Auth::guard('user')->claims(['guard' => 'user'])->login($user);
```

> wisdomcity 因历史原因移动端路由也校验 `:admin`（claim 全是 admin，无隔离）；
> 骨架把隔离做实了 —— 这是少数有意与 wisdomcity 不同的地方。
> 续签时 guard=user 能保住，靠的还是第 5 章的 `persistent_claims=['guard']`。

**② refresh 用 `(true, false)` —— 单设备登录。**

```php
$token = Auth::guard('user')->refresh(true, false);
// forceForever=true：旧 token 直接 addForever 进黑名单，没有 90 秒宽限
// 后台是 (false, false)：旧 token 有宽限 —— 多标签页并发不打架
```

一句话记忆：**后台怕并发打架要宽限；移动端要"新设备登录顶掉旧设备"，不能宽限。**

**③ 主体模型**：真实项目移动端通常是独立的会员表（Member 实现 `JWTSubject`），
骨架复用 Personnel 仅为演示，免去再造一张表。

## 7.3 路由（`routes/api.php`）

```php
Route::post('authenticate', [AuthController::class, 'authenticate'])->name('authenticate');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

// refresh 只挂 guard 校验，不挂 jwt.auth.refresh（原因见下）
Route::post('refresh', [AuthController::class, 'refresh'])
    ->middleware('jwt.guard.auth:user')->name('refresh');

Route::group(['middleware' => ['jwt.guard.auth:user', 'jwt.auth.refresh']], function () {
    Route::get('me/info', [AuthController::class, 'me'])->name('me.info');

    // :insert_code_here:do_not_delete   ← 生成器插入的移动端路由默认就在保护圈里
});
```

> **为什么 refresh 不能挂 jwt.auth.refresh**（复审时发现的坑，第 18 条）：该中间件会对
> 过期 token 先自动续签一次（新 token 放响应头），控制器再续签第二次（放响应体）——
> 一个旧 token 派生出**两个**有效新 token，响应头那个永远不会被作废（孤儿 token），
> 「单设备」承诺被打破。refresh 本身就接受过期 token（续期窗口内），单独挂
> `jwt.guard.auth` 即可，控制器里 catch JWTException 转 401。

## 7.4 真机验证

```bash
# 登录（注意前缀是 app，不是 api/admin）
APP_TOKEN=$(curl -s -X POST http://127.0.0.1:8088/app/authenticate \
  -H "Content-Type: application/json" \
  -d '{"account":"13800000000","password":"admin888"}' | ...提取 token...)

# 解码 payload                         → "guard": "user"   ✓
# user token 调 app/me/info            → 200               ✓
# admin token 调 app/me/info           → 401（隔离）        ✓
# user token 调 api/admin/me/info      → 401（反向隔离）     ✓
# app/refresh 拿新 token → 新 token 200；旧 token 立即 401（单设备，无宽限）✓
```

守护测试：`tests/Feature/ApiAuthTest.php`（4 用例：401 / 登录 / 双向隔离 / 单设备 refresh）。

## 7.5 何时该把 Personnel 换成真会员表

出现以下任一信号就该建独立的 `Member` 模型：注册开放给外部用户、移动端字段
与员工表开始分叉（昵称/头像/第三方 openid）、或要做手机验证码登录。
迁移路径：建表 → 实现 `JWTSubject`（`getJWTCustomClaims` 返回 `['guard' => 'user']`，
顺便就不用内联覆盖了）→ `config/auth.php` 给 `user` 守卫换 provider —— 路由和中间件一行不用动。
