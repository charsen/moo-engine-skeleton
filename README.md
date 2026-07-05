# moo-engine-skeleton

**Laravel 12 后端开发骨架 + 配套新手教程**

从零开始，一步步搭建一个带**代码生成器**、**JWT 登录认证**和**完整系统管理**的 Laravel 后端——并且每一步都能真机跑通，不是纸上谈兵。

## 这是什么

这个仓库有两部分：

1. **一个可运行的 Laravel 12 骨架项目**（在 `engine/` 目录）  
   已接入 moo-scaffold 代码生成器 + JWT 认证 + moo-system 系统管理模块，是个「开箱即用」的起点。

2. **一套完整的新手教程**（在 `docs/` 目录，带截图）  
   从空目录出发，教你一步步搭出这个骨架。每一步都经过真机测试，跟着做就能跑起来。

## 适合谁

- **新手**：第一次用 Laravel 搭后端，不知道从哪开始
- **想快速上手代码生成的**：看过 moo-scaffold 文档，但希望有个完整示例项目
- **需要系统管理功能的**：不想从头写部门 / 人员 / 权限，直接用现成模块

## 能学到什么

跟完教程（主线 7 章 + 部署指引 + 增量开发 + 云端进阶），你会掌握：

- Laravel 12 基础搭建（连 MariaDB、建库、配 .env）
- **运行时监控采集与云端推送**（moo-monitor-laravel + moo-scaffold-cloud，含 AI 辅助处理）
- moo-scaffold 代码生成器使用（YAML → Model / Controller / Migration）
- JWT 登录认证（自建用户，零付费依赖，含生产加固）
- 动作级授权（ACL，细到每个接口）
- 双守卫隔离（后台 admin + 移动端 user）
- 接入 moo-system 系统管理模块（部门 / 人员 / 角色）
- 部署上线（nginx + supervisor + Redis）
- 日常增量开发（改表 / 加接口 / 同步 ACL）

教程共 10 章，前 6 章零商业依赖、全程可跑；第 7 章接入商业包 moo-system（可选）；第 8 章部署指引；第 9 章增量开发演练；第 10 章云端监控进阶。

## 两种使用方式

### 方式 A：直接用现成骨架（适合急着上手的）

克隆本仓库，`engine/` 目录就是最终成品：

### 方式 B：从零跟教程搭（适合想深入学习的）

从空目录开始，一步步跟着 `docs/` 里的教程做：

```bash
# 第 1 章：安装 Laravel 12
composer create-project "laravel/laravel:^12.0" engine

# 第 2 章：接入 moo-scaffold 代码生成器
# （开源包，目标从 Packagist 安装）

# 第 3~6 章：JWT 认证 + ACL + 双守卫
# 第 7 章：接入 moo-system（可选）
# 第 8 章：部署上线
# 第 9 章：增量开发演练
```

教程入口：[docs/README.md](docs/README.md)（带「踩过的坑」速查表）

推荐用**网页引导器**跟做（分步模式 + 进度记忆）：

```bash
cd docs
php -S 127.0.0.1:9999
# 浏览器打开 http://127.0.0.1:9999
```

---

## 配套包（必读）

本骨架依赖作者的另外几个包。`moo-scaffold`、`moo-monitor-laravel` 是开源包，
目标通过 **Packagist** 直接安装；只有商业包 `moo-system` 必须通过 Composer **VCS 仓库**接入。
这些包都不走本地 path，也不需要 clone 到同级目录。

**访问前提**：

```bash
# 克隆本仓库
git clone git@gitee.com:charsen/moo-engine-skeleton.git

# 目标状态下，只有接入 moo-system（第 7 章商业包）时，才需要配置 Gitee SSH 仓库访问权：
# git@gitee.com:charsen/moo-system.git
```

| 包 | 定位 | 是否必装 | 说明 |
|---|---|---|---|
| `moo-scaffold` | 开源（MIT，发布到 Packagist） | **必装** | 代码生成器 + 开发后台（教程第 2 章接入） |
| `moo-system` | 进阶 / 商业包 | 可选 | 系统管理模块（部门 / 人员 / 角色，教程第 7 章接入） |
| `moo-monitor-laravel` | 开源（MIT） | 按需 | 监控采集 SDK（scaffold 3.9+ 自动依赖，单独用 Laravel 也可装） |

**为什么还要配置 VCS 仓库？**

开源包发布到 Packagist 且目标版本可解析后，不需要额外 `repositories` 配置。
`moo-system` 是商业包，不在 Packagist 公开分发，接入第 7 章时仍需要在
`composer.json` 的 `repositories` 里声明 `git@gitee.com:charsen/moo-system.git`。

> 当前过渡说明：本仓库终态需要 `moo-scaffold` 3.x 与 `moo-monitor-laravel` 0.1.x。
> 在 Packagist 目标版本完全可解析前，`engine/composer.json` 暂保留开源包的 VCS 仓库配置，
> 以保证教程和测试可运行。发布同步完成后即可删掉这两项，只保留 `moo-system` 的 VCS。

---

## 环境要求

| 软件 | 版本（实测） | 说明 |
|---|---|---|
| PHP | 8.2+ | Laravel 12 要求 `^8.2`；当前 lock 已按 PHP 8.2 可安装版本解析 |
| Composer | 2.9 | |
| Node | 26 | **整行可选**：只有 `engine/` 的前端资源构建（vite / tailwind，`npm install && npm run build`）才用到；本页快速开始 A/B 与九章后端教程全程不执行任何 npm 命令，可以不装 |
| npm | 11 | 同上 |
| MariaDB / MySQL | MariaDB 12 | 本机 `127.0.0.1:3306` |

## 🚀 快速开始

> 先理清教程的「章」与「主线」：教程共 **10 章**——**第 1~7 章是主线**（其中**第 1~6 章
> 零商业依赖、全程可跑**，第 7 章接入商业包 moo-system），**第 8 章是部署指引**，
> **第 9 章是增量开发演练**，**第 10 章是云端监控进阶**。下文「主线七章 + 部署指引 + 
> 第 9 章 + 第 10 章」即指这 10 章。

**方式 A：直接用本仓库**（最终态，即 9 章全部做完后的成品）：

> ⚠️ **前置**：
> ① 需 **PHP 8.2+**；
> ② 目标状态下开源包从 Packagist 安装；当前过渡期直接使用仓库最终态时，仍需确保 composer 能解析开源包；
>    同时还需配好 **moo-system** 的 Gitee SSH 仓库访问权；
> ③ 仓库最终态已接入**商业包 moo-system**（第 7 章）——没有它的授权时
>    `composer install` 会失败，请走方式 B 从第 1 章跟做，或联系作者获取授权；
> ④ `cp .env.example .env` 后记得把 `DB_PASSWORD`（预填的是教程示例值 `7777`）改成
>    你本机 MariaDB 的真实密码。

```bash
cd engine
composer install                                   # 目标状态：开源包走 Packagist；moo-system 走 Gitee VCS
cp .env.example .env && php artisan key:generate   # .env 预填 MariaDB root/7777 + moo_skeleton，按本机改
php artisan jwt:secret --force

# 建库（示例密码 7777，换成你自己的）
mysql -uroot -p7777 -h127.0.0.1 -e \
  "CREATE DATABASE IF NOT EXISTS moo_skeleton CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan vendor:publish --provider="Mooeen\Scaffold\ScaffoldProvider" --tag=public --force
php artisan migrate --seed                          # 自建用户 + 角色/部门树/岗位/管理员
php artisan moo:account:add <用户名> --password=<密码> --role=admin   # scaffold 调试台账号（自定，seed 不创建）
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
php artisan test                                    # 43 passed（用 sqlite 内存库，见下方说明）
```

> 💡 **关于 `php artisan test`**：phpunit.xml 把测试数据库定为 **sqlite `:memory:`**，
> 完全不碰本机 MariaDB——所以 MariaDB 没装/没建库测试照样全绿，反过来测试通过也
> **不代表** `.env` 的数据库配好了，两者别互相误判。仓库还自带 GitHub Actions CI
> （`.github/workflows/tests.yml`），push 后自动跑同一套测试。

**方式 B：从 0 跟教程搭**（推荐新手，带截图的完整教程见 [`docs/`](./docs/README.md)）：

```bash
# 1. 安装 Laravel 12 到 engine/ 子目录
composer create-project "laravel/laravel:^12.0" engine
cd engine

# 2. 建库（本机示例账号 root / 7777）
mysql -uroot -p7777 -h127.0.0.1 -e \
  "CREATE DATABASE IF NOT EXISTS moo_skeleton CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. 配 .env 的数据库（DB_CONNECTION=mysql / DB_DATABASE=moo_skeleton / DB_USERNAME=root / DB_PASSWORD=7777）

# 4. 接入 moo-scaffold（完整讲解见 docs 第 2 章；moo-system 见第 7 章）
#    Packagist 目标版本可解析后，直接 require：
composer require "charsen/moo-scaffold:^3.10" --with-all-dependencies
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
├── HANDOFF.md               # 换机/交接速查（Packagist + moo-system VCS + 初始化清单）
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
| [第 8 章 部署上线（可选）](./docs/08-部署上线.md) | Composer / Packagist 部署、Redis、nginx、supervisor、清缓存坑 |
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

包自身的细节文档在对应私有仓库里；有仓库权限后可查看
`moo-scaffold/docs/guide/` 与 `moo-system/docs/INTEGRATION.md`。

## 🎯 目标

给新手一套可用的教程，从 0 开始、零付费依赖地搭出带 JWT + ACL 的 Laravel 12 骨架；
进阶者再用 moo-system 一键升级成完整系统管理后端。
