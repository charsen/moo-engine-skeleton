# moo-engine-skeleton

一套 **Laravel 12 后端开发骨架**，把作者多年沉淀的业务代码结构提炼成一个「从 0 开始」的起点。
配套一份可开源的新手教程：从空目录出发，一步步搭出带**代码生成器**、**系统管理模块**和
**JWT 登录认证**的可运行后端——并且每一步都经过真机测试。

> 它是作者真实项目 `wisdomcity`、`light-language-engine` 的同级骨架版，
> 消费同一套私有包。

---

## ✨ 这套骨架包含什么

从零搭到一个「带基础系统管理模块 + JWT」的 Laravel 12 后端，覆盖：

1. **Laravel 12** 应用（放在 `engine/` 子目录），连接本机 MariaDB。
2. **moo-scaffold** 代码生成器：用一份 YAML 设计数据表 → 一键生成 Model / Request /
   Controller / Resource / 路由 / 迁移，并有内置的「数据库设计器 + 接口调试器」。
3. **moo-system** 系统管理模块：部门 / 岗位 / 人员 / 角色 / 授权 / 通知机器人 /
   登录管理 / 操作日志，开箱即用。
4. **JWT 登录认证**（仿 wisdomcity）：登录签发、守卫校验、近过期自动续签。
5. 在 moo-scaffold 的调试器里带 token 联调 moo-system 的接口。

## 📦 配套私有包

作者另外开发的插件包，存放在本项目的**同级目录**下：

| 包 | 作用 |
|---|---|
| `moo-scaffold` | 基础代码生成脚手架，含可视化数据库设计、接口调试 |
| `moo-scaffold-cloud` | 云端的异常 / 慢 SQL / todos 管理平台，支持多项目 |
| `moo-system` | 系统管理业务模块（部门、岗位、人员、角色等） |

## 🧰 环境要求

| 软件 | 版本（实测） | 说明 |
|---|---|---|
| PHP | 8.3 | Laravel 12 要求 `^8.2` |
| Composer | 2.9 | |
| Node / npm | 26 / 11 | 前端资源构建（可选） |
| MariaDB / MySQL | MariaDB 12 | 本机 `127.0.0.1:3306` |

## 🚀 快速开始

> 完整、带截图的从 0 教程见 [`docs/`](./docs/README.md)；这里是精简版。

```bash
# 1. 安装 Laravel 12 到 engine/ 子目录
composer create-project "laravel/laravel:^12.0" engine
cd engine

# 2. 建库（本机示例账号 root / 7777）
mysql -uroot -p7777 -h127.0.0.1 -e \
  "CREATE DATABASE IF NOT EXISTS moo_skeleton CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. 配 .env 的数据库（DB_CONNECTION=mysql / DB_DATABASE=moo_skeleton / DB_USERNAME=root / DB_PASSWORD=7777）
#    再接入私有包（开发用 path、生产用 vcs，详见 docs 第 2、3 章），然后：
composer update --with-all-dependencies
php artisan migrate --seed      # 迁移 + seed（角色 / 部门树 / 岗位 / 可登录管理员）

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

| 章节 | 内容 |
|---|---|
| [第 1 章 安装 Laravel 12](./docs/01-安装-laravel.md) | 建项目、连 MariaDB、建库、真机访问 |
| [第 2 章 安装 moo-scaffold](./docs/02-安装-moo-scaffold.md) | 私有包接入、设计 `foods` 表、一键生成业务代码、两种方式调接口 |
| [第 3 章 安装 moo-system（含 JWT）](./docs/03-安装-moo-system-与-jwt.md) | 系统管理模块、host 契约、JWT 登录、健康检查 |
| [第 4 章 真机调试 moo-system 接口](./docs/04-真机调试-moo-system-接口.md) | 登录拿 token、鉴权验证、在调试器里联调 |

教程目录页还附了一张**「踩过的坑」速查表**（9 条新手高频问题）：[docs/README.md](./docs/README.md)。

## 🔑 默认账号

| 用途 | 账号 | 密码 | 创建方式 |
|---|---|---|---|
| 后台管理员（Personnel） | `13800000000` | `admin888` | `migrate --seed`（PersonnelSeeder） |
| scaffold 调试台 | `charsen` | `skeleton2026` | `php artisan moo:account:add` |

## 🧭 设计原则

1. **每一步都有操作记录**，最终沉淀成可开源的新手教程。
2. **每一步都用 MCP 真机测试**——不只是写代码，而是跑起来、用浏览器/接口真实请求验证。

## 🔗 参考项目

搭建过程遇到不确定的地方，可参考 `wisdomcity`、`light-language-engine` 的真实实现；
私有包的细节见 `moo-scaffold/docs/guide/` 与 `moo-system/docs/INTEGRATION.md`。

## 🎯 目标

给新手一套可用的教程，从 0 开始，搭到一个带基础系统管理模块的 Laravel 12 骨架。
