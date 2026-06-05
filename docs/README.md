# moo-engine-skeleton 从 0 开始搭建教程

这是一套**面向新手**的后端开发骨架教程：从一个空目录开始，一步步搭出一个带
**代码生成器（moo-scaffold）**、**系统管理模块（moo-system：部门 / 岗位 / 人员 / 角色 / 授权）**
和 **JWT 登录认证** 的 Laravel 12 后端骨架。

> 本教程遵循两条原则：
> 1. **每一步都有操作记录**，命令和结果都照实写下来，方便照着做；
> 2. **每一步都用真机测试**（启动真实服务、用浏览器/接口真实请求验证），而不是只写代码。

## 环境要求

| 软件 | 版本（本教程实测） | 说明 |
|---|---|---|
| PHP | 8.3.31 | Laravel 12 要求 `^8.2` |
| Composer | 2.9.5 | PHP 包管理器 |
| Node / npm | 26 / 11 | 前端资源构建（可选） |
| MariaDB | 12.2.2（兼容 MySQL） | 数据库；本机 `127.0.0.1:3306` |
| Git | 任意较新版本 | 已装 `git-lfs` |

数据库连接：**用户名 `root` / 密码 `7777`**，本教程用的数据库名是 **`moo_skeleton`**。

## 目录结构约定（重要）

Laravel 应用放在仓库的 **`engine/`** 子目录里，仓库根目录只放文档和部署脚本。
这和作者其它项目（`wisdomcity`、`light-language-engine`）保持一致，
私有包的相对路径也因此统一为 `../../moo-scaffold`。

```
moo-engine-skeleton/
├── README.md  CLAUDE.md          # 说明文档
├── docs/                         # 就是你正在看的这套教程
└── engine/                       # ← Laravel 12 应用本体（composer 命令都在这里执行）
```

## 章节

| 章节 | 内容 | 对应 README 步骤 |
|---|---|---|
| [第 1 章 安装 Laravel 12](./01-安装-laravel.md) | 创建项目、连接 MariaDB、建库、真机访问 | 步骤 1 |
| [第 2 章 安装 moo-scaffold](./02-安装-moo-scaffold.md) | 私有包接入（dev=路径 / prod=VCS）、设计 `foods` 表、一键生成业务代码、两种方式调接口 | 步骤 2 |
| [第 3 章 安装 moo-system（含 JWT）](./03-安装-moo-system-与-jwt.md) | 系统管理模块接入、host 契约、JWT 登录、`moo-system check`、迁移 | 步骤 3 + 5 |
| [第 4 章 真机调试 moo-system 接口](./04-真机调试-moo-system-接口.md) | 登录拿 token、鉴权验证、在 scaffold 调试器里带 token 联调 | 步骤 4 |

> moo-system 的接口依赖 JWT 登录，所以 README 的第 3、5 步在第 3 章合并完成。

## 踩过的坑速查（新手必看）

| # | 现象 | 原因 / 解决 | 章节 |
|---|---|---|---|
| 1 | 生成 Model 报 `EloquentFilter\Filterable not found` | 装 `tucker-eric/eloquentfilter` + `godruoyi/php-snowflake` | 2 |
| 2 | 报 `BaseActionTrait not found` | `moo:free` 不建它，跑一次 `php artisan moo:controller Food -f` | 2 |
| 3 | `moo:free` 里 `moo:api` 提示 No routes matched | 路由刚插入、当前进程没刷新，单独补 `moo:api admin Food` | 2 |
| 4 | 调试器代理一直转圈 | 单线程 serve 自我代理死锁，用 `PHP_CLI_SERVER_WORKERS=4 ... --no-reload` | 2 |
| 5 | 装 moo-system 后 artisan 报 `Attribute [iResource] does not exist` | `iResource` 宏要注册在 `AppServiceProvider::register()` | 3 |
| 6 | 调部门列表报 `undefined function toLabelValue()` | 补 `app/Helpers/helpers.php` 并 `composer` files 自动加载 | 3 |
| 7 | `moo-system check` 的中间件组那项总 FAIL | 中间件组要注册到 router（provider boot），否则 console 看不到 | 3 |
| 8 | 调试器里带了 token 仍 401 | Authorization 值要加 `Bearer ` 前缀 | 4 |
