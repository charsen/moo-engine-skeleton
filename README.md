# moo-engine-skeleton

一套 **Laravel 12 后端开发骨架**，把作者多年沉淀的业务代码结构提炼成一个「从 0 开始」的起点。
配套一份可开源的新手教程：从空目录出发，一步步搭出带**代码生成器**、**系统管理模块**和
**JWT 登录认证**的可运行后端——并且每一步都经过真机测试。

> 它是作者真实项目 `wisdomcity`、`light-language-engine` 的同级骨架版，
> 消费同一套私有包。

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

## 📦 配套私有包

作者另外开发的插件包，存放在本项目的**同级目录**下：

| 包 | 作用 |
|---|---|
| `moo-scaffold` | 基础代码生成脚手架，含可视化数据库设计、接口调试 |
| `moo-scaffold-cloud` | 云端的异常 / 慢 SQL / todos 管理平台，支持多项目 |
| `moo-system` | 系统管理业务模块（部门、岗位、人员、角色等）——**进阶/商业包**，教程第 7 章可选接入 |

## 🧰 环境要求

| 软件 | 版本（实测） | 说明 |
|---|---|---|
| PHP | 8.3 | Laravel 12 要求 `^8.2` |
| Composer | 2.9 | |
| Node / npm | 26 / 11 | 前端资源构建（可选） |
| MariaDB / MySQL | MariaDB 12 | 本机 `127.0.0.1:3306` |

## 🚀 快速开始

**方式 A：直接用本仓库**（最终态，含全部七章成果）：

```bash
cd engine
composer install                                   # 含同级目录的 moo-* path 包
cp .env.example .env && php artisan key:generate   # .env 已预填 MariaDB root/7777 + moo_skeleton
php artisan jwt:secret --force
php artisan vendor:publish --provider="Mooeen\Scaffold\ScaffoldProvider" --tag=public --force
php artisan migrate --seed                          # 自建用户 + 角色/部门树/岗位/管理员
php artisan moo:account:add charsen --password=skeleton2026 --role=admin   # scaffold 调试台账号
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
php artisan test                                    # 21 passed
```

**方式 B：从 0 跟教程搭**（推荐新手，带截图的完整教程见 [`docs/`](./docs/README.md)）：

```bash
# 1. 安装 Laravel 12 到 engine/ 子目录
composer create-project "laravel/laravel:^12.0" engine
cd engine

# 2. 建库（本机示例账号 root / 7777）
mysql -uroot -p7777 -h127.0.0.1 -e \
  "CREATE DATABASE IF NOT EXISTS moo_skeleton CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. 配 .env 的数据库（DB_CONNECTION=mysql / DB_DATABASE=moo_skeleton / DB_USERNAME=root / DB_PASSWORD=7777）
#    再接入私有包（开发用 path、生产用 vcs，详见 docs 第 2、7 章），然后：
composer update --with-all-dependencies
php artisan migrate --seed      # 迁移 + seed（自建用户；装了 moo-system 还有角色/部门树/岗位/人员）

# 4. 启动（用多 worker，否则 scaffold 调试器代理会和单线程服务死锁）
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
```

- 应用：<http://127.0.0.1:8088>
- 代码生成 / 接口调试台：<http://127.0.0.1:8088/scaffold>

## 📂 目录结构

Laravel 应用放在 **`engine/`**（与 `wisdomcity`、`light-language-engine` 一致），
仓库根目录只放文档。

```
moo-engine-skeleton/
├── README.md  CLAUDE.md     # 说明文档
├── docs/                    # 从 0 开始的新手教程（含截图）
└── engine/                  # ← Laravel 12 应用本体
```

## 📖 从 0 开始教程

> 推荐用**网页引导器**跟做（分步模式 + 进度记忆 + 代码一键复制，零依赖单文件）：
> `cd docs && php -S 127.0.0.1:9999`，浏览器打开 http://127.0.0.1:9999

| 章节 | 内容 |
|---|---|
| [第 1 章 安装 Laravel 12](./docs/01-安装-laravel.md) | 建项目、连 MariaDB、建库、真机访问 |
| [第 2 章 安装 moo-scaffold](./docs/02-安装-moo-scaffold.md) | 私有包接入、设计 `foods` 表、一键生成业务代码、两种方式调接口 |
| [第 3 章 JWT 登录认证（自建用户）](./docs/03-JWT-登录认证-自建用户.md) | 零付费依赖：最简 User 实现 JWTSubject、双守卫、三中间件、登录全链路 |
| [第 4 章 JWT 加固与生产化](./docs/04-JWT-加固与生产化.md) | 生产踩坑回灌：persistent_claims、黑名单宽限、滑动续期、CORS、限流、生产 composer、接口测试 |
| [第 5 章 给 Food 上 JWT 与 ACL](./docs/05-给-Food-上-JWT-与-ACL.md) | 动作级授权完整闭环：401→403→授权→200（User actions 最小实现） |
| [第 6 章 移动端分片与 user 守卫](./docs/06-移动端分片与-user-守卫.md) | 守卫隔离、单设备 refresh |
| [第 7 章 安装 moo-system（进阶）](./docs/07-安装-moo-system.md) | 完整系统管理：host 契约、主体切换 User→Personnel、角色授权、操作日志、调试器联调 |

教程目录页还附了一张**「踩过的坑」速查表**（21 条新手高频问题）：[docs/README.md](./docs/README.md)。

## 🔑 默认账号

| 用途 | 账号 | 密码 | 创建方式 |
|---|---|---|---|
| 自建用户（第 3~6 章后台 + 永久的移动端） | `admin@example.com` | `password` | `migrate --seed`（UserSeeder） |
| 后台管理员（Personnel，第 7 章起） | `13800000000` | `admin888` | `migrate --seed`（PersonnelSeeder） |
| scaffold 调试台 | `charsen` | `skeleton2026` | `php artisan moo:account:add` |

## 🧭 设计原则

1. **每一步都有操作记录**，最终沉淀成可开源的新手教程。
2. **每一步都用 MCP 真机测试**——不只是写代码，而是跑起来、用浏览器/接口真实请求验证。

## 🔗 参考项目

搭建过程遇到不确定的地方，可参考 `wisdomcity`、`light-language-engine` 的真实实现；
私有包的细节见 `moo-scaffold/docs/guide/` 与 `moo-system/docs/INTEGRATION.md`。

## 🎯 目标

给新手一套可用的教程，从 0 开始、零付费依赖地搭出带 JWT + ACL 的 Laravel 12 骨架；
进阶者再用 moo-system 一键升级成完整系统管理后端。
