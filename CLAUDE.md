# CLAUDE.md

本文件为 Claude Code（claude.ai/code）在本仓库中工作时提供指引。

## 这是什么

`moo-engine-skeleton` 是一套 **Laravel 12 后端骨架**，把作者（“charsen”）沉淀的业务代码结构提炼成
一个从 0 开始的起点。最终目标是一套**可开源的新手教程**：从零开始，搭出一个带
`moo-scaffold` 代码生成器、`moo-system` 系统管理模块（部门 / 岗位 / 人员 / 角色 / 授权）
以及 JWT 登录认证的可运行后端——并且全部经过真机测试。

它是作者多个生产项目的同级 / 骨架版，消费同样的 moo-* 包
（`charsen/moo-scaffold`、`charsen/moo-system`）。
**注意：本仓库的一切资料（文档/代码注释/提交信息）不得出现作者具体生产项目的名称**——
统一用「作者生产项目 / 生产实践」指代。

**教学路线（2026-06 重构）**：JWT 用**自建最简 User** 独立教学（第 3~6 章，零付费依赖）；
moo-system 定位为**进阶/商业包**，放在第 7 章可选接入——后台守卫主体届时从 User 切到
Personnel，移动端 user 守卫**永久**用自建 User（与作者生产项目的真实模式一致）。

### 两条工作原则（来自 README.md，不可妥协）

1. **每一步都写进教程。** 工作过程要沉淀成 `docs/` 下一套从 0 开始、可开源的新手教程，
   照实记录实际执行的命令与结果。
2. **用 MCP 做真机测试。** 不要只写代码——要把它跑起来，对着活的服务验证。用浏览器 MCP
   （playwright / chrome-devtools）驱动 `moo-scaffold` 内置的接口调试器和生成的接口；
   用 `curl` / 数据库查询来确认。

## 仓库结构（重要）

Laravel 应用放在 **`engine/`** 子目录里，而不是仓库根目录——这是本生态所有项目
统一的目录约定。
仓库根目录只放文档、部署脚本和本文件。

```
moo-engine-skeleton/
├── CLAUDE.md  README.md                 # 文档（仓库根）
├── docs/                                # 从 0 开始的教程（原则 1）
├── .vscode/settings.json                # Peacock 窗口配色（纯装饰，保留）
└── engine/                              # ← Laravel 12 应用本体；composer.json 在这里
    ├── app/{Admin,Api}/...              # 入口式 HTTP 分片（见「业务代码架构」）
    ├── scaffold/database/*.yaml         # 表 schema = 代码生成的唯一真相源
    ├── config/{scaffold,moo-system,auth,jwt}.php
    └── routes/{admin,api}.php
```

所有 `php artisan` / `composer` 命令都在 **`engine/`** 目录下执行。

## 环境

- **PHP** 8.3 · **Composer** 2.9 · **Node** 26 / npm 11 —— 均满足 Laravel 12（`php ^8.2`）。
- **数据库**：MariaDB 12 / MySQL 8（实测均可），`127.0.0.1:3306`，库名 `moo_skeleton`。
  教程统一使用示例凭据 `root` / `7777`（读者换成自己的）。
- 本机特有的环境注意事项（php 多版本 PATH、真实凭据出处等）见 **`CLAUDE.local.md`**
  （已 gitignore，不随仓库分发）。

## 私有包接入：开发（path）vs 生产（vcs）

**包定位（2026-06 决策）**：`moo-scaffold` 为**开源**（composer.json 已声明 MIT，
规划发布到公开仓库 / Packagist）；`moo-system` 为**商业包**（声明 proprietary）。
当前两者均尚托管在 Gitee 私有仓库，接入用本节的 path/vcs 双轨制；
moo-scaffold 正式发布后，普通使用可简化为一行 `composer require`。
作者的标准写法：

**开发——本地 path 仓库**（源码实时生效，composer 把同级目录 symlink 进来）：
```json
"require": {
    "charsen/moo-scaffold": "dev-master as 3.999.0",
    "charsen/moo-system":   "dev-master as 1.999.0"
},
"minimum-stability": "stable",
"prefer-stable": true,
"repositories": {
    "scaffold": { "type": "path", "url": "../../moo-scaffold" },
    "system":   { "type": "path", "url": "../../moo-system" }
}
```
`dev-master as 3.999.0` 这个**别名**把 dev 分支“撑”成一个很高的稳定版本号，这样
`minimum-stability: stable` 不会拒绝它，`moo-system` 里传递依赖
`"charsen/moo-scaffold": "^3.0"` 也能解析。从 `engine/` 出发，`../../moo-scaffold` 正好到
`wwwroot/moo-scaffold`。

**生产——私有 VCS**（换掉 repo 块 + 锁 tag）：
```json
"repositories": {
    "moo-system":   { "type": "vcs", "url": "git@gitee.com:charsen/moo-system.git" },
    "moo-scaffold": { "type": "vcs", "url": "git@gitee.com:charsen/moo-scaffold.git" }
},
"require": { "charsen/moo-scaffold": "^3.0", "charsen/moo-system": "^1.2" }
```
生产用 Gitee 的 **SSH 部署公钥**鉴权（无 `auth.json`）。作者维护单独的
`composer.production.json`（vcs），部署时 `cp composer.production.json composer.json`。

**path 仓库的坑：** 对 path 仓库执行 `composer update` **不会**重新发布包的静态资源。
包里 `public/*.js|css` 改了之后，要重跑 `vendor:publish ... --tag=public --force`。
PHP/blade 的改动则无需发布。

> `moo-system` 在运行时**依赖** `moo-scaffold`——host 必须把*两个* repo 都声明出来
> （composer 不会去读某个依赖自己的 `repositories`）。

## 核心工作流：YAML 驱动的代码生成（`moo-scaffold`）

`charsen/moo-scaffold`（命名空间 `Mooeen\Scaffold`，自动发现 provider）是一个**仅开发期的
代码生成器 + 开发 UI**，不是运行时框架。它的命令只在 `runningInConsole()` 时注册，
并受 `config('scaffold.only_in_local')` 约束。

**心智模型：** `scaffold/database/{Module}.yaml`（真相源）→ `moo:fresh`（编译成
`storage/scaffold/*.php` 缓存，所有生成器都读它）→ `moo:free` / 各步生成器产出
Model、Request、Controller、Resource、路由、迁移 → 用内置接口工具调试。

```bash
# host 一次性初始化
php artisan moo:init "charsen"                                   # 写 SCAFFOLD_AUTHOR，建 scaffold/ 目录
php artisan vendor:publish --provider="Mooeen\Scaffold\ScaffoldProvider" --tag=config
php artisan vendor:publish --provider="Mooeen\Scaffold\ScaffoldProvider" --tag=public --force
php artisan moo:account:add charsen --password=<pw> --role=admin # 第一个开发 UI 账号（会打印 token）
# + 在 AppServiceProvider::register() 里注册 Route::iResource 宏（生成的路由要用）
# + 保留 routes/admin.php、routes/api.php 里的路由标记 `// :insert_code_here:do_not_delete`

# 定义并生成一张表（示例：foods）
php artisan moo:schema Food          # 创建 scaffold/database/Food.yaml —— 然后编辑字段
php artisan moo:fresh                # 改完任意 schema yaml 后必须跑（重建缓存）
php artisan moo:free admin Food -a   # Model+Resource+Controller+Request+路由+i18n+ACL+迁移（+API 文档）
                                     # 迁移提示选 yes，或事后跑：php artisan migrate

# 后续增量改动：编辑 yaml → moo:fresh → moo:adder admin Food/Food（单个 action；别重跑 moo:free）
```

**内置开发 UI**（路由前缀 `/scaffold`，需登录，`APP_ENV=production` 时禁止写操作）：
`/scaffold/db/designer`（可视化数据库设计器 + 迁移）、`/scaffold/api/request`
（Postman 风格的接口调试器，后端代理在 `/scaffold/api/proxy`）、`/scaffold/routes`
（路由 + ACL）、`/scaffold/config`。这是做接口真机测试的主要入口。

**绝不要手改**生成的「再生成区」：`app/Models/<Module>/Traits/<Entity>Trait.php`
（`get<Field>TxtAttribute` 访问器）和 `app/Models/<Module>/Enums/*.php`——每次生成都会被覆盖。
`Model/Controller/Request/Resource` 只生成一次（已存在则跳过；`-f` 强制覆盖）——业务代码写这里。

完整命令清单在 `moo-scaffold/src/Command/`；指南在 `moo-scaffold/docs/guide/`。

## 业务代码架构（作者的招牌结构）

读 `moo-scaffold/src/Foundation/{Controller,FormRequest,BaseResource,BaseResourceCollection}.php`
以及本仓库 `engine/app/Admin/...` 下 Food 实体的「四件套」，能直观看到实践。

- **入口即边界；没有 Service/Repository 层。** 逻辑分布在：轻量 **Controller**（编排）、
  **Model**（业务规则靠 `boot()` 守卫 + trait + `ModelFilter`）、**ModelFilter**（query 字符串 → 查询）、
  **Resource**（输出）。不要引入 Service/Repository 类——那是逆着这套代码的纹理来的。
- **镜像对称的 HTTP 分片**，位于 `app/` 下：`Admin/`（前缀 `api/admin`）、`Api/`（移动端，
  前缀 `app`），可选 `Rpa/`、`Screen/`。每个都有 `Controllers/ Requests/ Resources/`。
  加功能就加到对应的分片里。
- **一个实体 = 约 15 个文件的扇出**，在 `<Module>/<Entity>` 下：一个 Model、一个
  `Filters/<Entity>Filter`、一个生成的 `Traits/<Entity>Trait`、各 action 的 FormRequest
  （`Index/Store/Update/Create/Edit/DestroyBatch`，共享一个 `<Entity>RequestTrait`）、
  一个 `<Entity>Resource`。
- **基类**（在 `moo-scaffold/src/Foundation/`）：`Controller` 是 **ACL 引擎**（它的
  `callAction()` 先跑 `boot()` 再跑 `checkAuthorization()`，把 `Class::method` 映射成一个 snake
  风格 ACL key，经 Gate `acl_authentication` 鉴权；`$transform_methods` 让一个 action 复用另一个的权限）。
  `FormRequest` 提供软删感知的 `getUnique()` 和表单控件配置。
  `BaseResource` 提供链式的 `->show()/->hide()/->trashed()` + `whenDate/whenTrashed/...`。
- **响应约定——没有 `{code,data}` 信封。** 成功直接返回 **Resource 本身**。
  错误是 **`{"message": ...}`**（校验错误再带 `errors`），且 **HTTP 状态码承载语义**：
  业务错误默认 **522**（`Mooeen\Scaffold\Exceptions\BaseException`，它是 `ShouldntReport`，
  永远不记日志），校验 **422**（`{message: 第一条错误, errors:{...}}`），认证 **401**。
  列表接口额外带分页 meta `{page, per_page, total, total_page}`。
- **约定：** 雪花算法的**字符串**主键（JSON 里转成字符串，避开 JS 53 位精度溢出）；
  嵌套集树（`kalnoy/nestedset`：`_lft/_rgt/parent_id`，`toTree()`）；处处软删，
  带完整的「回收站/恢复/永久删除」生命周期和每行的 `options` 动作列表；
  **枚举绝不放进 `$casts`**（保持裸 int，显式 `Enum::tryFrom()`；用 `EnumExtend`）。
- **路由：** host 自定义的 `Route::iResource($name, $controller)` 宏
  （注册在 `AppServiceProvider::register()`，见下文「常用命令」的说明）替代 `Route::resource`，
  额外提供 PUT 更新、`DELETE /forever/{id}`、以及在 `/{id}` 之前注册的 `/trashed`。
- **横切关注的接线**集中在 `engine/bootstrap/app.php`（Laravel 12 —— 没有 `Kernel.php`）：
  各分片中间件组、JWT 守卫指派、全局异常分发，以及校验错误的 render 重写
  （JWT 别名 / 中间件组的注册位置见下文「常用命令」里的重要说明）。

## `moo-system` 模块

`charsen/moo-system`（命名空间 `Mooeen\System`）提供开箱即用的后台模块。迁移**自动加载**
（无需发布）；表名都是 `system_*`。路由统一加前缀 **`api/admin`**、名称前缀 `admin.`、
中间件组 **`admin`**（host 必须定义这个组，且要含 JWT）。

- 模块：部门 Department（`system_departments`，嵌套集树，`department/{id}/move`）、岗位 Position、
  人员 Personnel（`system_personnels`，**JWT 认证主体**——实现了 `JWTSubject`）、角色 Role、
  授权 Authorization（ACL 编辑器 + excel 导出）、通知机器人 NotifyRobot、登录管理 LoginManagement、
  操作日志 OperationLog，以及 `me*` 个人中心。
- **boot 前必须提供的 host 契约**（用 `vendor:publish --tag=moo-system-stubs` 发空壳后自行实现）：
  `App\Models\Traits\MediaSynchronous`、`App\Models\Notification`、
  `App\Admin\Controllers\Traits\{BaseActionTrait,UploaderTrait}`、`App\Notifications\SendBlessMessage`、
  `Route::iResource` 宏、一个含 JWT 的 `admin` 中间件组，以及
  `config/auth.php` → `providers.personnels.model = Mooeen\System\Models\Personnel::class` +
  一个用 `personnels` 的 `admin` 守卫。
- **包本身不带 seeder**，但本骨架在 `engine/database/seeders/` 提供了一套：`RoleSeeder` /
  `DepartmentSeeder`（嵌套集树）/ `PositionSeeder` / `PersonnelSeeder`，由 `DatabaseSeeder`
  按 角色→部门→岗位→人员 顺序调用。`php artisan migrate --seed` 即可得到含可登录管理员的初始数据。
  `DatabaseSeeder` 顺序：UserSeeder（自建用户 admin@example.com / password）→ 角色 → 部门 →
  岗位 → 人员。注意**不能用** `WithoutModelEvents`——会静默 nestedset 的事件、把部门树 `_lft/_rgt` 建坏。
- 通过 `config/scaffold.php` → `controller.admin.extra_modules` =
  `['System' => 'Mooeen\System\Http\Controllers\Admin']` 把它的控制器登记进 scaffold 的 ACL/路由工具。
- 维护命令：`php artisan moo-system check`（6 项 host 自检）、`moo-system update`。
- 生产环境下雪花 ID 需要跨 worker 的共享缓存 → 设 `CACHE_STORE=redis`。

## JWT 认证

包：**`php-open-source-saver/jwt-auth` `^2.8`**（`tymon/jwt-auth` 的维护分支，
命名空间 `PHPOpenSourceSaver\JWTAuth`，自动发现 provider）。

```bash
composer require php-open-source-saver/jwt-auth:^2.8
php artisan vendor:publish --provider="PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider"  # config/jwt.php
php artisan jwt:secret                                                                                # 写入 JWT_SECRET
```
- `config/auth.php`：`admin` / `user` 两个 JWT 守卫
  `['driver'=>'jwt','provider'=>...,'hash'=>false]` —— `hash:false` 是因为登录控制器手动校验密码。
  主体模型实现 `JWTSubject`（`getJWTIdentifier` + `getJWTCustomClaims`，guard 声明动态跟随守卫）。
- `config/jwt.php` 关键值（默认值已固化进 config，env 只需 `JWT_SECRET`）：`ttl=2880`（2 天）、
  `refresh_ttl=20160`（2 周）、`refresh_iat=true`（滑动续期）、`HS256`、开启黑名单并留
  **90 秒**宽限期（并发请求续签不打架）、`persistent_claims=['guard']`（**必须**——否则续签出的
  新 token 丢 guard claim，过 `JWTGuardAuth` 时 401，生产环境踩过的坑）。
- `config/cors.php` 必须发布并设 `exposed_headers=['Authorization']`、paths 含 `api/*` 与 `app/*`：
  无感续签的新 token 放在 authorization 响应头，跨域下不暴露就读不到。
- **登录是手动的，不用 `attempt()`：** `Hash::check()` 校验密码（带自定义前置检查），再用
  `Auth::login($user)` 签发。响应：`{"data":{"user":{...},"token":"<jwt>","expires_in":<秒>}}`，
  其中 `expires_in = Auth::factory()->getTTL()*60`。客户端用 `Authorization: Bearer <token>` 回传。
- 登录路由保持公开；受保护路由用
  `['jwt.guard.auth:admin','jwt.auth.refresh']`（多守卫 + 自动续签）。这 3 个自定义中间件在
  本仓库 `engine/app/Http/Middleware/{JWTAssignGuard,JWTGuardAuth,JWTAuthOrRefresh}.php`。

## 常用命令（在 `engine/` 下执行）

```bash
# 开发服务器跑在 8088（8000 被本机另一个项目占了）。4 个 worker + --no-reload 是
# scaffold 接口调试器所必需的：它的代理会回调同一个服务器，单线程 `php artisan serve` 会死锁。
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
php artisan migrate               # 执行迁移（重建用 migrate:fresh）
php artisan test                  # 全量测试
php artisan test --filter=Name    # 单个测试（Pest/PHPUnit 过滤）
./vendor/bin/pint                 # 格式化 / lint（Laravel Pint）
php artisan tinker                # REPL（调试 / 临时造数据；初始数据见 database/seeders）
php artisan list | grep moo       # 确认 moo-scaffold / moo-system 的命令已注册
php artisan moo-system check      # 6 项 host 集成自检（装包 / 升级后跑）
```

**中间件组写在 `app/Providers/AppServiceProvider::boot()` 里，不是 `bootstrap/app.php`。**
JWT 别名（`jwt.assign.guard` / `jwt.guard.auth` / `jwt.auth.refresh`）和各路由组
（`admin` = 仅指派守卫、`moo-system` = 完整强制认证、`client` = 移动端）都在那里直接注册到 router。
原因：写在 `bootstrap/app.php` 的 `withMiddleware()` 里的组，只有「HTTP 内核」实例化时才同步到
router，于是 `php artisan moo-system check`（走 console 内核）看不到它们。`iResource` 宏注册在
`AppServiceProvider::register()`（必须早于 moo-system 的 provider `boot()` 加载它的路由）。
`config/moo-system.php` 把 `admin.middleware` 指向 `moo-system` 组。moo-system 需要的 host 契约位于
`app/Admin/Controllers/Traits/{BaseActionTrait,UploaderTrait}.php`、`app/Models/Traits/MediaSynchronous.php`、
`app/Models/Notification.php`、`app/Notifications/SendBlessMessage.php`，以及全局函数 `toLabelValue()`
（在 `app/Helpers/helpers.php`，由 composer `autoload.files` 加载）。

## 参考项目（看它们学真实写法）

- `moo-scaffold/docs/guide/01-install.md … 06-acl.md` —— 安装 + 代码生成 + ACL 指南。
- `moo-system/docs/INTEGRATION.md` 和 `tests/Stubs/Host/HostContractStubs.php` —— host 契约说明。
- `moo-scaffold-cloud` —— 汇聚运行时异常 / 慢 SQL / todos 的云端平台（可选；客户端用
  `moo:cloud:push` 推送）。跑通骨架不需要它。

## 搭建进度 —— 已完成（2026-06 教学路线重构后）

全部九章搭好并真机验证（第 8 章为部署指引，无代码产物；第 9 章为增量开发演练，有代码产物）；
从 0 开始的过程写在 `docs/`
（`docs/README.md` 是目录，含一张 25 条「踩过的坑」速查表；`docs/index.html`
是零依赖的网页引导器：`cd docs && php -S 127.0.0.1:9999`）：

1. ✅ Laravel 12 装在 `engine/`，MariaDB `moo_skeleton`（`root`/`7777`）。
2. ✅ `moo-scaffold` 走 path 仓库；生成 `foods` 表；用 curl + `/scaffold` 调试器测接口。
3. ✅ JWT 登录认证（自建最简 User，零付费依赖）：User 实现 JWTSubject（guard claim
   动态跟随守卫）、admin/user/moo-system 三个中间件组、登录/me/refresh/logout 全链路。
   jwt-auth 是 composer **直接依赖**（不靠 moo-system 传递）。
4. ✅ JWT 加固与生产化：persistent_claims / 90s 黑名单宽限 / 滑动续期 / TTL 固化 2880 /
   cors.php 暴露 authorization / 限流（admin 300/min）/ refresh 防孤儿 token /
   composer.production.json。
5. ✅ ACL 已启用：Gate `acl_authentication` 在 host 的 `AuthServiceProvider`（包只消费
   不定义，**多态**——User/Personnel 通吃）；User 的 `actions` JSON 列是契约最小实现
   （'is_root' 字面量 = 超级权限）；acl key = `substr(md5(明文key), 8, 16)`。
6. ✅ 移动端 `Api/` 分片：user 守卫主体是自建 User（email 登录，**永久**）；
   admin/user token 双向隔离；移动端 refresh `(true,false)`
   单设备语义（无 90s 宽限）。
7. ✅ moo-system（进阶/商业包，第 7 章可选）：10 张 `system_*` 表、check 6/6、
   后台守卫主体 User→Personnel（只改 auth.php 一行 + Admin/AuthController 一个文件）、
   角色制授权接管 ACL（个人中心白名单 8 keys）、OperationLog 中间件、调试器联调。
8. ✅ 部署上线教程（docs 第 8 章，可选）：composer 双轨、Redis（雪花/黑名单共享缓存）、
   nginx、supervisor、坑 #22（cache:clear 清掉 JWT 黑名单 → 已注销 token 复活）。
   配套 `.github/workflows/tests.yml`（GitHub 镜像后生效，私有包凭据走
   secrets.MOO_PACKAGES_DEPLOY_KEY，未实测）。
9. ✅ 日常增量开发（docs 第 9 章）：Food 加 `stock` 字段（yaml → `moo:fresh` →
   `moo:migration` 增量 `Schema::table` 迁移）+「自动覆盖 vs 手动补」速查表 +
   `moo:adder` 加 toggleStatus（`$transform_methods` 复用 update 权限）+
   `moo:auth`/`moo:api` 同步 + `FoodIncrementalTest` + curl 冒烟。

默认账号：自建用户 `admin@example.com` / `password`（UserSeeder，第 3~6 章后台 +
永久移动端）；Personnel 管理员 `13800000000` / `admin888`（第 7 章起的后台）；
Scaffold 开发 UI `charsen` / `skeleton2026`。
全量测试 36 个（AuthTest/FoodAclTest/ApiAuthTest 三件套 + JwtAutoRefresh/SeederIntegrity/Regression 守护测试 + FoodIncremental + 示例），
`php artisan test` 全绿。**注意仓库代码是第 9 章完成后的最终态**：教程第 3~5 章的
中间态代码（User 版 Admin/AuthController、users 双守卫 auth.php、User 版 AuthTest）
以完整代码形式内联在对应章节文档里。
