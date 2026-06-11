# 第 6 章　给 Food 上 JWT 与 ACL（动作级授权）

目标：把第 2 章故意公开的 `food` 接口锁进 JWT，并启用这套架构的招牌能力——
**动作级 ACL 授权**。做完后完整走一遍：无 token `401` → 有 token 无权限 `403` →
给角色授权 → `200`。

---

## 6.1 先花两分钟搞懂机制

moo-scaffold 生成的每个控制器都带这么一段：

```php
public function boot(): void
{
    $this->checkAuthorization();   // 每个 action 执行前先过这里
}
```

`checkAuthorization()` 做两件事：

1. 把「当前控制器::方法」算成一个 **acl key**：先得到明文（如 `admin-food-food-index`），
   再取 `substr(md5(明文), 8, 16)`（如 `d84c4f5251f855f0`）。
   每个接口的明文/密文 key 都能在 `/scaffold/routes` 页面直接看到：

   ![接口路由页的 ACL key 列表](./images/06-scaffold-routes-acl.png)

2. 拿这个 key 去问 Laravel 的 Gate `acl_authentication`——**这个 Gate 包里不定义，
   必须 host 自己写**（下一节就写它）。

授权数据存在 `system_roles.role_next_actions` 一列（逗号拼接的 key 串）。
后台「授权」模块勾选动作、或者代码里给 `$role->role_actions` 赋数组，写的都是这列。

## 6.2 启用 ACL（四步）

**第 1 步：写 Gate。** 新建 `app/Providers/AuthServiceProvider.php`（完整文件见仓库），
核心就是一个判定顺序：

```php
Gate::define('acl_authentication', function ($personnel, $acl_key) {
    if ($personnel->isRoot()) return true;                    // ① id=1 直通（雪花主键下基本没有）
    // ② config/actions.php 白名单：登录即可用
    // ③ 角色动作里有 'is_root' 字面量 = 超级权限
    // ④ 精确匹配 acl key
});
```

别忘了在 `bootstrap/providers.php` 登记这个 Provider。

**第 2 步：打开开关。** `config/scaffold.php`：

```php
'authorization' => [
    'check' => true,   // 第 1~5 章一直是 false（全放行）
```

**第 3 步：填白名单 + 给管理员兜底。** 开关一开，moo-system 的所有模块同时开始鉴权，
两件事必须先做好，否则把自己锁在门外：

- `config/actions.php` 的 `whitelist` 放行**个人中心**的 8 个动作（查看本人信息、
  改密码、改头像等，key 和注释见仓库）。不放行的话，零授权角色登录后连自己的
  资料都 403（坑 #20）；
- `RoleSeeder` 给「系统管理员」角色授 `is_root` 字面量。雪花主键下不存在 id=1 的
  天然 root，没有这条，开了 ACL 连管理员自己都 403（坑 #15）：

```php
$admin_role = Role::where('role_name', '系统管理员')->first();
$admin_role->role_actions = ['is_root'];   // mutator 会写进 role_next_actions 列
$admin_role->save();
```

已有数据库补跑一次：`php artisan db:seed --class=RoleSeeder --force`

**第 4 步：food 路由入组。** `routes/admin.php` 里把 food 那个空 group 改成：

```php
Route::group(['middleware' => ['jwt.guard.auth:admin', 'jwt.auth.refresh']], function () {
    Route::iResource('food', FoodController::class);
    // :insert_code_here:do_not_delete
});
```

> 从此第 2 章"无 token 调 food"的玩法失效，调试器/curl 都要带 `Bearer token`
> （第 2 章已加注记）。

## 6.3 真机演练：403 → 授权 → 200

先造一个挂「编辑员」角色（零授权）的人员，tinker 执行：

```php
$e = Mooeen\System\Models\Personnel::firstOrNew(['mobile' => '13900000000']);
$e->real_name = '编辑小王'; $e->staff_status = 7; $e->account_status = 7;
$e->password = 'editor888';
$e->created_account_at = now();   // 个人中心展示用的开户时间，非必填
$e->save();
$e->roles()->syncWithoutDetaching([Mooeen\System\Models\Role::where('role_name', '编辑员')->first()->id]);
```

**① 无 token → 401：**

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8088/api/admin/food?page=1&page_limit=10"
# 401
```

**② 管理员（is_root）→ 200**；**编辑小王 → 403**（先按第 4 章方式分别登录拿 token）：

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8088/api/admin/food?page=1&page_limit=10" \
  -H "Authorization: Bearer $ADMIN_TOKEN"     # 200
curl -s "http://127.0.0.1:8088/api/admin/food?page=1&page_limit=10" \
  -H "Authorization: Bearer $EDITOR_TOKEN"    # 403 This action is unauthorized.
```

> 调试模式（APP_DEBUG=true）下 403 会带很长的堆栈，生产是干净的 `{"message": ...}`。

**③ 给「编辑员」授 `food.index` 这一个动作**（tinker，与后台「授权」模块勾选等效）：

```php
$key = substr(md5(Mooeen\Scaffold\Foundation\Controller::aclPlainKey(
    App\Admin\Controllers\Food\FoodController::class.'::index')), 8, 16);   // d84c4f5251f855f0
$r = Mooeen\System\Models\Role::where('role_name', '编辑员')->first();
$r->role_actions = [$key]; $r->save();
```

**④ 再测——授权是动作粒度的：**

```bash
# 编辑小王调列表  → 200（刚授的 index）
# 编辑小王新增    → 仍然 403（没授 store）
```

## 6.4 两个容易误判的点

1. **先 422 后 403**（坑 #16）：表单校验发生在控制器 `boot()` 之前。参数不合法时
   你会先看到 422——别误以为"ACL 没生效"，把 `page`/`page_limit` 等必填参数带齐
   才能看到 403。
2. **白名单/授权改完不生效？** 跑过 `config:cache` 的话先 `php artisan config:clear`。

## 6.5 测试守护

`tests/Feature/FoodAclTest.php` 5 个用例：401 / is_root 放行 / 零授权 403 /
零授权也能进个人中心（白名单）/ 授单个动作后 index 200 而 store 仍 403。

```bash
php artisan test --filter=FoodAclTest
# Tests: 5 passed
```

---

## 本章产出

- Gate `acl_authentication` 落在 host（包只消费不定义）；
- ACL 开关打开，food 接口锁进 JWT，moo-system 全模块同步受控；
- 「系统管理员」角色 `is_root` 兜底，个人中心白名单放行；
- 401 → 403 → 授权 → 200 全链路真机走通，5 个测试守护。

下一章：启用一直空着的移动端 `Api/` 分片，用 **user 守卫**做真正的双向隔离。
