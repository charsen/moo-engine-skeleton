# SQLite 单机试运行部署模板

本模板只适用于单台应用服务器、低写入量、可接受短暂停机维护的项目。它不是骨架的默认生产方案。

出现持续的 `database is locked`、需要横向扩容、多个服务共同写库或写入明显增加时，应迁移到 MySQL/PostgreSQL；禁止让多台服务器通过 NFS、SMB 等网络文件系统共享同一个 SQLite 文件。

## 持久化目录

代码工作树之外准备独立目录，下面的 `<project>` 替换成仓库目录名：

```text
/www/data/<project>/
├── .env
├── database.sqlite
└── backups/
```

```bash
DATA_DIR=/www/data/<project>
sudo install -d -m 2770 "$DATA_DIR" "$DATA_DIR/backups"
sudo touch "$DATA_DIR/database.sqlite"
sudo chmod 660 "$DATA_DIR/database.sqlite"
```

发布用户和 PHP-FPM 用户必须对数据目录具有读写权限。SQLite 会在数据库旁创建 `-wal`、`-shm` 临时文件，只给数据库文件写权限不够。

服务器专属 `.env`：

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=sqlite
DB_DATABASE=/www/data/<project>/database.sqlite
```

把 `.env` 链到固定工作树；数据库文件和 `.env` 都不进入 Git：

```bash
ln -s /www/data/<project>/.env engine/.env
```

`engine/storage/` 仍是运行时持久数据，原地 `git pull` 不会覆盖其中被忽略的文件；上传文件必须另行备份。如果部署方式改成每次替换整个 release 目录，应把 storage 改成部署工具的 shared directory。

## 队列

小项目可以显式选择 `QUEUE_CONNECTION=sync`，此时不需要 Redis queue worker，但耗时任务会直接占用 HTTP/CLI 请求。出现邮件群发、媒体处理等长任务后，应重新评估异步队列，而不是继续把任务塞进 sync。

## 迁移前在线备份

运行中的 SQLite 使用 `.backup`，不要直接复制正在写入的数据库文件：

```bash
DB_FILE=/www/data/<project>/database.sqlite
BACKUP_FILE=/www/data/<project>/backups/database-$(date +%Y%m%d-%H%M%S).sqlite

sqlite3 "$DB_FILE" ".backup '$BACKUP_FILE'"
sqlite3 "$BACKUP_FILE" 'PRAGMA integrity_check;'
cd engine
php artisan migrate --force
```

`integrity_check` 必须返回 `ok`。备份还应复制到另一块磁盘或异地存储，并定期做恢复演练；只留在同一台服务器无法覆盖磁盘损坏。

恢复前停止写入流量，先再次备份当前库，再执行：

```bash
sqlite3 /path/to/backup.sqlite 'PRAGMA integrity_check;'
sqlite3 /www/data/<project>/database.sqlite ".restore '/path/to/backup.sqlite'"
```

恢复后运行 `php artisan migrate:status`、`php artisan app:check-infrastructure-tables` 和真实登录/业务接口冒烟。
