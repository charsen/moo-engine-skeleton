# moo-engine-skeleton

一套 **Laravel 12 后端开发骨架**，把作者多年沉淀的业务代码结构提炼成一个「从 0 开始」的起点。
配套一份可开源的新手教程：从空目录出发，一步步搭出带**代码生成器**、**系统管理模块**和
**JWT 登录认证**的可运行后端——并且每一步都经过真机测试。

> 它与作者多个生产项目共用同一套 moo-* 包：开发期这些包不从 Packagist 安装，而是以
> composer **path 仓库**的方式引用本仓库**同级目录**下的包源码（详见下文「配套包」）。
> 本仓库就是按这套包搭出来的「骨架版」起点。

---

## ✨ 这套骨架包含什么

从零搭到一个带 JWT 认证与动作级授权的 Laravel 12 后端，覆盖：

1. **Laravel 12** 应用（放在 `engine/` 子目录），连接本机 MariaDB。
2. **moo-scaffold** 代码生成器：用一份 YAML 设计数据表 → 一键生成 Model / Request /
   Controller / 路由 / 迁移，并有内置的「数据库设计器 + 接口调试器」。
3. **JWT 登录认证（自建最简用户，零付费依赖）**：登录签发、守卫校验、近过期自动续签、
   生产级加固、动作级 ACL 授权、移动端双守卫隔离。
4. **moo-system 系统管理模块（进阶/商业包，可选）**：部门 / 岗位 / 人员 / 角色 / 授权 /
   登录管理 / 操作日志，最后一章接入——后台主体一键从自建用户切换为 Personnel。

## 📦 配套包

作者另外开发的插件包。`engine/composer.json` 的 `repositories` 用 **path 仓库**指向
`../../moo-scaffold` 与 `../../moo-system`——即这两个包仓库**必须以固定目录名 clone 到
本仓库的同级目录**（同 [`HANDOFF.md`](./HANDOFF.md) 第 2 节），像这样：

```
wwwroot/                       # 任意父目录
├── moo-engine-skeleton/       # 本仓库
├── moo-scaffold/              # git clone git@gitee.com:charsen/moo-scaffold.git
├── moo-system/                # git clone git@gitee.com:charsen/moo-system.git（商业包，需授权）
└── moo-scaffold-cloud/        # 按需
```

| 包 | 定位 | 是否必装 | 作用 |
|---|---|---|---|
| `moo-scaffold` | **开源（MIT，规划发布到 Packagist，目前尚未发布——只能 clone 到同级目录走 path 安装）** | 必装（教程第 2 章接入） | 基础代码生成脚手架，含可视化数据库设计、接口调试 |
| `moo-system` | **进阶/商业包**（教程第 7 章可选接入） | 可选 | 系统管理业务模块（部门、岗位、人员、角色等） |
| `moo-scaffold-cloud` | 配套云服务 | 可选（教程九章不覆盖其搭建） | 云端的异常 / 慢 SQL / todos 管理平台，支持多项目 |

> 关于 `moo-scaffold-cloud`：它是单独部署的云端平台，不是 composer 包，本教程不讲它的搭建。
> 但 engine 经由 moo-scaffold 已自带三条对接命令：`moo:cloud:push`（把本地 runtime 错误 /
> 慢 SQL 增量推送上云）、`moo:cloud:mcp`（以 MCP server 形式把云端数据暴露给 AI）、
> `moo:cloud:adopt`（云端化收尾）。接入提示见第 8 章的慢 SQL 一节及 `HANDOFF.md`；
> 不接云端不影响本教程任何一步。

## 🧰 环境要求

| 软件 | 版本（实测） | 说明 |
|---|---|---|
| PHP | 8.3 | Laravel 12 本身要求 `^8.2`，但本仓库 composer.lock 按 8.3 解析（jwt-auth 2.9.2 要求 `^8.3`），请装 8.3 |
| Composer | 2.9 | |
| Node | 26 | **整行可选**：只有 `engine/` 的前端资源构建（vite / tailwind，`npm install && npm run build`）才用到；本页快速开始 A/B 与九章后端教程全程不执行任何 npm 命令，可以不装 |
| npm | 11 | 同上 |
| MariaDB / MySQL | MariaDB 12 | 本机 `127.0.0.1:3306` |

## 🚀 快速开始

> 先理清教程的「章」与「主线」：教程共 **9 章**——**第 1~7 章是主线**（其中**第 1~6 章
> 零商业依赖、全程可跑**，第 7 章接入商业包 moo-system），**第 8 章是部署指引**，
> **第 9 章是增量开发演练**。下文「主线七章 + 部署指引 + 第 9 章」即指这 9 章。

**方式 A：直接用本仓库**（最终态，即 9 章全部做完后的成品）：

> ⚠️ **前置**：
> ① 需 **PHP 8.3**（composer.lock 按 8.3 解析）；
> ② 先把 **moo-scaffold** 和 **moo-system** clone 到本仓库**同级目录**（见上文「配套包」的
>    目录图）——moo-scaffold 尚未发布到 Packagist，不放同级 `composer install` 连开源包
>    也取不到；
> ③ 仓库最终态已接入**商业包 moo-system**（第 7 章）——没有它的源码/授权时
>    `composer install` 会失败，请走方式 B 从第 1 章跟做，或联系作者获取授权；
> ④ `cp .env.example .env` 后记得把 `DB_PASSWORD`（预填的是教程示例值 `7777`）改成
>    你本机 MariaDB 的真实密码。

```bash
cd engine
composer install                                   # 含同级目录的 moo-* path 包
cp .env.example .env && php artisan key:generate   # .env 预填 MariaDB root/7777 + moo_skeleton，按本机改
php artisan jwt:secret --force

# 建库（示例密码 7777，换成你自己的）
mysql -uroot -p7777 -h127.0.0.1 -e \
  "CREATE DATABASE IF NOT EXISTS moo_skeleton CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan vendor:publish --provider="Mooeen\Scaffold\ScaffoldProvider" --tag=public --force
php artisan migrate --seed                          # 自建用户 + 角色/部门树/岗位/管理员
php artisan moo:account:add <用户名> --password=<密码> --role=admin   # scaffold 调试台账号（自定，seed 不创建）
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
php artisan test                                    # 41 passed（用 sqlite 内存库，见下方说明）
```

> 💡 **关于 `php artisan test`**：phpunit.xml 把测试数据库定为 **sqlite `:memory:`**，
> 完全不碰本机 MariaDB——所以 MariaDB 没装/没建库测试照样全绿，反过来测试通过也
> **不代表** `.env` 的数据库配好了，两者别互相误判。仓库还自带 GitHub Actions CI
> （`.github/workflows/tests.yml`），push 后自动跑同一套测试（发布到 Packagist 前，
> CI 按 `composer.production.json` 走 VCS 依赖安装）。

**方式 B：从 0 跟教程搭**（推荐新手，带截图的完整教程见 [`docs/`](./docs/README.md)）：

```bash
# 1. 安装 Laravel 12 到 engine/ 子目录
composer create-project "laravel/laravel:^12.0" engine
cd engine

# 2. 建库（本机示例账号 root / 7777）
mysql -uroot -p7777 -h127.0.0.1 -e \
  "CREATE DATABASE IF NOT EXISTS moo_skeleton CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. 配 .env 的数据库（DB_CONNECTION=mysql / DB_DATABASE=moo_skeleton / DB_USERNAME=root / DB_PASSWORD=7777）

# 4. 接入 moo-scaffold（开发用 path、生产用 vcs，完整讲解见 docs 第 2 章；moo-system 见第 7 章）
#    先把 moo-scaffold clone 到本仓库同级目录，再往 engine/composer.json 里加：
#      "repositories": { "scaffold": { "type": "path", "url": "../../moo-scaffold" } }
#      "require" 里加 "charsen/moo-scaffold": "dev-master as 3.999.0"
composer update --with-all-dependencies
php artisan vendor:publish --provider="Mooeen\Scaffold\ScaffoldProvider" --tag=public --force   # 发布 /scaffold 静态资源

# 5. 迁移 + seed + 调试台账号
php artisan migrate --seed      # 自建用户；装了 moo-system 还有角色/部门树/岗位/人员
php artisan moo:account:add <用户名> --password=<密码> --role=admin   # 没有它登不进 /scaffold

# 6. 启动（必须多 worker，原因见下方说明）
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
```

> 💡 **为什么必须多 worker**：scaffold 的接口调试器是「代理」模式——它收到你的调试请求后，
> 会从服务内部再向**同一个服务**发一次真实 HTTP 请求。`php artisan serve` 默认单 worker、
> 一次只能处理一个请求：外层请求占着唯一的 worker 等内层请求，内层请求又排不上队——互相
> 等待即死锁。`PHP_CLI_SERVER_WORKERS=4` 开多 worker 即可；换成 nginx + php-fpm、valet
> 等多进程环境天然没有这个问题，无需此变量。

启动后的入口（注意：**「后台」没有独立网页**，它是纯 API）：

- 应用首页：<http://127.0.0.1:8088>
- 代码生成 / 接口调试台：<http://127.0.0.1:8088/scaffold>（登录用 `moo:account:add` 创建的账号）
- 后台管理 API：`http://127.0.0.1:8088/api/admin`（登录接口 `POST /api/admin/authenticate`；
  日常在 `/scaffold` 的接口调试器里带账号调它，不是打开网页登录）
- 移动端 API：`http://127.0.0.1:8088/app`

## 📂 目录结构

Laravel 应用放在 **`engine/`**（与作者其它项目的目录约定一致），仓库根目录是文档与工程配置：

```
moo-engine-skeleton/
├── README.md                # 本文，仓库入口
├── HANDOFF.md               # 换机/交接速查（同级目录约定 + 初始化清单）
├── overview.md              # 立项说明
├── CLAUDE.md                # AI 协作约定
├── .github/workflows/       # CI：GitHub Actions 自动跑 php artisan test
├── .vscode/                 # 编辑器约定
├── docs/                    # 从 0 开始的新手教程（含截图）
└── engine/                  # ← Laravel 12 应用本体
```

（本机开发可能还有一个 `CLAUDE.local.md`，已被 gitignore，clone 不会带下来。）

## 📖 从 0 开始教程

> 推荐用**网页引导器**跟做（分步模式 + 进度记忆 + 代码一键复制，零依赖单文件）：
> `cd docs && php -S 127.0.0.1:9999`，浏览器打开 http://127.0.0.1:9999
>
> 仓库公开后可一键上线为网页版：Gitee 仓库 →「服务」→「Gitee Pages」→
> 部署目录选 `docs/` → 访问 `https://<你>.gitee.io/moo-engine-skeleton/`
> （引导器全部用相对路径，子路径部署开箱即用）。

| 章节 | 内容 |
|---|---|
| [第 1 章 安装 Laravel 12](./docs/01-安装-laravel.md) | 建项目、连 MariaDB、建库、真机访问 |
| [第 2 章 安装 moo-scaffold](./docs/02-安装-moo-scaffold.md) | 开源代码生成器接入、设计 `foods` 表、一键生成业务代码、两种方式调接口 |
| [第 3 章 JWT 登录认证（自建用户）](./docs/03-JWT-登录认证-自建用户.md) | 零付费依赖：最简 User 实现 JWTSubject、双守卫、三中间件、登录全链路 |
| [第 4 章 JWT 加固与生产化](./docs/04-JWT-加固与生产化.md) | 生产踩坑回灌：persistent_claims、黑名单宽限、滑动续期、CORS、限流、生产 composer、接口测试 |
| [第 5 章 给 Food 上 JWT 与 ACL](./docs/05-给-Food-上-JWT-与-ACL.md) | 动作级授权完整闭环：401→403→授权→200（User actions 最小实现） |
| [第 6 章 移动端分片与 user 守卫](./docs/06-移动端分片与-user-守卫.md) | 守卫隔离、单设备 refresh |
| [第 7 章 安装 moo-system（进阶）](./docs/07-安装-moo-system.md) | 完整系统管理：host 契约、主体切换 User→Personnel、角色授权、操作日志、调试器联调 |
| [第 8 章 部署上线（可选）](./docs/08-部署上线.md) | composer 双轨部署、Redis、nginx、supervisor、清缓存坑 |
| [第 9 章 日常增量开发：改表与加接口](./docs/09-增量开发工作流.md) | 加字段（增量迁移）、「自动覆盖 vs 手动补」边界、`moo:adder` 自定义 action、ACL/文档/测试同步、专属 Resource 链式字段控制 |

教程目录页还附了一张**「踩过的坑」速查表**（27 条新手高频问题）：[docs/README.md](./docs/README.md)。

## 🔑 默认账号

| 用途 | 账号 | 密码 | 创建方式 |
|---|---|---|---|
| 自建用户（User 守卫）：第 3~6 章作后台账号；第 7 章后台主体切到 Personnel 后它不再用于后台，但**一直是移动端**（`/app` 接口、user 守卫）的账号 | `admin@example.com` | `password` | `migrate --seed`（UserSeeder） |
| 后台管理员（Personnel 守卫，第 7 章起，走 `/api/admin` 接口） | `13800000000` | `admin888` | `migrate --seed`（PersonnelSeeder） |
| scaffold 调试台（<http://127.0.0.1:8088/scaffold> 的登录账号） | 自定 | 自定 | **seed 不创建**，需自行执行 `php artisan moo:account:add <用户名> --password=<密码> --role=admin`（快速开始 A/B 均已含此步） |

> 后台账号的使用方式：后台是纯 API（前缀 `/api/admin`，无网页界面），拿着上表账号在
> `/scaffold` 接口调试器里调 `POST /api/admin/authenticate` 登录、再调业务接口。

## 🧭 设计原则

1. **每一步都有操作记录**，最终沉淀成可开源的新手教程。
2. **每一步都真机测试**——不只是写代码，而是跑起来、用浏览器/接口真实请求验证。
   （作者成稿时由 AI 经 MCP——Model Context Protocol，一种让 AI 驱动浏览器、调用接口的
   协议——自动完成这些验证；读者跟做时手动点一遍即可，不需要了解 MCP。）

## 🔗 参考项目

包自身的细节文档在**同级目录的另外两个仓库**里（不在本仓库内，需按「配套包」一节 clone）：
`../moo-scaffold/docs/guide/` 与 `../moo-system/docs/INTEGRATION.md`。

## 🎯 目标

给新手一套可用的教程，从 0 开始、零付费依赖地搭出带 JWT + ACL 的 Laravel 12 骨架；
进阶者再用 moo-system 一键升级成完整系统管理后端。
