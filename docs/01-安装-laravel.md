# 第 1 章　安装 Laravel 12

目标：在 `engine/` 子目录里创建一个 Laravel 12 应用，连接本机 MariaDB 数据库
`moo_skeleton`，并在真实浏览器里打开它的欢迎页。

---

## 1.1 检查环境

先确认本机工具齐全（在仓库根目录执行）：

```bash
php -v            # 应为 8.2 以上（本教程 8.3.31）
composer --version
node -v && npm -v
mysql --version   # MariaDB 12.x 客户端
```

确认数据库在跑、账号密码可用（MariaDB 用 Homebrew 启动，监听 `127.0.0.1:3306`）：

```bash
mysql -uroot -p7777 -h127.0.0.1 -e "SELECT VERSION();"
```

> `root / 7777` 是**本教程的示例凭据**——换成你自己的数据库账号密码即可，
> 后续所有命令里的 `-p7777` 同步替换。

## 1.2 创建 Laravel 12 项目到 engine/

本机没有 `laravel` 安装器，直接用 Composer 创建，并指定目录为 `engine`：

```bash
# 在仓库根目录 moo-engine-skeleton/ 下执行
composer create-project "laravel/laravel:^12.0" engine --no-interaction
```

装完后实测版本：

```bash
cd engine
php artisan --version
# Laravel Framework 12.61.1   ← 小版本号以实际安装为准（^12.0 内都没问题）
```

## 1.3 创建数据库

```bash
mysql -uroot -p7777 -h127.0.0.1 -e \
  "CREATE DATABASE IF NOT EXISTS moo_skeleton CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

## 1.4 配置 .env 连接数据库

Laravel 12 默认用 SQLite，改成连本机数据库。注意一个容易疑惑的点：
**本机装的是 MariaDB，但 `DB_CONNECTION` 写的是 `mysql`**——MariaDB 与 MySQL
协议完全兼容，用 `mysql` 驱动连 MariaDB 是惯例写法（本机若装的是 MySQL 8 则更不用改）。
编辑 `engine/.env`：

```dotenv
APP_NAME=moo-engine-skeleton
APP_URL=http://127.0.0.1:8088

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=moo_skeleton
DB_USERNAME=root
DB_PASSWORD=7777
```

> 端口为什么是 8088？因为本机 8000 端口已被其它项目占用（见 1.6）。

改完清掉配置缓存：

```bash
php artisan config:clear
```

## 1.5 执行数据库迁移

把 Laravel 自带的基础表建到 `moo_skeleton` 里：

```bash
php artisan migrate:fresh --force
```

验证表已建好：

```bash
mysql -uroot -p7777 -h127.0.0.1 moo_skeleton -e "SHOW TABLES;"
```

应能看到 `users`、`cache`、`jobs`、`migrations`、`sessions` 等 9 张表。

## 1.6 启动并真机访问

启动开发服务器（8000 被占用，这里用 8088）：

```bash
php artisan serve --host=127.0.0.1 --port=8088
```

> 如何确认 8000 被谁占用 / 哪些端口空闲：
> ```bash
> lsof -nP -iTCP:8000 -sTCP:LISTEN     # 看谁在用 8000
> ```

用浏览器打开 `http://127.0.0.1:8088`，能看到 Laravel 12 的欢迎页即成功：

![Laravel 12 欢迎页](./images/01-laravel-welcome.png)

命令行快速自检：

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8088   # 期望 200
```

---

## 本章产出

- `engine/` 下一个可运行的 Laravel 12（12.61.1）应用；
- 连上本机 MariaDB 的 `moo_skeleton` 库，基础表迁移完成；
- 真实浏览器访问欢迎页通过（HTTP 200）。

下一章：安装 **moo-scaffold** 代码生成器，并用它生成一张 `foods` 表的全套业务代码。
