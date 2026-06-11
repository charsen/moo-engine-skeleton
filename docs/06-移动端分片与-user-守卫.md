# 第 6 章　移动端分片与 user 守卫

目标：启用一直空着的 `app/Api/` 分片（移动端，路由前缀 `app`），用 **user 守卫**登录，
做到两件事：① 后台 token 和移动端 token **互不通用**（双向隔离）；
② 移动端刷新是**单设备语义**（旧 token 立即作废，没有 90 秒宽限）。

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

新建 `app/Api/Controllers/AuthController.php`（**完整文件见仓库，这就是最终版**），
结构和第 3 章后台版一样（查用户 → `Hash::check` → 激活检查 → 签发），**两处不同**：

**① 刷新用单设备语义：**

```php
$token = Auth::guard('user')->refresh(true, false);
// forceForever=true：旧 token 立即永久作废，没有 90 秒宽限 —— 新设备登录顶掉旧设备
```

一句话记忆：**后台怕并发打架要宽限（false）；移动端要单设备，不能宽限（true）。**

**② 登出同样走永久拉黑：** `Auth::guard('user')->logout(true);`

refresh 的 try/catch 写法与第 4 章的后台版完全相同。

## 6.3 路由（`routes/api.php`）

```php
Route::post('authenticate', [AuthController::class, 'authenticate'])->name('authenticate');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

// 主动刷新：单独挂 guard 校验，不进 jwt.auth.refresh（孤儿 token 问题，同第 4.4 节）
Route::post('refresh', [AuthController::class, 'refresh'])
    ->middleware('jwt.guard.auth:user')->name('refresh');

Route::group(['middleware' => ['jwt.guard.auth:user', 'jwt.auth.refresh']], function () {
    Route::get('me/info', [AuthController::class, 'me'])->name('me.info');

    // :insert_code_here:do_not_delete   ← 生成器插入的移动端路由默认就在保护圈里
});
```

写好后在 `/scaffold/routes` 切到「客户端接口」应用，能看到移动端的全部路由：

![客户端接口路由](./images/07-scaffold-routes-app.png)

## 6.4 真机验证

```bash
BASE=http://127.0.0.1:8088

# ① 移动端登录（注意前缀是 app，不是 api/admin）
APP_TOKEN=$(curl -s -X POST $BASE/app/authenticate \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}' \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')

# ② 解码 payload 看 guard。JWT 用的是 base64url（- _ 替代 + /，且不带 padding），
#    先转回标准 base64 再补 = 号，否则可能解出半截：
P=$(echo $APP_TOKEN | cut -d. -f2 | tr '_-' '/+'); P="$P$(printf '=%.0s' $(seq $(( (4 - ${#P} % 4) % 4 ))))"
echo $P | base64 -d
# {"iss":...,"guard":"user",...}

# ③ 各回各家 200
curl -s -o /dev/null -w "%{http_code}\n" $BASE/app/me/info -H "Authorization: Bearer $APP_TOKEN"   # 200

# ④ 双向隔离 401（ADMIN_TOKEN 按第 3 章登录后台拿）
curl -s -o /dev/null -w "%{http_code}\n" $BASE/app/me/info -H "Authorization: Bearer $ADMIN_TOKEN"        # 401
curl -s -o /dev/null -w "%{http_code}\n" $BASE/api/admin/me/info -H "Authorization: Bearer $APP_TOKEN"    # 401

# ⑤ 单设备刷新：拿新 token 后，旧 token 立刻 401（无宽限）
NEW=$(curl -s -X POST $BASE/app/refresh -H "Authorization: Bearer $APP_TOKEN" \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
curl -s -o /dev/null -w "%{http_code}\n" $BASE/app/me/info -H "Authorization: Bearer $NEW"          # 200
curl -s -o /dev/null -w "%{http_code}\n" $BASE/app/me/info -H "Authorization: Bearer $APP_TOKEN"    # 401
```

测试守护：仓库 `tests/Feature/ApiAuthTest.php` 5 个用例（401 / 登录 / 双向隔离 /
单设备刷新 / 过期 token 刷新只产生一个新 token），主体就是 User。

> 📦 它依赖仓库 `tests/TestCase.php` 的辅助方法，而那是第 7 章最终版——本章时间点
> 抄来后要微调两处（都是"后台主体还是 User"的缘故）：`adminLogin()` 改成第 4 章的
> email 写法；`makeExpiredToken()` 里 admin 分支的主体/`prv` 也先用 `User`。
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

- `Api/` 分片启用：登录 / me / 刷新 / 登出四件套（user 守卫，自建 User）；
- admin ↔ user token 双向隔离，移动端单设备刷新语义；
- 5 个测试守护，curl 真机验证通过。

下一章（可选 / 进阶）：接入 **moo-system**，后台升级成完整的系统管理
（部门 / 岗位 / 人员 / 角色 / 授权 / 操作日志）。
