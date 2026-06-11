# 第 1 章　安装 Laravel 12

目标：在 `engine/` 子目录里创建一个 Laravel 12 应用，连接本机 MariaDB 数据库
`moo_skeleton`，并在真实浏览器里打开它的欢迎页。

> **先分清你走哪条路。** 仓库根 [README](../README.md) 定义了两种用法：
> **方式 A = 直接用本仓库**（克隆下来，`engine/` 已是最终成品）；
> **方式 B = 从 0 跟教程一步步搭**。本章及后续教程面向**方式 B**。
> 如果你是克隆仓库的方式 A 读者：根目录已经存在 `engine/`，1.2 的
> `composer create-project` 会因目录非空直接报错；而且 `engine/.env.example`
> 已预填好 1.4 的全部配置，只需 `cp .env.example .env && php artisan key:generate`，
> 1.2–1.4 可整体跳过——请直接按根 README 的「快速开始（方式 A）」操作。

---

## 1.1 准备环境

还没有项目目录的话先建一个——它就是后文一直说的「仓库根目录」：

```bash
mkdir moo-engine-skeleton && cd moo-engine-skeleton    # git init 可选
```

本教程在 macOS 上用 [Homebrew](https://brew.sh) 安装基础工具（已装好的请跳过）：

```bash
brew install php composer node mariadb
brew services start mariadb        # 启动 MariaDB，监听 127.0.0.1:3306
```

> **MariaDB 新装后必做**：Homebrew 新装的 MariaDB 默认走 unix socket 认证、
> `root` 没有密码，直接照抄后文的 `mysql -uroot -p7777 -h127.0.0.1` 会认证失败。
> 先给 `root` 设一个密码（教程示例用 `7777`）：
>
> ```bash
> sudo mysql -uroot -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '7777'; FLUSH PRIVILEGES;"
> ```

然后确认本机工具齐全（在仓库根目录执行）：

```bash
php -v            # 8.2 起即可，本教程用 8.3.31（8.2 / 8.3 的区别见下方说明）
composer --version
node -v && npm -v # 本章用不到 Node——这是为后续章节的前端资源构建（可选）做预检
mysql --version   # MariaDB 12.x 客户端
```

> **PHP 到底要 8.2 还是 8.3？** 从 0 跟教程搭（方式 B）时，`laravel/laravel`
> 只要求 PHP `^8.2`，用 8.2 完全可行；**直接用本仓库（方式 A）才必须 8.3**——
> 仓库 `engine/composer.lock` 是按 8.3 解析的（其中 `php-open-source-saver/jwt-auth`
> 2.9 要求 PHP `^8.3`），在 8.2 上 `composer install` 装不上。

确认数据库在跑、账号密码可用：

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

`create-project` 已经自动生成了 `engine/.env`（`APP_KEY` 也已自动填好，不用再
`key:generate`），但它默认用 SQLite，要改成连本机数据库。注意一个容易疑惑的点：
**本机装的是 MariaDB，但 `DB_CONNECTION` 写的是 `mysql`**——MariaDB 与 MySQL
协议完全兼容，用 `mysql` 驱动连 MariaDB 是惯例写法（本机若装的是 MySQL 8 则更不用改）。
编辑 `engine/.env`，改成下面这样：

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

> **别在文件末尾追加！** 默认 `.env` 里 `DB_CONNECTION=sqlite`，而
> `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` 这几行
> 是被 `#` 注释掉的——请找到这几行，**取消注释并改成上面的值**。
> 若只在末尾追加新值，文件里会同时留着注释掉的旧行，日后排查容易看花眼。

> 端口为什么是 8088？最初只是因为作者本机 8000 端口被其它项目占用（见 1.6），
> 但 8088 现在已是**本仓库的约定端口**——`engine/.env`、根 README、第 2 章起的
> 所有命令都用它。建议跟教程统一用 8088；也可以自选空闲端口，但 `APP_URL`
> 以及后续章节所有 curl / 调试器地址都要同步修改、全程保持一致。

改完清掉配置缓存：

```bash
php artisan config:clear
```

## 1.5 执行数据库迁移

把 Laravel 自带的基础表建到 `moo_skeleton` 里（在 `engine/` 目录下执行）：

```bash
php artisan migrate
```

> 你可能在别处见过 `php artisan migrate:fresh --force`：`fresh` 会**先删掉库里
> 全部表**再重新迁移——在全新空库上和 `migrate` 等效，但日后在有数据的库上
> 执行会把数据清光；`--force` 则是让命令在生产环境跳过「确认执行」的交互提示。
> 日常按本教程用 `migrate` 即可。

验证表已建好：

```bash
mysql -uroot -p7777 -h127.0.0.1 moo_skeleton -e "SHOW TABLES;"
```

应能看到 `users`、`cache`、`jobs`、`migrations`、`sessions` 等 9 张表。

## 1.6 启动并真机访问

启动开发服务器（端口与 1.4 的 `APP_URL` 保持一致，教程统一用 8088）：

```bash
php artisan serve --host=127.0.0.1 --port=8088
```

> 端口被占时如何排查（比如想确认 8000 被谁占用）：
> ```bash
> lsof -nP -iTCP:8000 -sTCP:LISTEN     # 看谁在用 8000
> ```

> **注意**：`php artisan serve` 是前台进程，会一直占着当前终端（`Ctrl+C` 停止）。
> 浏览器访问不受影响，但下面的命令行自检需要**另开一个终端窗口**再执行。

用浏览器打开 `http://127.0.0.1:8088`，能看到 Laravel 12 的欢迎页即成功：

![Laravel 12 欢迎页](./images/01-laravel-welcome.png)

命令行快速自检（新终端里执行）：

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8088   # 期望 200
```

---

## 本章产出

- `engine/` 下一个可运行的 Laravel 12（12.61.1）应用；
- 连上本机 MariaDB 的 `moo_skeleton` 库，基础表迁移完成；
- 真实浏览器访问欢迎页通过（HTTP 200）。

下一章：安装 **moo-scaffold** 代码生成器，并用它生成一张 `foods` 表的全套业务代码。
