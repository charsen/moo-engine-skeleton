# moo-engine-skeleton 研发立项说明

> **一句话介绍**：moo-engine-skeleton 是一套基于 Laravel 12 的企业级后端工程骨架，内置 YAML 驱动的代码生成器（moo-scaffold）、双守卫 JWT 认证、动作级 ACL 授权与可选的系统管理模块（moo-system），并配套一套从零开始、全程真机验证的开源教程，使新项目可以跳过基建期直接进入业务开发。

---

## 一、项目背景与定位

团队多个生产项目沉淀出一套高度一致的后端工程实践：入口式 HTTP 分片、YAML 驱动的代码生成、雪花字符串主键、软删全生命周期、JWT 多守卫认证、动作级 ACL。但这套实践分散在各业务仓库中，新项目启动和新人上手都需要反复"考古"。

本项目把这套实践提炼为一个**从 0 开始的标准起点**，达成三个目标：

1. **新项目脚手架**：备齐依赖后克隆即得一个可运行、可生成代码、带完整认证授权的后端底座；
2. **新人教学载体**：`docs/` 下十章教程照实记录每条命令与结果，配零依赖网页引导器，新人可独立复现整个搭建过程；
3. **生产经验回灌池**：生产项目踩过的坑（JWT 续签丢 claim、孤儿 token、nestedset 事件被静默等 27 项）以代码 + 文档双重形式固化于此，反向校准生产仓库。

**教学路线设计**：第 1~6 章零付费依赖（JWT 用自建最简 User 独立教学）；第 7 章接入商业包 moo-system 为可选进阶；第 8~9 章覆盖部署上线与增量开发工作流——这使骨架同时满足开源教学与商业交付两种场景。

> **跑通前的硬前置**（只读本篇容易忽略）：`engine/composer.json` 当前过渡期通过 Composer VCS 解析 `moo-scaffold` / `moo-monitor-laravel` / `moo-system`。`moo-scaffold`、`moo-monitor-laravel` 是开源包，目标发布到 Packagist；`moo-system` 是商业包，必须通过 VCS 授权分发。Packagist 同步目标版本前，只克隆本仓库且没有相应 VCS 权限时，`composer install` 仍会失败（完整环境步骤见 `HANDOFF.md` §1~3 与根 `README.md`「快速开始」）。"零付费依赖"指第 1~6 章不需要商业包，不等于"零前置"。

## 二、技术栈与运行环境

| 项 | 选型 | 说明 |
|---|---|---|
| 框架 | Laravel 12（PHP 8.2+） | 应用本体位于 `engine/` 子目录，与生态内其它项目一致。当前 lock 已按 PHP 8.2 可安装版本解析 |
| 数据库 | MariaDB 12 / MySQL 8 | 实测均可；骨架库名 `moo_skeleton` |
| 认证 | php-open-source-saver/jwt-auth ^2.8 | tymon/jwt-auth 的维护分支，composer 直接依赖 |
| 代码生成 | charsen/moo-scaffold（**开源 MIT**，当前 VCS 过渡，目标 Packagist） | 运行时也依赖其基类、路由宏和调试台资源，不应放入 `require-dev` |
| 系统管理 | charsen/moo-system（**商业包**，可选） | 部门/岗位/人员/角色/授权等 8 个开箱模块 |
| 主键 | 雪花算法字符串主键 | JSON 输出转字符串，规避 JS 53 位精度溢出 |
| 测试 | PHPUnit 11（无 Pest），`php artisan test` 45 passed | Feature 11 个文件共 44 个测试方法 + Unit 1 个，覆盖双守卫认证、ACL、移动端全链路、增量开发回归、监控采集与上传端点 |

## 三、总体架构

```
moo-engine-skeleton/
├── docs/                      # 九章从零教程 + 网页引导器 + 27 条踩坑速查
├── README.md / HANDOFF.md     # 快速开始 / 环境搭建与交接说明
├── CLAUDE.md / CLAUDE.local.md# AI 协作工程说明（local 为本机文件，已 gitignore，新克隆没有）
└── engine/                    # Laravel 12 应用本体
    ├── app/Admin/             # 后台分片（路由前缀 api/admin）
    ├── app/Api/               # 移动端分片（路由前缀 app）
    ├── app/Http/Middleware/   # JWT 三中间件 + 操作日志中间件
    ├── scaffold/database/     # 表结构 YAML —— 代码生成唯一真相源
    ├── config/{scaffold,moo-system,auth,jwt,cors}.php
    └── routes/{admin,api}.php # 含生成器插入标记
```

**核心架构约定**（与生产项目完全对齐）：

- **入口即边界，无 Service/Repository 层**：逻辑分布在轻量 Controller（编排）、Model（`boot()` 守卫 + trait + ModelFilter）、Resource（输出）三处；
- **镜像对称的 HTTP 分片**：`Admin/`（后台）与 `Api/`（移动端）各自拥有 Controllers/Requests/Resources，互不渗透；
- **响应约定无信封**：成功直接返回 Resource，错误为 `{"message": ...}`，HTTP 状态码承载语义（业务错误 522 / 校验 422 / 认证 401 / 越权 403）。522 不是标准 HTTP 状态码（不是 Cloudflare 那个超时，也不是笔误）——它是 moo-scaffold `BaseException` / moo-system `BusinessException` 约定的业务错误码，刻意避开标准码的既有语义；
- **处处软删**：带完整「回收站 / 恢复 / 永久删除」生命周期及每行 `options` 动作列表；
- **枚举不进 `$casts`**：字段保持裸 int，显式 `Enum::tryFrom()` 转换，规避枚举实例比较陷阱。

## 四、分模块介绍与功能点清单

### 模块 1：应用底座（engine/）

Laravel 12 标准工程加生态约定的固化。

| 功能点 | 介绍 |
|---|---|
| `engine/` 子目录工程布局 | 仓库根只放文档（部署内容也是文档：`docs/08-部署上线.md`，无部署脚本），Laravel 应用本体集中在 `engine/` |
| `bootstrap/app.php` 横切接线 | Laravel 12 无 Kernel.php，分片中间件挂载、全局异常分发、校验错误 render 重写集中于此 |
| `AppServiceProvider` 双时机注册 | `register()` 注册 `Route::iResource` 宏（须早于包路由加载）；`boot()` 把 JWT 别名与 admin/user/moo-system 三个中间件组注册到 router（保证 console 内核可见） |
| `Route::iResource` 宏 | 替代 `Route::resource`：额外提供 PUT 更新、批量删除 `destroyBatch`、回收站 `/trashed`（先于 `/{id}` 注册）、恢复 `restore`、`DELETE /forever/{id}` 永久删除；且**用反射检查控制器，action 真实存在且为 public 才注册对应路由**，杜绝"幻影路由"（声明了却 404 的路由） |
| 通用 Model Traits | `UsingSnowFlakePrimaryKey`（雪花字符串主键）、`HasOperator`（操作人追踪）、`BaseFilter`（query string → 查询条件） |
| moo-* 包接入 | 当前过渡期 host 显式声明三个 VCS 仓库以保证可安装；目标状态为 `moo-scaffold` / `moo-monitor-laravel` 走 Packagist，只有 `moo-system` 保留 VCS |

### 模块 2：代码生成器接入（moo-scaffold）

YAML 驱动的开发期代码生成器与开发 UI，骨架已完成全部接入并以 `foods` 表为样例实体。

| 功能点 | 介绍 |
|---|---|
| YAML 单一真相源 | `scaffold/database/*.yaml` 定义表结构；`moo:fresh` 编译缓存，所有生成器读缓存产码 |
| 一键实体生成（`moo:free`） | 一条命令产出一个实体约 15 个文件：Model + Filter + Trait + 六个 FormRequest + Resource + Controller + 路由 + i18n + ACL 注册 + 数据库迁移 + API 文档 |
| 增量生成（`moo:adder`） | schema 变更后按单 action 增量补码，不覆盖已写业务代码；「再生成区」（Traits/Enums）与「一次生成区」（Model/Controller 等）边界明确 |
| 内置开发 UI（`/scaffold`） | 可视化数据库设计器、Postman 风格接口调试器（自带后端代理）、路由 + ACL 总览、配置查看；需登录，生产环境禁写 |
| 开发 UI 账号体系 | `moo:account:add` 管理开发者账号，与业务账号体系隔离 |
| 样例实体 Food | 完整演示生成产物与业务代码写法：枚举字段、ModelFilter 检索、软删全生命周期、ACL 动作授权 |

### 模块 3：JWT 认证体系（自建 User，双守卫）

零付费依赖的完整 JWT 认证实现，含生产化加固，是骨架的核心教学与交付内容。

| 功能点 | 介绍 |
|---|---|
| 自建最简 User 主体 | User 实现 `JWTSubject`，guard claim 动态跟随签发守卫；不依赖任何付费包即可完成认证教学 |
| admin / user 双守卫 | 后台与移动端各一个 JWT 守卫；token 内嵌 guard claim，**双向隔离**——后台 token 调移动端接口 401，反之亦然 |
| 三个自定义 JWT 中间件 | `JWTAssignGuard`（指派守卫不强制认证）、`JWTGuardAuth`（校验 token 的 guard claim）、`JWTAuthOrRefresh`（强制认证 + 近过期无感续签，新 token 经 authorization 响应头下发） |
| 手动登录（不用 attempt） | `Hash::check()` 校验密码并支持自定义前置检查（账号状态等），`Auth::login()` 签发；响应含 user/token/expires_in |
| 认证全链路接口 | 登录 `authenticate`、当前用户 `me/info`、主动刷新 `refresh`、登出 `logout`，后台与移动端各一套 |
| persistent_claims 加固 | `config/jwt.php` 固化 `persistent_claims=['guard']`，杜绝续签丢 guard claim 导致的偶发 401（生产实测踩坑回灌） |
| 黑名单 90 秒宽限 | 续签瞬间旧 token 进黑名单前留 90 秒宽限期，并发在途请求不被误杀 |
| 滑动续期 | `refresh_iat=true` + ttl 2880 分钟 / refresh_ttl 20160 分钟，活跃用户无感长登录 |
| refresh 防孤儿 token | `/refresh` 路由不挂 `jwt.auth.refresh` 中间件，避免中间件与控制器各续签一次派生两个有效 token |
| 单设备语义（移动端） | 移动端主动刷新调用 `JWTGuard::refresh($forceForever, $resetClaims)` 时传 `(true, false)`——`$forceForever=true` 让旧 token **立即**进黑名单（跳过 90 秒宽限），实现单设备登录语义（见 `app/Api/Controllers/AuthController.php`） |
| CORS 配套 | `config/cors.php` 暴露 `Authorization` 响应头，跨域前端才能读到无感续签的新 token |
| 接口限流 | 后台 300 次/分钟限流，另有登录接口专用限流（`AppServiceProvider`），防暴力撞库与异常流量 |

### 模块 4：ACL 动作级授权

控制器动作粒度的权限引擎，host 定义契约、包与自建代码共同消费。

| 功能点 | 介绍 |
|---|---|
| Gate `acl_authentication` 契约 | 定义在 host 的 `AuthServiceProvider`，**多态设计**——自建 User 与 moo-system Personnel 通吃，主体切换不动授权代码 |
| 动作自动鉴权 | 基类 Controller 的 `callAction()` 先 `boot()` 再 `checkAuthorization()` 自动鉴权，业务代码零侵入。ACL key 分两步生成（实现见 moo-scaffold `Foundation/Controller.php`）：① 把 `Class::method` 各段 snake 化拼成明文 key，如 `App\Admin\Controllers\Food\FoodController::index` → `admin-food-food-index`；② 再 `substr(md5(明文key), 8, 16)` 截成 16 位十六进制串，即数据库里实际存的 key |
| 权限复用（transform_methods） | 一个 action 可声明复用另一个 action 的权限，避免权限点爆炸 |
| User.actions 最小实现 | 自建 User 用一个 JSON 列存权限 key 数组，`is_root` 字面量为超级权限；演示契约的最小可行实现 |
| 角色制接管（第 7 章） | 接入 moo-system 后无缝切换为 角色-授权 体系，含个人中心 8 个动作的白名单兜底（防零授权角色把自己锁死） |
| 401→403→200 完整闭环 | 教程以 Food 实体演示无 token、有 token 无权限、授权后三种状态的完整验证路径 |

### 模块 5：移动端分片（Api/）

面向 App / 小程序的独立 HTTP 分片，与后台完全隔离。

| 功能点 | 介绍 |
|---|---|
| 独立路由空间 | 前缀 `app`、中间件组 `client`，与后台 `api/admin` 物理隔离 |
| 永久使用自建 User | 移动端 user 守卫主体**永久**为自建 User（email 登录），即使第 7 章后台切换 Personnel 也不受影响——与作者生产项目的真实模式一致 |
| 移动端认证四接口 | authenticate / me/info / refresh / logout，语义与后台一致、守卫与续签策略独立 |
| 生成器插入标记 | 路由文件保留 `:insert_code_here:do_not_delete` 标记，moo-scaffold 可直接向移动端分片生成业务接口 |

### 模块 6：系统管理模块（moo-system，进阶/可选）

商业包提供的开箱后台模块，骨架完成全部 host 契约实现与真机联调；不装此包前六章功能完全自洽。

| 功能点 | 介绍 |
|---|---|
| 部门管理 Department | 嵌套集树（nestedset），支持 `department/{id}/move` 拖拽调序 |
| 岗位管理 Position | 岗位字典维护 |
| 人员管理 Personnel | 第 7 章起的后台 JWT 认证主体（实现 JWTSubject），手机号登录 |
| 角色与授权 Role / Authorization | 角色制 ACL 编辑器，支持权限矩阵 Excel 导出 |
| 通知机器人 NotifyRobot | 钉钉/企微类机器人通知通道 |
| 登录管理 LoginManagement | 登录态与设备管理 |
| 操作日志 OperationLog | 中间件式操作审计，经 Job 异步落库——**依赖队列被消费**。本仓库 `engine/.env.example` 预设 `QUEUE_CONNECTION=sync` 所以开箱可见日志；若从零自装（Laravel 默认 database 队列）而不跑 worker，会出现"日志永远 0 条且无报错"（踩坑速查 #21） |
| 个人中心 me* | 个人资料、改密等 8 个白名单动作 |
| host 契约实现 | `MediaSynchronous` / `Notification` / `BaseActionTrait` / `UploaderTrait` / `SendBlessMessage` / `toLabelValue()` 全局函数等契约在骨架中给出标准实现 |
| 自检命令 | `php artisan moo-system check` 6 项 host 集成自检全过 |
| 初始数据 Seeder | 包本身无 seeder，骨架补齐 角色→部门→岗位→人员 全套（含嵌套集树正确构建），`migrate --seed` 即得可登录管理员 |
| 主体平滑切换 | 后台守卫 User→Personnel 仅改 `auth.php` 一行 + 一个 AuthController，验证了架构的可替换性 |

### 模块 7：文档与教学体系（docs/）

原则「每一步都写进教程」的产物，本身即是交付物。

| 功能点 | 介绍 |
|---|---|
| 九章从零教程 | 安装 Laravel → 接入 scaffold → JWT 自建用户 → JWT 生产化 → ACL 闭环 → 移动端分片 → moo-system → 部署上线 → 增量开发工作流，每章照实记录命令与真机结果 |
| 零依赖网页引导器 | `docs/index.html` 单文件（内联全部 CSS/JS），支持分步模式、进度记忆、代码一键复制，`php -S` 即可启动 |
| 27 条踩坑速查表 | `docs/README.md`「踩过的坑速查」，现象 → 原因 → 解决 → 所在章节，全部来自真实搭建与生产回灌（JWT 丢 claim、孤儿 token、nestedset 静默、枚举比较死代码等） |
| 中间态代码内联 | 仓库代码为第 9 章最终态（含第 9 章增量演练的 Food 增量产物与测试），第 3~5 章的中间态代码（User 版 AuthController 等）以完整代码内联在章节文档中，保证任意章节可独立复现 |
| cleanroom 验证 | 主线七章经过"从零重做"的洁净环境终极验证，修复全部卡点后定稿；第 8~9 章为后续增补 |

### 模块 8：测试与质量保障

原则「用真机测试」的固化。

| 功能点 | 介绍 |
|---|---|
| Feature 测试（11 文件 44 方法） | `AuthTest`（Personnel 后台认证）、`ApiAuthTest`（User 移动端认证）、`FoodAclTest`（角色制 ACL）、`JwtAutoRefreshTest`（无感续签）、`RegressionTest`（回归集）、`SeederIntegrityTest`（种子数据完整性）、`FoodIncrementalTest` / `ApiFoodTest`（第 9 章增量产物）、`MonitorTest`（监控采集，第 1.7 节）、`UploadTest`（头像/附件上传端点）、`ExampleTest`；`php artisan test` 45 passed 全绿 |
| 跨进程行为模拟 | 测试基类 `tests/TestCase.php` 的 `freshJwtProcess()` 用 `forgetInstance` 重置整条 jwt 服务链单例（`tymon.jwt.*` + `auth.driver`），使同进程测试能复现真实跨进程的续签丢 claim 问题 |
| MCP 真机验证流程 | 开发约定以浏览器 MCP 驱动 `/scaffold` 接口调试器 + curl + 数据库查询对活服务验证，而非仅靠单测 |
| 代码规范 | Laravel Pint 统一格式化 |

### 防回退清单（骨架已领先生产项目的框架卫生高地）

以下 7 项是三仓审查沉淀出的、骨架**反超**存量生产项目的框架卫生点。做「骨架 ↔ 生产双向回灌」时
只能生产向骨架看齐，严禁因「生产项目没这么写」把骨架改回去：

1. 中间件组注册在 `AppServiceProvider::boot()`（保证 console 内核可见性）；
2. `RateLimiter::for('login')` 登录专用防爆破限流；
3. `JWTGuardAuth` 校验过期 token 的 guard claim（防跨守卫续签越权）；
4. `OperationLog` 用 `REQUEST_TIME_FLOAT` 计时 + 响应内容 60000 字符截断；
5. `tests/TestCase.php` 的 JWT 跨进程测试脚手架 + `phpunit.xml` 显式 `JWT_SECRET`（迁 Pest 后完整保留）；
6. `config/jwt.php` 的 `(int)` 强转；
7. helpers「有消费者」最小化门槛（无调用点的工具函数不进）。

> 逐条背景见 `HANDOFF.md` §8。

## 五、交付现状、初始化与默认账号

十章全部搭建完成并经真机验证，仓库代码为第 9 章最终态；第 10 章是云端监控进阶文档，不改变代码最终态。

**从克隆到能登录**（完整步骤见根 `README.md`「快速开始」与 `HANDOFF.md` §2~3，此处为最简链路；前提：当前过渡期 composer 能读取三个 moo-* VCS 仓库，见第一节硬前置）：

```bash
cd engine/
composer install                  # 当前过渡期解析 moo-* VCS；目标状态开源包走 Packagist、moo-system 走 VCS
cp .env.example .env              # 已预设 QUEUE_CONNECTION=sync 等
php artisan key:generate
php artisan jwt:secret --force
# 建库 moo_skeleton 后：
php artisan migrate --seed        # 产出下表前两个账号（UserSeeder / PersonnelSeeder）
php artisan moo:account:add <用户名> --password=<密码> --role=admin   # /scaffold 登录账号，seed 不创建

# 启动（多 worker 为接口调试器代理所必需）；开发 UI 在 http://127.0.0.1:8088/scaffold
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
```

| 账号 | 凭据 | 用途 |
|---|---|---|
| 自建用户 | `admin@example.com` / `password` | 第 3~6 章后台 + 永久移动端（`migrate --seed` 产出） |
| Personnel 管理员 | `13800000000` / `admin888` | 第 7 章起的后台（`migrate --seed` 产出） |
| Scaffold 开发 UI | 自定 | `http://127.0.0.1:8088/scaffold` 登录。账号存于 `engine/scaffold/accounts.yaml`——该文件已 gitignore，**新克隆后不存在任何账号**，必须用上面的 `moo:account:add` 自行创建 |

## 六、预期收益

- **新项目启动成本**：从"按生产仓库考古搭建底座（数天）"降为"克隆骨架 + 改库名（小时级）"；
- **新人培养**：九章教程 + 引导器使新人可独立完成从空目录到带认证授权后端的全过程，建立与团队生产代码一致的心智模型；
- **质量基线**：27 条生产级踩坑以代码形式固化，新项目天然规避；双向回灌机制（骨架 ↔ 生产仓库）持续提升存量项目质量；
- **商业弹性**：前六章可独立开源引流，第 7 章商业包形成付费转化路径。
