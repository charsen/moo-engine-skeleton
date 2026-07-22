#!/usr/bin/env sh
#
# backup.sh — mysqldump 全库备份 → engine/storage/app/db/，bz2 压缩，按天清理（crontab 友好）
#
# 配置优先级：环境变量 > engine/.env。env 里读 DB_DATABASE / DB_USERNAME / DB_PASSWORD /
#             DB_HOST / DB_PORT；环境变量用大写别名 DB_NAME / DB_USER / DB_PASS / DB_HOST / DB_PORT 覆盖。
#
# 用法：
#   sh backup.sh                                  # 用 engine/.env 的 DB_* 配置
#   DB_NAME=other_db sh backup.sh                 # 备份其它库
#   MYSQLDUMP_BIN=/usr/bin/mysqldump sh backup.sh # 指定 mysqldump 路径
#   DB_HOST=10.0.0.10 DB_PORT=3306 sh backup.sh   # 备份远端
#   KEEP_DAYS=14 sh backup.sh                     # 旧备份保留 14 天（默认 7）
#   IGNORE_TABLES="system_operation_logs,sessions" sh backup.sh  # 跳过大表（逗号分隔）
#
# crontab（绝对路径 + 重定向日志）：
#   0 3 * * * /path/to/repo/backup.sh >> /var/log/your-project-backup.log 2>&1
#
# 产物：$OUT_DIR/${DB_NAME}_YYYYMMDD_HHMMSS.tar.bz2
# 还原：tar -xjf xxxx.tar.bz2 && mysql -u root -p <db> < xxxx.sql

set -eu

usage() {
    cat <<'HELP'
用法：sh backup.sh [选项]

选项：
  -h, --help           显示本帮助，不执行备份

配置通过环境变量或 engine/.env 提供。常用环境变量：
  DB_NAME / DB_USER / DB_PASS / DB_HOST / DB_PORT
  MYSQLDUMP_BIN / OUT_DIR / KEEP_DAYS / IGNORE_TABLES
HELP
}

case "${1:-}" in
    '') ;;
    -h|--help)
        usage
        exit 0
        ;;
    *)
        printf '%s\n' "未知选项：$1" >&2
        usage >&2
        exit 2
        ;;
esac

# shellcheck disable=SC1007
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_DIR="$SCRIPT_DIR"
ENGINE_SUBDIR="${ENGINE_SUBDIR:-engine}"
ENGINE_DIR="$PROJECT_DIR/$ENGINE_SUBDIR"
ENV_FILE="$ENGINE_DIR/.env"

# 复用公共工具（打印 5 件套 + require_command + PATH 守卫），跟 pull.sh / cache.sh 单一真值源。
if [ ! -f "$SCRIPT_DIR/tools/_common.sh" ]; then
    printf '%s\n' "❌ [ERROR] $SCRIPT_DIR/tools/_common.sh 缺失（部署时请带上 tools/ 整目录）" >&2
    exit 1
fi
# shellcheck source=tools/_common.sh
. "$SCRIPT_DIR/tools/_common.sh"

# 从 .env 读单行 KEY，剥首尾引号 + 去行尾注释；环境变量优先（未设才读 .env）。
read_env() {
    key="$1"
    [ -f "$ENV_FILE" ] || return 0
    awk -F= -v k="$key" '
        $1 == k {
            sub(/^[^=]*=/, "")
            sub(/[[:space:]]+#.*$/, "")
            sub(/^["'\'']/, ""); sub(/["'\'']$/, "")
            print; exit
        }' "$ENV_FILE"
}

DB_NAME=${DB_NAME:-$(read_env DB_DATABASE)}
DB_USER=${DB_USER:-$(read_env DB_USERNAME)}
DB_PASS=${DB_PASS:-$(read_env DB_PASSWORD)}
DB_HOST=${DB_HOST:-$(read_env DB_HOST)}
DB_PORT=${DB_PORT:-$(read_env DB_PORT)}

OUT_DIR=${OUT_DIR:-"$ENGINE_DIR/storage/app/db"}
KEEP_DAYS=${KEEP_DAYS:-7}
# 默认跳过操作日志表的数据、保留表结构（体积大但恢复后仍应有空表）。逗号分隔可覆盖。
IGNORE_TABLES=${IGNORE_TABLES:-system_operation_logs}

# 解析 mysqldump：env MYSQLDUMP_BIN > PATH > 常见安装位置。
resolve_mysqldump() {
    if [ -n "${MYSQLDUMP_BIN:-}" ]; then
        printf '%s\n' "$MYSQLDUMP_BIN"; return 0
    fi
    if command -v mysqldump >/dev/null 2>&1; then
        command -v mysqldump; return 0
    fi
    for candidate in /usr/local/mariadb/bin/mysqldump /usr/local/mysql/bin/mysqldump; do
        [ -x "$candidate" ] && { printf '%s\n' "$candidate"; return 0; }
    done
    return 1
}

# IGNORE_TABLES="a,b" → 多个 --ignore-table-data=DB.table（保留建表语句）
build_ignore_args() {
    raw="$1"; db="$2"; args=""
    OLD_IFS=$IFS; IFS=','
    for tbl in $raw; do
        tbl_trimmed=$(printf '%s' "$tbl" | awk '{$1=$1; print}')
        [ -n "$tbl_trimmed" ] || continue
        args="$args --ignore-table-data=$db.$tbl_trimmed"
    done
    IFS=$OLD_IFS
    printf '%s' "$args"
}

if [ -z "$DB_NAME" ]; then
    error "未取到 DB_DATABASE（检查 $ENV_FILE 或传入 DB_NAME=...）"
    exit 1
fi

section "解析 mysqldump 路径"
DUMP_BIN=$(resolve_mysqldump || true)
if [ -z "$DUMP_BIN" ]; then
    error "找不到 mysqldump，请设 MYSQLDUMP_BIN 或安装 mysql/mariadb client"
    exit 1
fi
info "mysqldump => $DUMP_BIN"

section "准备输出目录"
mkdir -p "$OUT_DIR"
cd "$OUT_DIR"
info "输出目录: $OUT_DIR"

DATE=$(date +%Y%m%d_%H%M%S)
OUT_SQL="${DB_NAME}_${DATE}.sql"
TAR_SQL="${DB_NAME}_${DATE}.tar.bz2"

# 组装非密码鉴权参数。密码不拼进命令行参数，避免 `ps` 暴露和 mysql 的
# "Using a password on the command line" 警告；只给本次 mysqldump 注入 MYSQL_PWD。
DUMP_AUTH=""
[ -n "$DB_USER" ] && DUMP_AUTH="$DUMP_AUTH -u$DB_USER"
[ -n "$DB_HOST" ] && DUMP_AUTH="$DUMP_AUTH -h$DB_HOST"
[ -n "$DB_PORT" ] && DUMP_AUTH="$DUMP_AUTH -P$DB_PORT"

section "导出 $DB_NAME"
IGNORE_ARGS=$(build_ignore_args "$IGNORE_TABLES" "$DB_NAME")
[ -n "$IGNORE_ARGS" ] && info "忽略表: $IGNORE_TABLES"

# shellcheck disable=SC2086
if [ -n "$DB_PASS" ]; then
    MYSQL_PWD="$DB_PASS" "$DUMP_BIN" $DUMP_AUTH \
        --skip-extended-insert \
        --default-character-set=utf8mb4 \
        $IGNORE_ARGS \
        "$DB_NAME" > "$OUT_SQL"
else
    "$DUMP_BIN" $DUMP_AUTH \
        --skip-extended-insert \
        --default-character-set=utf8mb4 \
        $IGNORE_ARGS \
        "$DB_NAME" > "$OUT_SQL"
fi
success "已导出: $OUT_SQL"

section "压缩"
tar -jcf "$TAR_SQL" "./$OUT_SQL"
rm -f "$OUT_SQL"
success "已压缩: $TAR_SQL"

section "清理过期备份（> $KEEP_DAYS 天）"
find ./ -maxdepth 1 -name "${DB_NAME}_*.tar.bz2" -type f -mtime "+$KEEP_DAYS" -print -exec rm {} \;
success "过期备份清理完成"

section "完成"
success "backup.sh 执行完成"
info "解压命令: tar -xjf $OUT_DIR/$TAR_SQL"
