# moo-engine-skeleton 从 0 开始搭建教程

这是一套**面向新手**的后端开发骨架教程：从一个空目录开始，一步步搭出一个带
**代码生成器（moo-scaffold）**、**系统管理模块（moo-system：部门 / 岗位 / 人员 / 角色 / 授权）**
和 **JWT 登录认证** 的 Laravel 12 后端骨架。

## 第 0 步：把仓库拿到本地

```bash
git clone <本仓库地址> moo-engine-skeleton   # 地址以作者提供为准
cd moo-engine-skeleton
```

教程有两种走法，定义在[仓库根 README 的「快速开始」](../README.md)：

- **方式 A：直接用本仓库**——`engine/` 已是最终成品，装好依赖即可运行（需 PHP 8.3；且最终态接入了商业包 moo-system，拿不到其源码时 `composer install` 会失败）；
- **方式 B：从 0 跟教程搭**——从第 1 章起一步步自己搭，**推荐新手**。

后文出现的「方式 A / 方式 B」均指这里。

> 🎯 **推荐的阅读方式**：本目录自带一个零依赖的网页引导器（`docs/index.html`，
> 单文件含全部 CSS/JS），支持分步模式、进度记忆、代码一键复制：
> ```bash
> cd docs && php -S 127.0.0.1:9999     # 或 python3 -m http.server 9999
> # 浏览器打开 http://127.0.0.1:9999
> ```
> ![网页引导器](./images/00-tutorial-guide.png)

> 本教程遵循两条原则：
> 1. **每一步都有操作记录**，命令和结果都照实写下来，方便照着做；
> 2. **每一步都用真机测试**（启动真实服务、用浏览器/接口真实请求验证），而不是只写代码。

## 环境要求

| 软件 | 版本（本教程实测） | 说明 |
|---|---|---|
| PHP | 8.3.31 | Laravel 12 要求 `^8.2`；但仓库 lock 的 jwt-auth 2.9.x 要求 `^8.3`，直接用本仓库（**方式 A**，见第 0 步）必须 8.3 |
| Composer | 2.9.5 | PHP 包管理器 |
| Node / npm | Node 26 / npm 11 | **整行可选**：只有 `engine/` 的前端资源构建（vite/tailwind）用到，只跑后端接口教程可以完全不装 |
| MariaDB / MySQL | MariaDB 12 或 MySQL 8（实测均可） | 数据库；本机 `127.0.0.1:3306` |
| Git | 任意较新版本 | **无需 git-lfs**（本仓库不使用 LFS） |
| moo-scaffold 源码 | dev-master | **第 2 章起必需**。开源包（MIT，规划发 Packagist），但**尚未正式发布——目前唯一获取途径是联系作者要源码**，缺它 `composer install` 直接失败。克隆到与本仓库同级目录，第 2 章用相对路径引用；moo-system 为第 7 章的**商业包**（可选，同样联系作者获取） |

动手前先自检一遍（PHP 版本不对的话，先安装/切换到 8.3 再继续）：

```bash
php -v              # 方式 A 必须 8.3
composer -V
mysql --version
node -v && npm -v   # 可选，不做前端构建可跳过
```

数据库连接：教程统一用示例凭据 **`root` / `7777`**（换成你自己的，命令里同步替换），库名 **`moo_skeleton`**。建库命令（与[根 README 方式 B](../README.md) 相同）：

```bash
mysql -uroot -p7777 -h127.0.0.1 -e \
  "CREATE DATABASE IF NOT EXISTS moo_skeleton CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

## 目录结构约定（重要）

Laravel 应用放在仓库的 **`engine/`** 子目录里，仓库根目录只放文档（部署相关内容在[第 8 章](./08-部署上线.md)文档里，根目录没有部署脚本）。
这是作者所有项目统一的目录约定。
moo-* 包用 path 仓库引用，相对路径统一写 `../../moo-scaffold`——注意基准是 `composer.json` 所在的 **`engine/` 子目录**（不是仓库根），所以「同级目录」要往上两层 `../../`，而不是 `../`。

```
moo-engine-skeleton/              # ← 与 moo-scaffold、moo-system 克隆在同级目录
├── README.md  CLAUDE.md          # 说明文档（根目录另有 HANDOFF.md、overview.md 等开发随档）
├── docs/                         # 就是你正在看的这套教程
└── engine/                       # ← Laravel 12 应用本体（composer 命令都在这里执行）
```

## 章节

| 章节 | 内容 | 定位 |
|---|---|---|
| [第 1 章 安装 Laravel 12](./01-安装-laravel.md) | 创建项目、连接 MariaDB、建库、真机访问 | 基础 |
| [第 2 章 安装 moo-scaffold](./02-安装-moo-scaffold.md) | 开源代码生成器接入（dev=路径 / prod=VCS）、设计 `foods` 表、一键生成业务代码、两种方式调接口 | 基础 |
| [第 3 章 JWT 登录认证（自建用户）](./03-JWT-登录认证-自建用户.md) | **零付费依赖**：最简 User 实现 JWTSubject、双守卫规划、三中间件、登录/me/刷新/登出全链路 | 核心 |
| [第 4 章 JWT 加固与生产化](./04-JWT-加固与生产化.md) | 生产踩坑回灌：persistent_claims、黑名单宽限、滑动续期、CORS、限流、生产 composer、第一批接口测试 | 核心 |
| [第 5 章 给 Food 上 JWT 与 ACL](./05-给-Food-上-JWT-与-ACL.md) | 动作级授权完整闭环：Gate 契约、401→403→授权→200（User actions 列最小实现） | 核心 |
| [第 6 章 移动端分片与 user 守卫](./06-移动端分片与-user-守卫.md) | 启用 Api/ 分片：双向守卫隔离、单设备 refresh 语义 | 核心 |
| [第 7 章 安装 moo-system](./07-安装-moo-system.md) | **进阶/商业包（可选）**：host 契约、后台主体 User→Personnel 切换、角色授权、操作日志、调试器联调 | 进阶 |
| [第 8 章 部署上线](./08-部署上线.md) | composer 双轨部署、Redis（雪花/黑名单）、nginx、supervisor、清缓存致 token 复活的坑 | 可选 |
| [第 9 章 日常增量开发：改表与加接口](./09-增量开发工作流.md) | 绿地之后的真实日常：加字段（增量迁移）、「自动覆盖 vs 手动补」边界、`moo:adder` 自定义 action、ACL/文档/测试同步、移动端分片第一个只读接口、专属 Resource 链式字段控制 | 进阶 |

> **包定位**：moo-scaffold 开源（MIT）但**尚未发布到 Packagist**，正式发布前需联系作者获取源码；moo-system 是商业包（获取方式同样联系作者）。
> 第 1~6 章不依赖任何**付费**包，装不装第 7 章，前六章的骨架都是完整可用的——但前提是已拿到 moo-scaffold 源码（第 2 章起必需，见上方环境要求表），「开源」不等于现在就能从 Packagist 装到。

## 踩过的坑速查

> 用法：**遇到报错时回来查**，不必现在逐条看懂——表里的术语（「再生成区 / 真相源」「分片」「`iResource` 宏」等）都在「章节」列对应的章里讲解，踩到坑时跳回去对照即可。

| # | 现象 | 原因 / 解决 | 章节 |
|---|---|---|---|
| 1 | 生成 Model 报 `EloquentFilter\Filterable not found` | 装 `tucker-eric/eloquentfilter` + `godruoyi/php-snowflake` | 2 |
| 2 | 报 `BaseActionTrait not found` | `moo:free` 不建它，跑一次 `php artisan moo:controller Food -f` | 2 |
| 3 | `moo:free` 里 `moo:api` 提示 No routes matched | 路由刚插入、当前进程没刷新，单独补 `moo:api admin Food` | 2 |
| 4 | 调试器代理一直转圈 | 单线程 serve 自我代理死锁，用 `PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload` 启动 | 2 |
| 5 | 装 moo-system 后 artisan 报 `Attribute [iResource] does not exist` | `iResource` 宏要注册在 `AppServiceProvider::register()` | 7 |
| 6 | 调部门列表报 `undefined function toLabelValue()` | 补 `app/Helpers/helpers.php` 并 `composer` files 自动加载 | 7 |
| 7 | `moo-system check` 的中间件组那项总 FAIL | 中间件组要注册到 router（provider boot），否则 console 看不到 | 7 |
| 8 | 调试器里带了 token 仍 401 | Authorization 值要加 `Bearer ` 前缀 | 7 |
| 9 | seed 后部门树 `_lft/_rgt` 错乱 | `DatabaseSeeder` 别用 `WithoutModelEvents`，否则静默 nestedset 事件 | 7 |
| 10 | token 续签后再请求偶发 401 `Guard Unverified` | jwt-auth 2.8.x 续签会丢自定义 claim（生产环境踩过的真坑）；**无论装的是 2.8.x 还是 2.9.x**，`config/jwt.php` 的 `persistent_claims` 都必须列上 `'guard'`——契约保证靠它，2.9.x 只是内部实现碰巧保留（本仓库 `engine/config/jwt.php` 已是正确示范）。自查版本：`composer show php-open-source-saver/jwt-auth`（本仓库约束 `^2.8`，lock 实锁 2.9.2） | 4 |
| 11 | 页面并发请求时偶发 401（刚续签完） | 旧 token 续签后立刻进黑名单，同批在途请求被拒；`blacklist_grace_period` 设 90 秒宽限 | 4 |
| 12 | 前端跨域时拿不到续签的新 token | 新 token 在 `authorization` 响应头里，CORS 默认不暴露；发布 `config/cors.php` 设 `exposed_headers=['Authorization']` | 4 |
| 13 | 操作日志中间件报 `Undefined constant "LARAVEL_START"` | Laravel 12 入口不再定义它（老项目抄来的代码会炸），改用 `$request->server('REQUEST_TIME_FLOAT')` | 7 |
| 14 | Feature 测试里 refresh 永远"测不出"丢 claim | 同进程下 payload 工厂单例残留登录时的 claim；测试里用 `engine/tests/TestCase.php` 的 `freshJwtProcess()` 重置 jwt 服务链单例，模拟真实跨进程 | 4 |
| 15 | 开了 ACL 后管理员自己也 403 | 雪花主键下没有 id=1 的天然 root；给「系统管理员」角色授 `is_root` 字面量兜底（RoleSeeder 已带） | 7 |
| 16 | 带 token 调接口报 422 误以为 ACL 没生效 | FormRequest 校验先于控制器 boot() 的鉴权，参数不合法先 422；带齐合法参数才能看到 403 | 5 |
| 17 | user 守卫发的 token 过不了 `jwt.guard.auth:user` | moo-system 旧版 `getJWTCustomClaims()` 硬编码 guard=admin（新版已动态化）；用旧版包给非 admin 守卫签发时要 `claims(['guard'=>...])` 内联覆盖 | 7 |
| 18 | 过期 token 调 `/refresh` 后冒出两个有效新 token | `/refresh` 路由不能挂 `jwt.auth.refresh`——中间件和控制器各续签一次，响应头那个成孤儿 token；单独挂 `jwt.guard.auth` 即可 | 4 |
| 19 | 账号状态检查写了却不生效 | 枚举不进 `$casts`、字段是裸 int，`=== AccountStatus::FORBIDDEN`（enum 实例）永远 false，必须 `->value`（生产项目里就出过这种静默失效的死代码） | 7 |
| 20 | 开 ACL 后零授权角色连个人中心都 403 | `config/actions.php` 白名单要放行 moo-system AdminController 的 8 个个人中心动作，否则自己锁死自己 | 7 |
| 21 | 操作日志表永远 0 条、也无报错 | `.env` 默认 `QUEUE_CONNECTION=database`，Job 堆在 `jobs` 表没人消费；改 `sync`（或起 worker），且改 `.env` 后要连 `php -S` 的 worker 一起杀掉重启。注：本仓库 `engine/.env.example` 已预设 `sync`，此坑主要是方式 B 从零自装时 Laravel 默认 `.env` 才会踩 | 7 |
| 22 | 部署清缓存后，已登出的 token 又能用了 | `cache:clear`/`optimize:clear` 会清空 Redis 里的 JWT 黑名单，已作废 token 全部"复活"；部署脚本只用 `optimize`，必要时换 `JWT_SECRET` 强制全员重登 | 8 |
| 23 | 手工改过的 `lang/en/model.php` 枚举标签被 `moo:i18n` 回退 | lang 是再生成区、yaml 才是真相源；英文标签写进 yaml 枚举定义，再 `moo:fresh` + `moo:i18n` | 9 |
| 24 | `moo:adder admin Food` 直接崩：找不到 `FoodFoodController.php` | folder 参数与控制器文件名直接拼接，必须带尾斜杠写成 `Food/` | 9 |
| 25 | 重跑 `moo:auth` 后零授权角色又被锁在门外（坑 #20 复发） | `config/actions.php` 是再生成区、整文件重写：手动放行的个人中心 8 个 key 会被冲掉（moo:auth 只自动放行「无 @acl」的 action）；重跑后要把 8 个 key 合并回 whitelist（FoodAclTest 有守护断言） | 9 |
| 26 | `moo:free api` 生成的控制器第一次请求就 500：`Class "FoodResource" not found` | **已在 moo-scaffold（dev-master）上游修复，仅作历史记录**：现在分片未声明 resource 时生成器自动回退 `BaseResource` 且 stub 自带 `use` 导入，新版本不会再遇到这个 500。当时的成因：api 分片 stub 按 `{Entity}Resource` 引用资源类（且无 `use`），而 `moo:resource` 不为 api 分片生成它 | 9 |
| 27 | `moo:resource Food` 报 SUCCESS 却一个文件不生成 | 生成器只为 yaml `controller.resource` 声明过的分片产文件（坑 #26 的另一面），Food.yaml 只写了 `controller.app` → resource 数组为空 → 0 个目标也算"成功"；yaml 补 `resource: ['admin']` + `moo:fresh` 后再生成 | 9 |
