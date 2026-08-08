# 第 7 章　安装 moo-system（进阶：完整系统管理）

目标：接入 `moo-system`，加入部门、岗位、人员、角色、授权和操作日志，
并把后台认证主体从 User 切换为 Personnel。

> moo-system 是可选商业包，需要授权访问。未安装时，前 6 章的 User + JWT + ACL 仍可独立使用。

---

## 7.1 接入包

**前置：拿到私有仓库访问权。** `moo-system` 是商业包，安装前要先联系作者获取授权，
并确保本机 / 部署机的 Gitee SSH key 能读取仓库：

```bash
git ls-remote git@gitee.com:charsen/moo-system.git
```

没有访问权，下面的 `composer update` 第一步就会失败（Composer 拉不到 VCS 仓库）。

把 `system` 仓库加进 `engine/composer.json` 的 `repositories`。下面片段只示意要**新增**
`system` 这一项；开源包保持正式版本约束安装，只有商业私有包 `moo-system` 保留 VCS：

```json
"require": {
    "charsen/moo-scaffold": "^2.1.3",
    "charsen/moo-monitor-laravel": "^0.1",
    "charsen/moo-system": "^1.6.17"
},
"repositories": [
    {
        "name": "system",
        "type": "vcs",
        "url": "git@gitee.com:charsen/moo-system.git"
    }
]
```

> composer **不会**读依赖包自带的 repositories 声明；本仓库约定只为 `moo-system`
> 保留私有仓库声明。

安装前先检查第 2 章的 `iResource` 契约：

```bash
php artisan tinker --execute="dump(Route::hasMacro('iResource'));"
# true
```

`moo-system` 会在它的 ServiceProvider `boot()` 阶段加载包路由，因此这个宏必须在
更早的 `AppServiceProvider::register()` 里就可用。输出不是 `true` 时先回到 2.3 节修正，
不要带着错误继续安装。

检查通过后安装（会自动带入 kalnoy/nestedset、maatwebsite/excel、jenssegers/agent 等依赖）：

```bash
composer update charsen/moo-system --with-all-dependencies
```

> 如果 Composer 末尾仍报 `Attribute [iResource] does not exist`，不是“预期坑”，而是第 2 章
> 没有按最终代码完成；回到 2.3 节修正后再重跑 Composer。

第 4 章建立的 `composer.production.json` 还是当时的最小版本，不含 `moo-system`、
`predis/predis`、`tucker-eric/eloquentfilter` 和部署脚本依赖的私包 manifest。此处必须同步升级，
否则开发环境看似完成，到了第 8 章生产安装时才会失败。在项目根目录执行：

> 下文复制的参考仓库必须与当前教程来自同一 tag 或 commit。不要把新版主分支的
> 最终态文件复制到旧版依赖中。

```bash
REFERENCE_ENGINE=../moo-engine-skeleton/engine   # 按你的实际位置调整
test -f "$REFERENCE_ENGINE/composer.production.json"

cp "$REFERENCE_ENGINE/composer.production.json" engine/composer.production.json
cd engine
COMPOSER=composer.production.json composer update --no-install --no-scripts
COMPOSER=composer.production.json composer validate --no-check-publish
COMPOSER=composer.production.json composer show charsen/moo-system --locked
# versions : * 1.6.17（或与你拿到的当前稳定版本一致）
cd ..
```

这会刷新独立的 `engine/composer.production.lock`，不会覆盖开发用的
`engine/composer.json` / `engine/composer.lock`。`--no-install --no-scripts` 只解析并锁定生产依赖，
不会安装依赖，也不会提前触发生产 Composer 的 Artisan 脚本；当前开发目录的 `vendor/`
仍保持开发版，不会删掉测试工具。

## 7.2 提供 host 端契约（6 个文件 + 1 个全局函数）

moo-system 的控制器/模型会 `use` host 侧的几个 trait 和类（叫「host 契约」）。
需要提供以下 6 个文件：

```
engine/app/Admin/Controllers/UploadController.php          ← 最小上传端点，供头像/附件表单控件使用
engine/app/Admin/Controllers/Traits/BaseActionTrait.php   ← 覆盖第 2 章 scaffold 生成的精简版
engine/app/Admin/Controllers/Traits/UploaderTrait.php
engine/app/Models/Traits/MediaSynchronous.php
engine/app/Models/Notification.php
engine/app/Notifications/SendBlessMessage.php
```

这 6 份是 host 契约，`moo-system` 目前不会通过 `vendor:publish` 自动写入你的项目。
方式 B 的项目与教程仓库是两个并列目录；在你的项目根目录（`moo-engine-from-zero/`）
执行下面的真实复制。如果你只在看线上教程，先把开源骨架仓库 clone 到旁边：

```bash
# 只看线上教程、本机还没有参考仓库时才需要这行
git clone https://gitee.com/charsen/moo-engine-skeleton.git ../moo-engine-skeleton

REFERENCE_ENGINE=../moo-engine-skeleton/engine   # 路径不同就改成你的实际位置
test -f "$REFERENCE_ENGINE/app/Admin/Controllers/UploadController.php"

mkdir -p engine/app/Admin/Controllers/Traits engine/app/Models/Traits engine/app/Notifications
cp "$REFERENCE_ENGINE/app/Admin/Controllers/UploadController.php" engine/app/Admin/Controllers/
cp "$REFERENCE_ENGINE/app/Admin/Controllers/Traits/BaseActionTrait.php" engine/app/Admin/Controllers/Traits/
cp "$REFERENCE_ENGINE/app/Admin/Controllers/Traits/UploaderTrait.php" engine/app/Admin/Controllers/Traits/
cp "$REFERENCE_ENGINE/app/Models/Traits/MediaSynchronous.php" engine/app/Models/Traits/
cp "$REFERENCE_ENGINE/app/Models/Notification.php" engine/app/Models/
cp "$REFERENCE_ENGINE/app/Notifications/SendBlessMessage.php" engine/app/Notifications/
```

> `test -f` 没有任何输出才是通过；一旦报错就先修正 `REFERENCE_ENGINE`，不要继续执行
> `cp`。第二条 `cp` 会覆盖第 2 章的精简 `BaseActionTrait`，这正是本节需要的升级。
> 复制后可执行
> `rg 'Mooeen\\Scaffold\\Concerns\\UsingSnowFlakePrimaryKey' engine/app/Models/Notification.php`
> 做一次版本检查：当前版应复用 moo-scaffold 的共享雪花 ID 实现，不再依赖旧的
> `App\Models\Traits\UsingSnowFlakePrimaryKey`。

`UploaderTrait::getUploadImageControl()` 会把头像控件的上传地址指向
`api/admin/upload/image?field=avatar`；因此还要在 `routes/admin.php` 的登录保护组内注册：

```php
use App\Admin\Controllers\UploadController;

Route::post('upload/image', [UploadController::class, 'image'])->name('upload.image');
Route::post('upload/file', [UploadController::class, 'file'])->name('upload.file');
```

> 注意第一个是**覆盖**而非新增。7.4 的 `moo-system check` 只验证这些 trait/class
> **存在**，不校验内容——忘了覆盖、还在用第 2 章精简版时自检照样全绿，
> 问题会推迟到调用包接口时才暴露。这一步务必以仓库文件为准。

还差一个全局函数 `toLabelValue()`（部门控制器在用）。
**新建文件** `engine/app/Helpers/helpers.php`，内容如下：

```php
<?php

if (! function_exists('toLabelValue')) {
    /**
     * 把数据集转成前端「label-value」选项结构（支持树状 children 与关联子项）。
     *
     * @param  array  $data  数据集（数组）
     * @param  string  $key_field  作为 value 的字段名
     * @param  string  $label_field  作为 label 的字段名
     * @param  string  $count_field  可选：作为 count 的字段名
     * @param  array  $other  可选：关联子项 [关联字段, 子value字段, 子label字段, 前缀?]
     */
    function toLabelValue(array $data, string $key_field, string $label_field, string $count_field = '', array $other = []): array
    {
        $res = [];
        foreach ($data as $one) {
            $tmp = ['value' => $one[$key_field], 'label' => $one[$label_field]];

            if ($count_field !== '') {
                $tmp['count'] = $one[$count_field];
            }

            if (! empty($one['children'])) {
                $tmp['children'] = toLabelValue($one['children'], $key_field, $label_field, $count_field, $other);
            }

            // 处理 model 的关联数据（已是最后一级）
            if (! empty($other) && ! empty($one[$other[0]])) {
                $select = [];
                $prefix = $other[3] ?? ' · ';
                foreach ($one[$other[0]] as $o) {
                    $select[] = ['value' => $o[$other[1]], 'label' => $prefix.$o[$other[2]]];
                }
                $tmp['children'] = isset($tmp['children']) ? array_merge($tmp['children'], $select) : $select;
            }

            $res[] = $tmp;
        }

        if (empty($res)) {
            $res = [['label' => '暂无相关数据', 'value' => '']];
        }

        return $res;
    }
}
```

然后在 `composer.json` 的现有 `autoload` 中加入 `files`。下面是可直接替换的完整
`autoload` 段，不要把省略号写进 JSON：

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Database\\Factories\\": "database/factories/",
        "Database\\Seeders\\": "database/seeders/"
    },
    "files": [
        "app/Helpers/helpers.php"
    ]
}
```

```bash
composer dump-autoload
php -r "require 'vendor/autoload.php'; var_dump(function_exists('toLabelValue'));"
# bool(true)
```

> 缺少 `toLabelValue()` 时，部门列表会返回 500。完整异常栈可在
> `storage/moo-monitor/runtimes/open/` 查看。

## 7.3 后台主体切换：User → Personnel

这是本章的核心动作，也是第 3 章设计的回报时刻——只动两个文件
（机制层的中间件 / 路由 / Gate / 移动端 user 守卫全都不碰）：

**① `config/auth.php`**：两处改动——`admin` 守卫的 `provider` 改一行指到
`personnels`，再在 `providers` 里**新增一段** `personnels` 数组项
（`user` 守卫**不动**，移动端继续用自建 User）：

```php
'guards' => [
    'web'   => ['driver' => 'session', 'provider' => 'users'],
    'admin' => ['driver' => 'jwt', 'provider' => 'personnels'],   // ← 只改 provider
    'user'  => ['driver' => 'jwt', 'provider' => 'users'],        // ← 不动
],
'providers' => [
    'users'      => ['driver' => 'eloquent', 'model' => App\Models\User::class],
    'personnels' => ['driver' => 'eloquent', 'model' => Mooeen\System\Models\Personnel::class],  // ← 新增整项
],
```

> 不要在 `jwt` 守卫里加 `hash => false`。那是 Laravel 内置 `token` 守卫的配置项，
> jwt-auth 创建 `JWTGuard` 时不读它。本项目的密码校验明确发生在登录控制器的
> `Hash::check()`，与 guard 配置无关。

> 顺带说明：本骨架 `config/auth.php` 的默认守卫早已是
> `'guard' => env('AUTH_GUARD', 'admin')`（第 3 章改的，API 后端以后台为主入口），
> 不是 Laravel 教科书默认的 `web`——这处**不需要**在本章再动。

**② `app/Admin/Controllers/AuthController.php`** 换成 Personnel 版
（需要将第 3 章的 User 版改为 Personnel 版）。与第 3 章 User 版的差异一目了然：

这个控制器包含登录统计和登录 token 记录同步，不要凭差异描述手工猜着改。
继续复用 7.2 已经验证过的参考仓库，在**项目根目录**执行：

```bash
REFERENCE_ENGINE=../moo-engine-skeleton/engine   # 按你的实际位置调整
test -f "$REFERENCE_ENGINE/app/Admin/Controllers/AuthController.php"
cp "$REFERENCE_ENGINE/app/Admin/Controllers/AuthController.php" engine/app/Admin/Controllers/AuthController.php
```

复制后再读一遍下面四个差异，它们是验收点，不是让你自行补全代码的提示：

- 查询主体：请求体字段统一叫 **`account`**，控制器拿它**同时匹配姓名或手机号**——
  `Personnel::where('real_name', $params['account'])->orWhere('mobile', $params['account'])`。
  所以 7.8 登录时传的是 `{"account":"13800000000",...}`，而不是 `mobile`；
- 状态检查：字段是 int，必须与 `AccountStatus::FORBIDDEN->value` 比较；
- 登录后更新 `login_times / last_login_at / last_login_ip`；
- `refresh()` 补一行 `UpdateLoginTokenJob::dispatch($old, $new)`（同步包里的登录管理记录）。

## 7.4 包路由接线 + 迁移 + 自检

发布包配置并指到第 3 章预埋的 `moo-system` 中间件组：

```bash
php artisan vendor:publish --tag=moo-system-config
```

```php
// config/moo-system.php
'admin' => ['prefix' => 'api/admin', 'name' => 'admin.', 'middleware' => 'moo-system'],
```

顺手把 moo-system 的控制器登记进 scaffold（`config/scaffold.php`）——
ACL key 的命名空间反查、接口文档、调试器联调都依赖这一步，**必须在跑测试之前做**：

```php
'controller' => [
    'admin' => [
        // ...
        'extra_modules' => [
            'System' => 'Mooeen\\System\\Http\\Controllers\\Admin',
        ],
    ],
],
```

配置好 `extra_modules` 后立即让 scaffold 从**真实路由**重建后台 ACL 动作树：

```bash
php artisan moo:auth admin
```

这一步会更新 `config/actions.php`、两份 actions 语言文件和
`scaffold/acl/admin.yaml`。不跑它的话，root 管理员可能靠 `is_root` 看起来一切正常，
但普通角色根本没有 moo-system 动作可授权。7.5 还会在生成结果上手动合并个人中心白名单；
以后再跑 `moo:auth` 时也要重新合并。

迁移（包内 migration 自动加载）+ 5 项自检：

```bash
php artisan migrate             # 建 system_* 共 10 张核心表，并执行包的后续变更迁移
php artisan moo-system check    # 当前应 5/5 全绿
```

```
✓ Auth provider 配置真实 FQN
✓ 包路由使用的 middleware group 含 jwt.auth.refresh
✓ Composer classmap 不含已删除的 App\Models\System\*
✓ Host 端 5 个必需契约 trait/class 全部存在
✓ config:cache 与 source 一致
🎉  All 5 required checks passed. moo-system 配置健康。
```

> `Route::iResource` 已在 7.1 安装前单独检查；当前 `moo-system check` 的输出不再
> 把它重复计入 5 项 host 集成检查。

> 自检读取的是 `config('moo-system.admin.middleware')` 指向的 `moo-system` 组，
> 不是需要放行登录接口的 `admin` 组。

## 7.5 初始数据：角色 → 部门 → 岗位 → 人员

4 个 seeder 完整代码见参考仓库 `engine/database/seeders/`，同时要把第 3 章的
精简 `DatabaseSeeder` 换成仓库版（UserSeeder 之后按序调用四个）。在**项目根目录**
执行：

```bash
REFERENCE_ENGINE=../moo-engine-skeleton/engine   # 按你的实际位置调整
test -f "$REFERENCE_ENGINE/database/seeders/PersonnelSeeder.php"

cp "$REFERENCE_ENGINE/database/seeders/DatabaseSeeder.php" engine/database/seeders/
cp "$REFERENCE_ENGINE/database/seeders/RoleSeeder.php" engine/database/seeders/
cp "$REFERENCE_ENGINE/database/seeders/DepartmentSeeder.php" engine/database/seeders/
cp "$REFERENCE_ENGINE/database/seeders/PositionSeeder.php" engine/database/seeders/
cp "$REFERENCE_ENGINE/database/seeders/PersonnelSeeder.php" engine/database/seeders/
```

| Seeder | 内容 |
|---|---|
| `RoleSeeder` | 系统管理员（授 `is_root` 字面量 = 超级权限，对应 ACL Gate 第 ③ 优先级）/ 开发 / 编辑员 |
| `DepartmentSeeder` | 猫途科技（根）→ 技术部[后端组/前端组] / 市场部（嵌套集树 `_lft/_rgt`） |
| `PositionSeeder` | 后端工程师 / 前端工程师 / 市场专员 |
| `PersonnelSeeder` | 管理员 `13800000000` / `admin888`，挂技术部·后端工程师·系统管理员角色 |

> 这些 seeder 只用于本地学习和测试，包含公开演示密码。复制后的 `DatabaseSeeder`
> 会在 `APP_ENV=production` 主动拒绝运行；生产初始账号流程见第 8 章。

> 「第 ③ 优先级」回指第 5 章 Gate 闭包的判定顺序：① `isRoot()` 天然 root 直通
> → ② `config/actions.php` 白名单 → ③ `getActions()` 含 `'is_root'` 字面量 = 超级权限
> → ④ 精确匹配 acl key。

> `DatabaseSeeder` 不要使用 `WithoutModelEvents`，否则 Department 的嵌套集字段无法维护。
> 雪花主键也没有固定的 id=1 root，因此系统管理员角色必须包含 `is_root`。

**ACL 白名单**：开着 ACL 接入 moo-system 后，`config/actions.php` 的 `whitelist`
必须放行**个人中心**的 8 个动作（查看资料、改密码、改头像等），否则普通用户会收到 403。
刚才的 `moo:auth admin` 在当前版本会自动留下「本人信息」的
`84470713dcb9a7c9`；在同一个 `admin.whitelist` 数组中手动补下面 7 个（已有的值不要删）：

```php
'f6d488cc41bea74a', // admin-system-admin-edit          个人中心·编辑表单
'b00ef1ce449c970b', // admin-system-admin-update        个人中心·更新资料
'cbc32275c4bdb06c', // admin-system-admin-password-form 个人中心·改密码表单
'88e610dbb210a3dc', // admin-system-admin-password      个人中心·修改密码
'1fcbfd9524aebb83', // admin-system-admin-avatar-form   个人中心·头像表单
'd59a5622ff031201', // admin-system-admin-avatar        个人中心·更新头像
'e389e65e330e8af2', // admin-system-admin-logins        个人中心·登录记录
```

先验证 8 个目标 key 都在，再 seed：

```bash
php artisan tinker --execute='$w=config("actions.admin.whitelist",[]); $keys=["84470713dcb9a7c9","f6d488cc41bea74a","b00ef1ce449c970b","cbc32275c4bdb06c","88e610dbb210a3dc","1fcbfd9524aebb83","d59a5622ff031201","e389e65e330e8af2"]; dump(array_values(array_diff($keys,$w)));'
# []

php artisan db:seed
```

seed 完不只看「DONE」，再检查组织树和管理员关联：

```bash
php artisan tinker --execute='$p=Mooeen\System\Models\Personnel::where("mobile","13800000000")->firstOrFail(); dump(["departments"=>Mooeen\System\Models\Department::count(),"roles"=>Mooeen\System\Models\Role::count(),"positions"=>Mooeen\System\Models\Position::count(),"department"=>$p->department?->department_name,"position"=>$p->position?->position_name,"roles_of_personnel"=>$p->roles->pluck("role_name")->all(),"password_ok"=>Illuminate\Support\Facades\Hash::check("admin888",$p->password),"tree_errors"=>Mooeen\System\Models\Department::countErrors()]);'
# departments=5 / roles=3 / positions=3 / 技术部 / 后端工程师 / [系统管理员]
# password_ok=true；tree_errors 的 4 项都是 0
```

## 7.6 操作日志

moo-system 提供了 `system_operation_logs` 表和写库 Job，采集点由 host 决定。三步：

**① 抄中间件**：仓库的 `app/Http/Middleware/OperationLog.php`
（terminable、敏感参数 `[FILTERED]`、响应按 6 万字节做 UTF-8 安全截断）。在**项目根目录**
执行：

```bash
REFERENCE_ENGINE=../moo-engine-skeleton/engine   # 按你的实际位置调整
test -f "$REFERENCE_ENGINE/app/Http/Middleware/OperationLog.php"
cp "$REFERENCE_ENGINE/app/Http/Middleware/OperationLog.php" engine/app/Http/Middleware/
```

**② 挂到两个组的末尾**：`admin` / `moo-system` 两个中间件组都注册在
`engine/app/Providers/AppServiceProvider.php` 的 **`boot()`** 里（仓库版的
`OperationLog::class` 已在两组末位，照抄即可）。注意组特意注册在 provider `boot()`
而不是 `bootstrap/app.php` 的 `withMiddleware()`，确保 Artisan 自检也能读取。

```php
use App\Http\Middleware\OperationLog;

$router->middlewareGroup('admin', [
    'jwt.assign.guard:admin',
    'throttle:admin',
    SubstituteBindings::class,
    OperationLog::class, // 放末尾
]);

$router->middlewareGroup('moo-system', [
    'jwt.assign.guard:admin',
    'jwt.guard.auth:admin',
    'jwt.auth.refresh',
    'throttle:admin',
    SubstituteBindings::class,
    OperationLog::class, // 放末尾
]);
```

**③ 打开开关**：`config/logging.php` 里是
`'operation' => env('OPERATION_LOG', false)`——**默认关闭**。
`.env` 里加上两行，否则照做到底日志也可能永远 0 条：

```bash
OPERATION_LOG=true
QUEUE_CONNECTION=sync
```

然后用 `Ctrl+C` 完整停掉第 1 章启动的服务，重新执行：

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
```

新开一个终端，发一次失败登录来验证「未登录身份 + 密码脱敏 + 同步落库」：

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://127.0.0.1:8088/api/admin/authenticate \
  -H "Content-Type: application/json" \
  -d '{"account":"nobody","password":"super-secret"}'
# 422

php artisan tinker --execute='$l=Mooeen\System\Models\OperationLog::latest("id")->firstOrFail(); dump(["personnel_id"=>$l->personnel_id,"method"=>$l->request_method,"url"=>$l->request_url,"status"=>$l->response_code,"request_param"=>$l->request_param]);'
# personnel_id=null / POST / api/admin/authenticate / 422 / password="***"
```

> 当前 moo-system 的明确业务规则是**root 不记录操作日志**，包内 Job 也会再拦一次；
> 所以不要拿 7.5 种子里唯一的 root 管理员发一次 200 请求、然后以「日志仍是 0」
> 判定接入失败。上面故意用失败登录验证基础链路；要验证已登录业务操作，
> 需要再建一个非 root 人员。对审计要求较高的业务，应重新评估「root 豁免审计」这条产品规则。

> 操作耗时使用 `$request->server('REQUEST_TIME_FLOAT')`，不要依赖测试入口未定义的
> `LARAVEL_START`。异步队列环境还需启动 queue worker；修改 `.env` 后要重启开发服务。

## 7.7 测试换最终版

第 4 章手写的 User 版 AuthTest 完成了历史使命。本章涉及 4 个测试文件，
**前 3 个换成仓库最终版，第 4 个不动**：

| 文件 | 动作 |
|---|---|
| `tests/TestCase.php` | 换最终版（含 `freshJwtProcess()` 等测试基建） |
| `tests/Feature/AuthTest.php` | 换 Personnel 版（登录字段 `account` = `13800000000`） |
| `tests/Feature/FoodAclTest.php` | 换角色版（授权写进 `$role->role_actions`） |
| `tests/Feature/ApiAuthTest.php` | **不用动**（第 6 章的 User 版、email 登录，本就是终态） |

同时把本章已具备前置条件的 4 个守护测试拿进来。在**项目根目录**执行：

```bash
REFERENCE_ENGINE=../moo-engine-skeleton/engine   # 按你的实际位置调整
test -f "$REFERENCE_ENGINE/tests/Feature/RegressionTest.php"

cp "$REFERENCE_ENGINE/tests/TestCase.php" engine/tests/TestCase.php
cp "$REFERENCE_ENGINE/tests/Feature/AuthTest.php" engine/tests/Feature/AuthTest.php
cp "$REFERENCE_ENGINE/tests/Feature/FoodAclTest.php" engine/tests/Feature/FoodAclTest.php
cp "$REFERENCE_ENGINE/tests/Feature/MonitorTest.php" engine/tests/Feature/MonitorTest.php
cp "$REFERENCE_ENGINE/tests/Feature/JwtAutoRefreshTest.php" engine/tests/Feature/JwtAutoRefreshTest.php
cp "$REFERENCE_ENGINE/tests/Feature/SeederIntegrityTest.php" engine/tests/Feature/SeederIntegrityTest.php
cp "$REFERENCE_ENGINE/tests/Feature/RegressionTest.php" engine/tests/Feature/RegressionTest.php
```

同时确认 `phpunit.xml` 里有这行测试用 JWT 密钥（没有它 JWT 测试起不来）：

```xml
<env name="JWT_SECRET" value="testing-secret-do-not-use-in-production"/>
```

运行完整回归测试：

```bash
php artisan test
```

所有测试都应通过。`FoodAclTest` 的主体已从 User actions 切换为 Personnel 角色，
Gate 契约不变。

## 7.8 在 scaffold 调试器里联调

先用多 worker 启动本地服务，避免调试器回调被单线程服务阻塞：

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
```

7.4 已把控制器登记进 scaffold（extra_modules），现在生成接口文档、刷新调试器：

```bash
php artisan moo:api admin System
```

左侧多出「系统管理」整组（部门 / 岗位 / 人员 / 角色 / 授权 / 通知机器人 / 登录 / 操作日志 / 个人信息）：

![调试器里出现系统管理模块](./images/03-system-debugger-list.png)

登录拿 token——注意主体已是 Personnel，请求字段是 `account`（7.3 说过：
控制器拿它同时匹配姓名或手机号）：`{"account":"13800000000","password":"admin888"}`。
点开「岗位管理 → 岗位列表」，在 Header 区把 **Authorization 填成 `Bearer <token>`**
（必须包含 `Bearer ` 前缀），发送后应返回 200：

![带 token 调通岗位列表 200](./images/04-system-positions-200.png)

再用 curl 走一遍 CRUD（岗位名换个 seeder 里没有的，重名会撞唯一校验 422）：

```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8088/api/admin/authenticate \
  -H "Content-Type: application/json" \
  -d '{"account":"13800000000","password":"admin888"}' \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')

curl -s "http://127.0.0.1:8088/api/admin/departments?page=1&page_limit=10" \
  -H "Accept: application/json" -H "Authorization: Bearer $TOKEN"      # 部门树

curl -s -X POST http://127.0.0.1:8088/api/admin/positions \
  -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN" \
  -d '{"position_name":"测试工程师"}'                                   # 201
```

---

## 本章产出

- moo-system 接入完成，`moo-system check` 全部通过；
- 后台主体从 User 切换为 Personnel，原有中间件、路由、Gate 和移动端守卫保持不变；
- 角色制授权接管 ACL（白名单放行个人中心），操作日志落库（记得 `OPERATION_LOG=true`）；
- 自动测试和调试器联调通过。

**主线教程完成。** 你现在拥有：代码生成（moo-scaffold）+ 自建用户 JWT + 动作级 ACL +
双守卫隔离的移动端 + 完整系统管理（moo-system）。
踩坑速查见 [docs/README.md](./README.md)。

下一章（可选）：把它部署到真正的服务器上。
