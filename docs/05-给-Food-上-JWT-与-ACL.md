# 第 5 章　给 Food 上 JWT 与 ACL（动作级授权）

目标：把第 2 章故意公开的 `food` 接口锁进 JWT，并启用这套架构的招牌能力——
**动作级 ACL 授权**。做完后完整走一遍：无 token `401` → 有 token 无权限 `403` →
给用户授权 → `200`。

> ACL 的鉴权引擎在 moo-scaffold（免费）里，授权数据怎么存由 host 决定。
> 本章用自建 User 的 `actions` 列做**最小实现**；第 7 章的 moo-system（进阶包）
> 用「角色 → 动作」的完整授权体系实现同一个契约。

> ⚠️ **本章演练适用的代码状态**：本文按「方式 B（从 0 跟教程）做到第 4 章末」的
> 快照行文。仓库 HEAD 是第 7~9 章的**最终态**——admin 守卫已换成 Personnel 登录、
> `config/actions.php` 已生成且内容庞大、ACL 开关已是 `true`、food 路由组已带中间件
> 并多了第 9 章的路由——所以下文所有「去改 X」「现在还没有 Y」在仓库里都对不上号；
> 仓库也没有按章 tag/分支，回不到「第 5 章状态」。**对照仓库的读者（方式 A）请只看
> 思路；真机跟做 5.3 需要方式 B 的进度。** 方式 A / 方式 B 的定义见
> [docs/README.md](./README.md)。

---

## 5.1 先花两分钟搞懂机制

moo-scaffold 生成的每个控制器都带这么一段：

```php
public function boot(): void
{
    $this->checkAuthorization();   // 每个 action 执行前先过这里
}
```

`checkAuthorization()` 做两件事：

1. 把「当前控制器::方法」算成一个 **acl key**：先得到明文（如 `admin-food-food-index`）；
   **若 `config/scaffold.php` 的 `authorization.md5` 开关为 `true`（本教程默认）**，
   再取 `substr(md5(明文), 8, 16)`（如 `d84c4f5251f855f0`）；md5 为 `false` 时直接用明文
   （vendor `Foundation/Controller.php` 的 `formatAclName()` 就是按这个开关分支的）。
   每个接口的明文/密文 key 都能在 `/scaffold/routes` 页面直接看到：

   ![接口路由页的 ACL key 列表](./images/06-scaffold-routes-acl.png)

2. 拿这个 key 去问 Laravel 的 Gate `acl_authentication`——**这个 Gate 包里不定义，
   必须 host 自己写**（下一节就写它）。

授权数据存在哪？本章的最小实现：第 3 章 User 模型上那个 `actions` JSON 列——
`getActions()` 返回被授权的 key 数组，`'is_root'` 字面量 = 超级权限
（UserSeeder 给 admin@example.com 的就是它）。

## 5.2 启用 ACL（三步）

**第 1 步：写 Gate。** 新建 `app/Providers/AuthServiceProvider.php`，核心就是下面这个
判定顺序——这段是**完整的闭包实现**（Gate 是多态的，第 7 章换成 Personnel
后一行不用改）：

```php
Gate::define('acl_authentication', function ($user, $acl_key) {
    // ① 天然 root 直通。注意：本章的 User::isRoot() 恒返回 false（自增主键体系下
    //    不启用这条，超级权限走 ③ 的 'is_root' 字面量）；它仍排在第 ① 是给第 7 章
    //    留位——Personnel::isRoot() 是真实生效的判定（getKey() === ROOT_ID，即
    //    id=1 的内置管理员），而雪花主键的系统一般不存在 id=1，同样靠 ③ 兜底。
    //    「换主体不用改 Gate」的多态就体现在这。
    if ($user->isRoot()) {
        return true;
    }

    // ② config/actions.php 白名单：登录即可用。方式 B 做到本章还没有这个文件——
    //    config() 拿不到就是空白名单，整段安全跳过；第 7 章 moo:auth 会生成并维护它。
    foreach (config('scaffold.controller', []) as $app => $config) {
        $whitelist = config('actions.'.$app.'.whitelist', []);
        if (in_array($acl_key, $whitelist, true)) {
            return true;
        }
    }

    $actions = $user->getActions();

    // ③ getActions() 里有 'is_root' 字面量 = 超级权限
    if (in_array('is_root', $actions, true)) {
        return true;
    }

    // ④ 精确匹配 acl key
    return in_array($acl_key, $actions, true);
});
```

> ⚠️ ②③④ 必须有实现，不能只留注释——闭包对非 root 隐式返回 `null` 的话，
> 所有普通用户会被全部拒绝。
>
> 📦 仓库的 `engine/app/Providers/AuthServiceProvider.php` 是第 7 章后的**最终版**：
> 判定逻辑与上面一致，但注释里出现 Personnel、RoleSeeder、「系统管理员」等第 7 章
> 才介绍的概念；其中「moo:acl 生成时维护」是**笔误**——这个命令不存在，实际命令叫
> `moo:auth`（vendor `UpdateAuthorizationCommand` 里 `$name = 'moo:auth'`）。
> 本章使用上面这段即可（已是完整实现）。

别忘了在 `bootstrap/providers.php` 登记这个 Provider。

**第 2 步：打开开关。** `config/scaffold.php`（注意 ACL 配置是**两个**旋钮）：

```php
'authorization' => [
    'check' => true,   // 是否开启鉴权——方式 B 做到这里之前一直是 false（全放行）
    'md5' => true,     // key 形态——true 取 substr(md5(明文), 8, 16)，false 用明文
```

> ⚠️ 改完立刻 `php artisan config:clear`——第 4 章生产化如果跑过 `config:cache`，
> 不清缓存这两个开关都不生效，5.3 ① 会出现「改了还是 200」的假象。
>
> `md5` 开关决定 5.1 / 5.3 ③ 算出来的 key 形态：若你把它改成 `false`，
> 5.3 ③ 按 md5 手算的 key 会**静默失配**（403 始终不变 200）。本教程全程保持 `true`。
>
> 📦 仓库 HEAD 里 `check` 已经是 `true`——「去改它」只对方式 B 跟做成立。

**第 3 步：food 路由入组。** `routes/admin.php` 里把 food 那个空 group 改成：

```php
Route::group(['middleware' => ['jwt.guard.auth:admin', 'jwt.auth.refresh']], function () {
    Route::iResource('food', FoodController::class);
    // :insert_code_here:do_not_delete
});
```

> 📦 仓库 HEAD 的这个 group 早已带中间件，且多了一条第 9 章的
> `Route::post('food/{id}/toggle-status', ...)` ——方式 B 做到本章只需上面这段。
>
> 从此第 2 章"无 token 调 food"的玩法失效，调试器/curl 都要带 `Bearer token`
> （第 2 章已加注记）。admin@example.com 有 `is_root`，不会把自己锁在门外。

## 5.3 真机演练：403 → 授权 → 200

> ⚠️ 本节只适用于**方式 B 跟做到第 4 章末**的代码状态（见开篇说明）。仓库最终态的
> admin 守卫 provider 已是 `personnels`，`AuthController` 校验的是 `account` 字段
> 并查 moo-system 的 Personnel（姓名/手机号）——在最终态上跑下面的 email 登录会直接
> 422 / 查无此人。

跟做前先确认两件事：

```bash
# ⓐ users 表要有 actions 列——第 3 章已建立的迁移
#   （database/migrations/*_add_actions_to_users_table.php）。漏抄的话，
#   下面 ③ 赋权会直接报「列不存在」。检查 + 补救：
php artisan tinker --execute="var_dump(Schema::hasColumn('users', 'actions'));"
# false 的话：从仓库 engine/database/migrations/ 抄这份迁移，再 php artisan migrate

# ⓑ admin@example.com 要存在且 actions 带 'is_root'（UserSeeder，第 3 章）。
#   没跑过种子的话 ② 的管理员登录会失败：
php artisan db:seed --class=UserSeeder
```

先造一个**零授权**的用户（tinker）：

```php
$e = App\Models\User::firstOrNew(['email' => 'editor@example.com']);
$e->name = '编辑小王'; $e->password = 'editor888';
$e->email_verified_at = now();   // 过第 4 章的激活检查
$e->save();                       // actions 不给 —— 零授权
```

**① 无 token → 401：**

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8088/api/admin/food?page=1&page_limit=10"
# 401
```

**② 管理员（is_root）→ 200**；**编辑小王 → 403**。按第 3 章的 sed 提取方式，
两个账号各登录一次、分别赋给两个变量：

```bash
BASE=http://127.0.0.1:8088

ADMIN_TOKEN=$(curl -s -X POST $BASE/api/admin/authenticate \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}' \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')

EDITOR_TOKEN=$(curl -s -X POST $BASE/api/admin/authenticate \
  -H "Content-Type: application/json" \
  -d '{"email":"editor@example.com","password":"editor888"}' \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')

curl -s -o /dev/null -w "%{http_code}\n" "$BASE/api/admin/food?page=1&page_limit=10" \
  -H "Authorization: Bearer $ADMIN_TOKEN"     # 200
curl -s "$BASE/api/admin/food?page=1&page_limit=10" \
  -H "Authorization: Bearer $EDITOR_TOKEN"    # 403 This action is unauthorized.
```

> 调试模式（APP_DEBUG=true）下 403 会带很长的堆栈，生产是干净的 `{"message": ...}`。

**③ 给编辑小王授 `food.index` 这一个动作**（tinker；下面按 `md5 = true` 手算，
若你改过 5.2 的 md5 开关，key 直接用明文）：

```php
$key = substr(md5(Mooeen\Scaffold\Foundation\Controller::aclPlainKey(
    App\Admin\Controllers\Food\FoodController::class.'::index')), 8, 16);   // d84c4f5251f855f0
$e = App\Models\User::where('email', 'editor@example.com')->first();
$e->actions = [$key]; $e->save();
```

**④ 再测——授权是动作粒度的：**

```bash
# 编辑小王调列表 → 200（刚授的 index）
curl -s -o /dev/null -w "%{http_code}\n" "$BASE/api/admin/food?page=1&page_limit=10" \
  -H "Authorization: Bearer $EDITOR_TOKEN"    # 200

# 编辑小王新增 → 仍然 403（没授 store）。store 是 POST，必须带齐合法请求体才能
# 越过表单校验看到 403（否则先撞 422，正是 5.4 的坑 #16）。必填字段可在
# /scaffold 调试器或 app/Admin/Requests/Food/Food/StoreRequest.php 查到：
curl -s -o /dev/null -w "%{http_code}\n" -X POST "$BASE/api/admin/food" \
  -H "Authorization: Bearer $EDITOR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"food_name":"测试新菜品","food_category":1,"price":500,"food_status":1}'   # 403
```

## 5.4 两个容易误判的点

> 「坑 #N」编号出自 [docs/README.md](./README.md) 的「踩过的坑速查」表——
> 下面的 #16 正是本章贡献的那条。

1. **先 422 后 403**（坑 #16）：表单校验发生在控制器 `boot()` 之前。参数不合法时
   你会先看到 422——别误以为"ACL 没生效"，把 `page`/`page_limit` 等必填参数带齐
   才能看到 403（POST 同理，见 5.3 ④）。
2. **白名单/授权改完不生效？** 跑过 `config:cache` 的话先 `php artisan config:clear`
   （5.2 第 2 步已提醒过一次，这里再记一笔）。

## 5.5 测试守护（练习）

照第 4 章 AuthTest 的样子，给 ACL 写 4 个用例：无 token 401 / is_root 200 /
零授权 403 / 授单个动作后 index 200 而 store 仍 403。
完整的测试代码见本仓库 `engine/tests/Feature/FoodAclTest.php`（Laravel 应用整体在 `engine/`
子目录，仓库根没有 `tests/`）。

> 📦 注意：那是第 7 章接入 moo-system 后的**最终版**——登录主体是 Personnel
> （手机号 `'13900000000'` + 「编辑员」角色），授权对象是"角色"而不是本章 User 的
> `actions` 列。**处于第 5 章进度直接跑它必挂**，只能参考断言思路；顺带一提，它的
> `foodIndexAclKey()` 也是按 `authorization.md5` 开关分支算 key 的，正好印证 5.1。

---

## 本章产出

- Gate `acl_authentication` 落在 host（包只消费不定义，且多态——换主体不用改）；
- ACL 开关打开，food 接口锁进 JWT；
- 用 User 的 `actions` 列演示了最小授权存储：401 → 403 → 授权 → 200 全链路真机走通。

下一章：启用一直空着的移动端 `Api/` 分片，用 **user 守卫**做真正的双向隔离。
