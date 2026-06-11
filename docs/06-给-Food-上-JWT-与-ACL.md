# 第 6 章　给 Food 上 JWT 与 ACL（动作级授权）

目标：把第 2 章故意留着公开的 `food` 路由锁进 JWT，并启用这套架构的招牌能力 ——
**动作级 ACL 鉴权**。完整闭环：无 token `401` → 有 token 无权限 `403` → 角色授权 → `200`。

---

## 6.1 ACL 的机制（读懂再动手）

moo-scaffold 生成的控制器都长这样：

```php
public function boot(): void
{
    $this->checkAuthorization();   // Foundation\Controller::callAction 先跑 boot 再跑 action
}
```

`checkAuthorization()`（`Mooeen\Scaffold\Foundation\Controller`）做的事：

1. 把「当前控制器::action」格式化成 **acl key**：先生成明文 key
   （如 `admin-food-food-index`），再因 `config('scaffold.authorization.md5')=true`
   取 `substr(md5(明文), 8, 16)`（如 `d84c4f5251f855f0`）；
2. 拿这个 key 去问 Gate `acl_authentication` —— **这个 Gate 是 host 契约，包里不定义**。

授权数据存在哪：`system_roles.role_next_actions` 一列，逗号拼接的 md5 key。
`Role` 模型的 `role_actions` 访问器/修改器负责数组 ↔ 字符串转换；
`Personnel::getActions()` 汇总此人所有角色的 key。后台「授权」模块勾选动作，写的就是这列。

## 6.2 host 侧三步启用

**① 定义 Gate**（新建 `app/Providers/AuthServiceProvider.php`，仿 wisdomcity）：

判定顺序：`$personnel->isRoot()`（id=1，雪花主键下基本不存在）→
`config/actions.php` 白名单 → 角色动作（**`is_root` 字面量 = 超级权限**）→ 精确匹配 key。
记得在 `bootstrap/providers.php` 登记。

> **白名单不能是空的**（复审时发现，第 20 条）：开关一开，moo-system 的**个人中心**
> （`AdminController` 的 index/update/password/avatar/logins 等 8 个动作）也开始鉴权——
> 零授权角色（比如刚建的「编辑员」）登录后连查看本人信息、改密码都 403，把自己锁死
> 在门外。`config/actions.php` 的 whitelist 必须放行这 8 个 key（已带注释写好），
> 有 `FoodAclTest::test_zero_action_role_can_still_use_personal_center` 守护。

**② 打开开关**（`config/scaffold.php`）：

```php
'authorization' => [
    'check' => true,   // 关掉则所有 checkAuthorization() 直接放行（第 1~5 章的状态）
```

**③ 路由入组**（`routes/admin.php`）——把 food 那组从 `[]` 改成：

```php
Route::group(['middleware' => ['jwt.guard.auth:admin', 'jwt.auth.refresh']], function () {
    Route::iResource('food', FoodController::class);
    // :insert_code_here:do_not_delete
});
```

> 开关打开后 **moo-system 的所有模块同样开始鉴权**。所以 `RoleSeeder` 同步改了：
> 「系统管理员」角色授 `is_root` 字面量，seed 出来的管理员才不会把自己锁在门外。
> 已有库跑一次 `php artisan db:seed --class=RoleSeeder --force` 即可。

## 6.3 真机演练：403 → 授权 → 200

造一个挂「编辑员」角色（零授权）的人员（tinker）：

```php
$e = Mooeen\System\Models\Personnel::firstOrNew(['mobile' => '13900000000']);
$e->real_name = '编辑小王'; $e->staff_status = 7; $e->account_status = 7;
$e->password = 'editor888'; $e->created_account_at = now(); $e->save();
$e->roles()->syncWithoutDetaching([Mooeen\System\Models\Role::where('role_name', '编辑员')->first()->id]);
```

实测（管理员是 `is_root`，全绿；编辑小王是另一番景象）：

```bash
# 无 token                                          → 401
curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:8088/api/admin/food?page=1&page_limit=10"
# 编辑小王登录后调 food                                → 403 This action is unauthorized.
# （APP_DEBUG=true 时 403 会带完整 trace，生产是干净的 {"message": ...}）
```

给「编辑员」角色授 `food.index` 这一个动作（tinker；与后台「授权」模块勾选等效）：

```php
$key = substr(md5(Mooeen\Scaffold\Foundation\Controller::aclPlainKey(
    App\Admin\Controllers\Food\FoodController::class.'::index')), 8, 16);   // d84c4f5251f855f0
$r = Mooeen\System\Models\Role::where('role_name', '编辑员')->first();
$r->role_actions = [$key]; $r->save();
```

再测 —— **授权是动作粒度的**：

```bash
# 编辑小王调 food 列表  → 200
# 编辑小王新增 food     → 仍然 403（只授了 index，没授 store）
```

## 6.4 两个容易踩的点

1. **校验先于鉴权**：FormRequest 在控制器 `boot()` 之前解析，参数不合法时你会先看到
   `422` 而不是 `403`——别误判成"ACL 没生效"。
2. **第 2 章的无 token 调试到此失效**：food 已上锁，调试器/curl 都要带
   `Bearer token`（第 2 章已加注记）。

守护测试：`tests/Feature/FoodAclTest.php`（4 用例，覆盖 401/is_root/403/授权后 200 + 动作粒度）。
