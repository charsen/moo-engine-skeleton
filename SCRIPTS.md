# 运维脚本说明

骨架把两个生产项目验证过的部署 / 运维脚本沉淀到仓库根，分两层：

- **仓库根 `*.sh`** —— 部署 / 运维主脚本（`pull.sh` / `cache.sh` / `backup.sh` / `opcache.sh` / `release-check.sh` / `fixJob.sh`）。
- **`tools/`** —— 辅助工具 + 共享库（被根脚本 `source` 的 `_common.sh`、nginx 加固、schema 漂移探针）。

> 全部脚本 `set -eu`（出错 / 未定义变量立即退），且能在**非生产环境优雅降级**：
> macOS 开发机上缺 `flock` / 非 root / 无 `systemctl` 时跳过对应步骤并给清晰提示，而不是静默失败。

## 速查表 —— 仓库根（部署 / 运维）

| 脚本 | 用途 | 典型场景 |
| --- | --- | --- |
| [`pull.sh`](#pullsh) | git pull + 验证私包权限 + 使用生产 Composer 配置/独立 lock + 强制 update 私包 + 调 cache.sh | **生产部署主入口** |
| [`cache.sh`](#cachesh) | Laravel `optimize:clear`+`optimize` + 目录权限 + 预建 daily log + 清旧 log | 本地刷缓存 / 报权限错 / 每晚 cron |
| [`backup.sh`](#backupsh) | mysqldump 全库 → `engine/storage/app/db/`，bz2 压缩，按天清理 | 每天 cron 备份 |
| [`opcache.sh`](#opcachesh) | 按 git diff 精准失效变更 `.php` 的 OPcache（不全量 reset） | 热更后让 CLI OPcache 生效 |
| [`release-check.sh`](#release-checksh) | 发布门禁：脚本语法 + `composer validate/audit` + `route:list` 冒烟 + 全量测试 | 发版 / CI 前一键自检 |
| [`fixJob.sh`](#fixjobsh) | 注册表驱动的单项目 queue worker 重启器（`supervisorctl`） | Job 卡住 / 部署后重启 worker |

## 速查表 —— `tools/`（工具 + 共享库）

| 脚本 | 用途 | 典型场景 |
| --- | --- | --- |
| [`tools/_common.sh`](#tools_commonsh) | 共享库：打印 5 件套 + `require_command` + `acquire_lock` + `is_production` + PATH 守卫 | 被 pull/cache/backup source，**不直接跑** |
| [`tools/fix-nginx-storage-safe.sh`](#toolsfix-nginx-storage-safesh) | 加固 nginx vhost：拦 `/storage/` 下可执行文件（php/cgi/…）+ PATH_INFO 绕过，幂等、带 `--dry-run` | 安全加固（配 docs 第 8 章 nginx 段） |
| [`tools/db-yaml-drift-probe.php`](#toolsdb-yaml-drift-probephp) | **只读**对账 MySQL 库表 vs scaffold yaml，出 high/mid/low 三级漂移报告 | 排查 schema 与 yaml 不同步（配 docs 第 9 章） |

---

## `pull.sh`

**功能（生产部署主入口）**：

1. 校验主仓工作区干净（脏则停；`--force-reset` 丢弃）
2. 版本选择（`--tag` 锚定 / `--latest` 或非交互追 master / 终端交互列近 5 个 tag 选）+ `git pull`
3. 验证私包拉取权限（`ssh` 联通 + 逐包 `git ls-remote` fail-fast）
4. 按 `.env APP_ENV=production` 守卫设置 `COMPOSER=composer.production.json`，配对使用 `composer.production.lock`（不覆盖开发文件）
5. `composer install` 兜底（含 vendor Provider 缺失自愈）+ 强制 `composer update <私包>` 拉最新
6. （5.5）`php artisan vendor:publish` 刷私包前端资源副本
7. 调 [`cache.sh`](#cachesh) 收尾（缓存清理 + 权限）；（6.5）检测 pending 迁移（只报不跑）

```bash
sh pull.sh                          # 交互选 tag 发版（终端）；非交互等价 --latest
sh pull.sh --tag 1.2.0              # 锚定 tag 发版
sh pull.sh --latest                 # 追 master 最新
sudo sh pull.sh --production        # 首次部署（.env 未建，显式声明生产）
sh pull.sh --force-reset            # 工作区脏时丢弃本地已跟踪改动后继续
sh pull.sh --skip-private-pkg       # 跳过私包权限验证（仅本地调试）
sh pull.sh --engine-subdir backend  # 非 engine/ 布局覆盖（默认 engine）
sh pull.sh --help                   # 全部参数
```

**项目无关性**：私包 manifest 与 vcs URL 全从 `engine/composer.production.json` 的
`.extra."moo-private-packages"` / `.repositories` 用 jq 动态解析（字段：`name` / `repo-key` /
`provider-rel` / `publish-tag`），**脚本零硬编码**——加新私包只改 manifest 数组，不动 pull.sh。

**返回码**（cron 监控按此写告警规则）：`0` 成功 / `1` 仓库·权限·.env·`tools/_common.sh` 缺失 /
`3` composer install·update 失败 / `4` 主体成功但收尾失败（git 已推进、缓存/权限/迁移未跟上，需人排查）。

**详见** [`PRIVATE-COMPOSER-PACKAGES.md`](./PRIVATE-COMPOSER-PACKAGES.md) 与 [`DEPLOY-CHECKLIST.md`](./DEPLOY-CHECKLIST.md)。

---

## `cache.sh`

**功能**：Laravel 缓存清理 + 目录权限修复 + daily log 治理（纯本地，**不碰 vendor / composer**）。

**职责严格分离**：

| 脚本 | 职责 |
| --- | --- |
| `pull.sh` | 网络层 — git pull / 私包验证 / composer install·update / vendor:publish |
| `cache.sh` | 本地层 — `optimize:clear`+`optimize` / 目录权限（chown+setgid） / 预建+清理 daily log |

> ⚠️ cache.sh **不跑** composer。vendor 不在 → 报错让你走 pull.sh。

```bash
sh cache.sh                          # 普通用户：刷缓存（非 root / 无 systemctl 自动降级跳过 chown/reload）
sudo sh cache.sh                     # root：额外 chown / setgid / reload php-fpm
LOG_KEEP_DAYS=14 sh cache.sh         # 旧 log 保留 14 天（默认 30；设 0 = 不清）
```

### 核心价值：治 daily log "first-writer-wins"

Laravel `daily` channel 每天 00:00 建新 `<prefix>-YYYY-MM-DD.log`，**谁先写谁拥有**：root cron / sudo CLI
抢先 → 文件 `root:root` → PHP-FPM 写不进 → 全站 500。cache.sh 兜底链：`umask 002`（新文件 664）+
`chmod 2775`（setgid，新文件继承 web group）+ 预 `touch` 今日+次日所有 daily channel 文件并 chown 给
web user + 末段 `reload php-fpm`（清 worker 里被 OPcache 缓存的 fopen 失败状态）。

骨架的 daily channel 是 `laravel` / `auth` / `dev`（见 `engine/config/logging.php`）——脚本里
`DAILY_LOG_BASENAMES` 已按此配置；host 新增 daily channel 时同步补一行。

**为什么 cron 要每天跑**：predcreate 只覆盖今天+明天，不每天跑则后天 00:00 又回到 race。
建议 web user crontab：`55 23 * * * cd /path/to/repo && sh cache.sh >/dev/null 2>&1`（非 tty 自动不刷 tip）。

---

## `backup.sh`

**功能**：mysqldump 全库 → `engine/storage/app/db/`，bz2 压缩，按天清理。

```bash
sh backup.sh                                  # 用 engine/.env 的 DB_* 配置
DB_NAME=other_db sh backup.sh                 # 备份其它库
MYSQLDUMP_BIN=/usr/bin/mysqldump sh backup.sh # 指定 mysqldump 路径
DB_HOST=10.0.0.10 DB_PORT=3306 sh backup.sh   # 备份远端
KEEP_DAYS=14 sh backup.sh                     # 旧备份保留 14 天（默认 7）
```

**配置优先级**：环境变量 `DB_*` / `MYSQLDUMP_BIN` / `KEEP_DAYS` / `IGNORE_TABLES` > `engine/.env` 的
`DB_DATABASE`/`DB_USERNAME`/…。默认 `--ignore-table` 跳过 `system_operation_logs`（操作日志表，体积大不需备）。
输出 `<db>_YYYYMMDD_HHMMSS.tar.bz2`，自动清 `KEEP_DAYS` 天前旧备份。

**还原**：`tar -xjf <file>.tar.bz2 && mysql -u root -p <db> < <file>.sql`

---

## `opcache.sh`

**功能**：按 git diff 精准失效变更 `.php` 的 OPcache（`opcache_invalidate` 逐个，而非全量 `opcache_reset`）。

```bash
sh opcache.sh                 # 默认对比最近一次 commit
sh opcache.sh HEAD~3..HEAD    # 自定义提交范围
```

> ⚠️ 走 PHP **CLI** 的 OPcache，跟 php-fpm 的 OPcache 池独立。线上 php-fpm 改完 `.php` 后仍要
> `systemctl reload php-fpm`（`cache.sh` 末段已做）。PHP CLI 未启用 OPcache（`opcache.enable_cli=1`）时脚本优雅退出。

---

## `release-check.sh`

**功能**：发布门禁，任一步失败即非零退出。检查项：全部 `*.sh` 语法（`sh -n` / `bash -n` 按 shebang 分流）+
`db-yaml-drift-probe.php` 与初始化器 `php -l` → 两套 Composer 配置 `validate` / `audit` →
`composer dump-autoload --classmap-authoritative` → `php artisan about` / `route:list` → `composer test`（全量测试）→
`git diff --check`（改动无空白错误）。

```bash
sh release-check.sh          # 仓库根跑；CI / 手动发版前都跑一遍
```

> macOS 可跑（主要是 composer / artisan 校验）。这是发版前的最后一道机器闸门，跟
> [`DEPLOY-CHECKLIST.md`](./DEPLOY-CHECKLIST.md) 的 last-mile sanity 配合用。

---

## `fixJob.sh`

**功能**：注册表驱动的单项目 queue worker 重启器 —— `supervisorctl reread/update/restart <pool>:*`
让 worker 加载新代码。一次只重启一个项目；加项目改脚本里 `WORKERS` 注册表一行即可。

```bash
sudo sh fixJob.sh                # 交互单选
sudo sh fixJob.sh your-project   # 直接重启 key（cron 友好，不交互）
sudo sh fixJob.sh 1              # 按菜单序号
```

> ⚠️ 骨架发的是**模板**：`WORKERS` 注册表是 `your-project` 占位，首次部署时改成你实际的
> supervisor pool（每行 `key|显示名|pool 名`）。需 supervisor 权限（通常 `sudo`）。

---

## `tools/_common.sh`

**共享库**，被 `pull.sh` / `cache.sh` / `backup.sh` 顶部 `source`，**不直接执行**。单一真值源，避免各脚本
各写一份导致 `is_production` regex 漂移之类的 bug。暴露：

- `info / warn / success / error / section` —— 打印 5 件套
- `require_command <cmd>` —— 缺命令 exit 1 + 装机提示（jq / flock 等）
- `user_exists <name|uid>` —— 用户存在性
- `acquire_lock <file> <名>` —— flock 单实例锁（无 flock 则 warn 跳过，兼容 macOS）
- `is_production()` —— 读 `.env APP_ENV`（带引号兼容 + 缓存）

source 时还有副作用：**PATH 守卫**（补 `/usr/local/php/bin` 等，防 sudo 重置 PATH 命中旧版 PHP）。

---

## `tools/fix-nginx-storage-safe.sh`

**功能**：加固 nginx vhost —— 把 `/storage/` 下可执行文件（`php`/`phtml`/`phar`/`cgi`/…）的请求拦成 404，
堵住"上传目录被当脚本执行" + `PATH_INFO`（`/storage/x.php/y`）绕过。**幂等**：扁平 / 嵌套 legacy 规则都识别并
统一升级到当前标准，改动走临时文件 + `cmp` 对比 + 原子 mv，改前自动备份。

```bash
sh tools/fix-nginx-storage-safe.sh --dry-run                     # 沙盘，只看会改什么
sh tools/fix-nginx-storage-safe.sh                               # 实改（自动备份）
sh tools/fix-nginx-storage-safe.sh --dir /etc/nginx/conf.d       # 指定 vhost 目录
sh tools/fix-nginx-storage-safe.sh --exts 'php[0-9]*|phtml|phar' # 自定义后缀黑名单
```

> 脚本**只改配置不重载**；改完需手动 `nginx -t && nginx -s reload`。配套讲解见 docs 第 8 章 8.4 nginx 段。

---

## `tools/db-yaml-drift-probe.php`

**功能**：**只读**对账 —— 逐列比对 `engine/scaffold/database/*.yaml` 与实际 MySQL 库表，输出 markdown
漂移报告到 `tools/db-yaml-drift.md`。无参数、无 `--apply`，纯读 + 写一个 md（复用 engine vendor 里的
`symfony/yaml` + PDO，读 `engine/.env` 拿 DB 凭据，不依赖 Laravel runtime）。

```bash
php tools/db-yaml-drift-probe.php        # 从仓库根跑
```

**漂移分级**：`high`（列缺失 / 类型族不符 / 未知 yaml 简写，必修）`mid`（nullable 不一致 / DB legacy 列，需评估）
`low`（varchar 宽度差，低优）。使用方法论见 docs 第 9 章「一次性数据迁移可回滚范式」进阶节。

---

## 常用组合

### 部署上线
```bash
sh pull.sh                       # 1) 拉代码 + 私包 + 刷缓存（自动调 cache.sh）
sudo sh fixJob.sh your-project   # 2) 重启该项目 supervisor pool
systemctl reload php-fpm         # 3) 让 fpm 生效（cache.sh 末段已 reload，这步多为兜底）
```

### crontab 标配
```cron
# 每天 3 点全库备份
0 3 * * * /path/to/repo/backup.sh >> /var/log/your-project-backup.log 2>&1

# 每晚 23:55 预建次日 daily log + 清旧 log + 修权限（治 00:00 翻篇 race）
55 23 * * * cd /path/to/repo && sh cache.sh >/dev/null 2>&1
```

### 紧急排查
```bash
sudo sh cache.sh                  # 权限错：重建缓存目录 + 修属主 + reload fpm
systemctl reload php-fpm          # 部署后 fpm 还跑旧代码
sudo supervisorctl status         # Job 卡住：看 pool
sudo sh fixJob.sh                 # 选项目重启 pool
php tools/db-yaml-drift-probe.php # 怀疑库表与 yaml 不同步：出漂移报告
```

---

## 注意事项

- `cache.sh`（root 跑时）会把根目录 `*.sh` 权限改 `750` + `chgrp $WEB_GROUP`（cron user 友好）——**隐性约束**：
  cron user 须是 `$WEB_GROUP` 成员，否则 750 让 cron 触发即 permission denied。
- 走 crontab 的脚本（`backup.sh` / `cache.sh`）**必须用绝对路径**（cron 默认 `$PATH` 很短）。
- `tools/_common.sh` 必须随 `pull.sh` / `cache.sh` / `backup.sh` 一起部署（顶部 source 它，缺则同时挂）。
- 首次部署系统依赖前置（`git` / `ssh` / `jq` / `flock` / sudo `secure_path`）见 [`DEPLOY-CHECKLIST.md`](./DEPLOY-CHECKLIST.md) O-0 与 docs 第 8 章。
