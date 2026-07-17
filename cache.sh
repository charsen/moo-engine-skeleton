#!/usr/bin/env sh
#
# cache.sh — Laravel 缓存清理 + 权限修复（纯本地，不碰 vendor）
#
# 跟 pull.sh 职责严格分离：
#   pull.sh ：网络层 — git pull / 私包验证 / composer / vendor:publish
#   cache.sh：本地层 — Laravel 缓存（optimize:clear + optimize）+ 目录权限
#
# 何时单独跑 cache.sh：
#   - 改了 .env / config/* / 路由 / view，想刷 Laravel 优化缓存
#   - 改了根目录 *.sh 想修脚本权限
#   - 报 Permission denied 想修目录属主（须 root）
#   - bootstrap/cache 里有死类引用导致 artisan 启不来（本脚本自带轻量自救，见下）
#
# 不该跑 cache.sh 的场景（应走 pull.sh）：
#   - 改了 composer.json / 拉了新 vendor 版本 → pull.sh
#   - 改了 moo-scaffold 源 → pull.sh（自动 publish 副本 + 刷 autoload）
#
# vendor 不在 → 报错让用户走 pull.sh（避免误装 dev 依赖 + 跳过私包权限验证）
#
# 退出码（cron 监控按这个写告警规则）：
#   0    完全成功
#   1    vendor/autoload.php 缺失 / 生产探测不到 web user / touch 失败 / flock 持锁
#   130  被 INT / TERM 中断（trap 处理后），上层最好重跑
#
# 流程总览：
#   1 检查 / 建 storage 与 prototypes 软链接
#   2 准备可写目录 + 预创建当日+次日 daily log（artisan 前，治 00:00 翻篇 first-writer-wins）
#   3 重建 storage/framework/cache/data
#   4 刷新 Laravel 优化缓存（optimize:clear + optimize）
#   5 修正根目录 *.sh 权限（仅 prod root，750 + chgrp web group）
#   6 兜底 chown -R + setgid + 文件 mode（→ web user；public/ 和 vendor/ 除外）
#   7 reload php-fpm（清 OPcache realpath 失败缓存）
#   8 M3 校验 vendor/charsen/moo-scaffold 实体目录（仅 prod）

# 确保脚本在出错和未定义变量时立即退出
set -eu

# umask 002：本脚本进程内所有 touch / artisan / chmod 创建的文件都是 664（group 可写）。
# 默认 022 → 644 → group 不能写 → 别的 user 来 append 必挂。
# 这是 daily log first-writer-wins 反复发作的关键之一（另一个是 setgid，见 set_tree_permissions）。
umask 002

# shellcheck disable=SC1007  # CDPATH= cd 是惯用法（给 cd 临时清空 CDPATH 防乱跳），非手滑空格
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_DIR="$SCRIPT_DIR"
# Laravel 后端子目录（默认 engine/，跟 pull.sh 一致；--engine-subdir / 同名 env 可覆盖）。
# ENGINE_DIR 在 arg 解析之后才 derive（见下），这里只定默认值。
ENGINE_SUBDIR="${ENGINE_SUBDIR:-engine}"
CURRENT_USER=$(id -un 2>/dev/null || printf '%s' "unknown")

# ---- 参数解析（在 tools/_common.sh source 之前，避免错参数把流程带歪）-----
# --tips    : 强制打末段 tip 教学块（覆盖 cron 非 tty 默认抑制）
#             默认行为：tty 跑打 tip / cron(pipe) 跑不打。--tips 强制打开。
SHOW_TIPS=0
while [ $# -gt 0 ]; do
    case "$1" in
        --tips)        SHOW_TIPS=1; shift;;
        --engine-subdir)    [ $# -ge 2 ] || { printf '%s\n' "❌ --engine-subdir 需要一个目录名参数" >&2; exit 1; }
                            ENGINE_SUBDIR="$2"; shift 2;;
        --engine-subdir=*)  ENGINE_SUBDIR="${1#*=}"; shift;;
        -h|--help)
            printf '%s\n' "用法：sh cache.sh [选项]"
            printf '%s\n' ""
            printf '%s\n' "选项："
            printf '%s\n' "  --tips               强制打末段 tip 教学块（覆盖 cron 非 tty 自动抑制）"
            printf '%s\n' "  --engine-subdir DIR  Laravel 后端子目录名（默认 engine，跟 pull.sh 一致）"
            printf '%s\n' "  -h, --help           显示本帮助"
            printf '%s\n' ""
            printf '%s\n' "环境变量（escape hatch）："
            printf '%s\n' "  WEB_USER=app sudo -E sh cache.sh   强制注入 web user 跳过自动探测"
            printf '%s\n' "  LOG_KEEP_DAYS=30 sh cache.sh       清 storage/logs 下 N 天前 *.log（默认 30，0=不清）"
            exit 0
            ;;
        *)
            printf '%s\n' "❌ 未知参数: $1（用 --help 看用法）" >&2
            exit 1
            ;;
    esac
done

# ENGINE_DIR 在 arg 解析后才 derive（--engine-subdir 可覆盖默认 engine，跟 pull.sh 同款）。
ENGINE_DIR="$PROJECT_DIR/$ENGINE_SUBDIR"

# 加载共用工具（info/warn/success/error/section + require_command + user_exists +
# is_production + PATH 守卫）。tools/_common.sh 是 pull.sh 跟 cache.sh 的单一真值源，
# 避免之前各写一份导致 IS_PROD regex 漂移之类的 bug。
# 显式存在性检查：比 sh "No such file" 默认错友好，提醒运维 tools/_common.sh 必须随脚本部署。
if [ ! -f "$SCRIPT_DIR/tools/_common.sh" ]; then
    printf '%s\n' "❌ [ERROR] $SCRIPT_DIR/tools/_common.sh 缺失" >&2
    printf '%s\n' "    pull.sh / cache.sh 共用此公共工具，缺则两脚本同时挂。" >&2
    printf '%s\n' "    确认 git pull 已拉到最新（tools/_common.sh 入 git）+ 部署时带上 tools/ 整目录。" >&2
    exit 1
fi
# shellcheck source=tools/_common.sh
. "$SCRIPT_DIR/tools/_common.sh"

# 全局状态默认值（统一在脚本顶部声明，set -u 下任何分支访问都安全）。
# WEB_USER_FALLBACK : 1 = dev 探测失败 fallback 到 $CURRENT_USER；0 = 真探测到独立 web user
WEB_USER_FALLBACK=0

# ---- flock 防并发（跟 pull.sh 对称）---------------------------------------
# 场景：两个 `sudo sh cache.sh` 并发跑（运维手抖双击 / cron 撞手动 / pull.sh 调
# cache.sh 同时另一终端独立跑）。两进程同时 optimize:clear + chown -R 会撞 inode
# 状态，setgid 位 race。pull.sh 用 pull.lock，这里独立用 cache.lock 不互相阻塞。
CACHE_LOCK_FILE="${CACHE_LOCK_FILE:-/tmp/$(basename "$PROJECT_DIR")-cache.lock}"
acquire_lock "$CACHE_LOCK_FILE" "cache.sh"

# ---- trap：INT / TERM 信号 ------------------------------------------------
# 运维 Ctrl-C / OOM killer / systemd timeout 杀掉 cache.sh，提示重跑而不是让运维
# 以为"我都 Ctrl-C 了应该没事"。合并一个 handler，exit 130 让上层据退出码重跑。
# 注：旧版 trap EXIT 兜底 chown 已砍 —— 那是窄场景（artisan 中途失败留 root garbage），
# 靠重跑 sudo sh cache.sh 即修，不值得维护一个全局标志 + 兜底分支。
cache_sh_on_signal() {
    printf '%s\n' "⚠️  [WARN] cache.sh 被中断（${1}），状态可能半态" >&2
    printf '%s\n' "⚠️  [WARN] 建议重跑 sudo sh cache.sh 完成 chown / setgid / reload" >&2
    exit 130
}
trap 'cache_sh_on_signal SIGINT' INT
trap 'cache_sh_on_signal SIGTERM' TERM

ensure_dir() {
    mkdir -p "$1"
}

clear_dir_contents() {
    ensure_dir "$1"
    find "$1" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
}

ensure_cache_gitignore() {
    cache_gitignore="$ENGINE_DIR/storage/framework/cache/data/.gitignore"
    session_gitignore="$ENGINE_DIR/storage/framework/sessions/.gitignore"

    if [ -f "$session_gitignore" ]; then
        cp "$session_gitignore" "$cache_gitignore"
        return 0
    fi

    printf '%s\n%s\n' "*" "!.gitignore" > "$cache_gitignore"
}

set_tree_permissions() {
    ensure_dir "$1"
    # 2775 = setgid + rwxrwxr-x。setgid 是治本：新文件自动继承"目录的 group"，
    # 不继承"创建者的 primary group"。配合 chown -R 把目录 group 钉成 web group，
    # 之后任何用户（_www / root / charsen / cron）在该目录新建文件，文件 group 都
    # 是 _www，配合 664（group 可写）→ PHP-FPM 永远 append 得进去，彻底消灭
    # daily log "谁先写谁拥有" 的 group race。
    find "$1" -type d -exec chmod 2775 {} +
    find "$1" -type f -exec chmod 664 {} +
}

# 已知会按 daily channel 落盘的日志 prefix（来自 engine/config/logging.php）。
# 新增 daily channel 时这里同步加一行。不抓 config 因为那要 bootstrap app，
# 反而可能比本脚本早一步触发 log 写入，搬起石头砸自己脚。
# 注：旧版 verify_daily_channel_parity（grep 启发式对账）已砍 —— grep 会把注释里
# 的样例 'driver' => 'daily' 误算成漂移，本身带 false-positive；新增 channel 是
# 低频开发事件，dev 改 logging.php 时顺手同步这里即可，不值得脚本每跑都对账。
# 骨架 engine/config/logging.php 的三个 daily channel：daily(laravel-)、auth(auth-)、dev(dev-)。
# 各 host 若新增 daily channel（如业务侧 notify/sql 等），在这里同步补 basename。
DAILY_LOG_BASENAMES="laravel auth dev"           # storage/logs/<prefix>-YYYY-MM-DD.log
# 嵌套 daily channel（path 落在子目录时）示例，骨架暂无：
#   DAILY_LOG_NESTED="sql/query"                 # storage/logs/sql/query-YYYY-MM-DD.log
DAILY_LOG_NESTED=""
# 旧 log 保留天数：清理 storage/logs 下 mtime 超过这么多天的 *.log（递归，含嵌套 sql/）。
# 整合自旧 delLogs.sh（原 7 天 → 30 天）；env 可调，设 0 或空 = 不清。
LOG_KEEP_DAYS=${LOG_KEEP_DAYS:-30}

predcreate_daily_logs() {
    logs_dir="$ENGINE_DIR/storage/logs"
    ensure_dir "$logs_dir"

    today=$(date +%Y-%m-%d)
    # GNU date (Linux 生产) 优先，BSD date (macOS 开发) 兜底；都失败就只 touch 今天。
    # 这条双写是 mac/linux 双环境承重，不是镀金 —— 两个环境都真实跑本脚本。
    tomorrow=$(date -d "+1 day" +%Y-%m-%d 2>/dev/null \
               || date -v+1d +%Y-%m-%d 2>/dev/null \
               || printf '')

    info "预创建当日 + 次日 daily log（消灭凌晨翻篇 first-writer-wins）"
    # touch 失败时 set -e 会直接退出，但默认无任何说明，运维只看到脚本"在中段莫名挂"。
    # 显式诊断：磁盘满 / inode 满 / 目录权限错都是真实生产长跑会撞的。
    touch_or_die() {
        target="$1"
        if ! touch "$target" 2>/dev/null; then
            error "touch 失败：${target}"
            error "→ 检查磁盘：df -h \"$(dirname "$target")\""
            error "→ 检查 inode：df -i \"$(dirname "$target")\""
            error "→ 检查目录权限：ls -ld \"$(dirname "$target")\""
            exit 1
        fi
    }
    for base in $DAILY_LOG_BASENAMES; do
        touch_or_die "$logs_dir/${base}-${today}.log"
        [ -n "$tomorrow" ] && touch_or_die "$logs_dir/${base}-${tomorrow}.log"
    done
    for nested in $DAILY_LOG_NESTED; do
        nested_dir="$logs_dir/$(dirname "$nested")"
        nested_base=$(basename "$nested")
        ensure_dir "$nested_dir"
        touch_or_die "$nested_dir/${nested_base}-${today}.log"
        [ -n "$tomorrow" ] && touch_or_die "$nested_dir/${nested_base}-${tomorrow}.log"
    done
}

# 清理 storage/logs 下 LOG_KEEP_DAYS 天前的旧 *.log（递归，含 sql/ 等嵌套 daily channel）。
# 整合自旧 delLogs.sh。今天/明天的 log 刚被 predcreate、太新，-mtime 命不中，不会误删。
# nginx 访问日志不在此列（按约定交给 logrotate；cache.sh 跨项目通用，不碰外部硬编码路径）。
clean_old_logs() {
    logs_dir="$ENGINE_DIR/storage/logs"
    [ -d "$logs_dir" ] || return 0
    case "$LOG_KEEP_DAYS" in
        ''|0) info "LOG_KEEP_DAYS=${LOG_KEEP_DAYS:-空}，跳过旧 log 清理"; return 0 ;;
    esac
    old_n=$(find "$logs_dir" -type f -name '*.log' -mtime +"$LOG_KEEP_DAYS" 2>/dev/null | wc -l | tr -d ' ')
    if [ "$old_n" -gt 0 ]; then
        find "$logs_dir" -type f -name '*.log' -mtime +"$LOG_KEEP_DAYS" -delete 2>/dev/null || true
        success "🧹 已清理 ${old_n} 个 ${LOG_KEEP_DAYS} 天前的旧 log"
    else
        info "无 ${LOG_KEEP_DAYS} 天前的旧 log，无需清理"
    fi
}

ensure_storage_link() {
    storage_link="$ENGINE_DIR/public/storage"
    storage_target="$ENGINE_DIR/storage/app/public"

    ensure_dir "$ENGINE_DIR/public"
    ensure_dir "$storage_target"

    # 是软链接 / 已存在（任何形态）就跳过，不去校验"目标符不符" —— 过度分支，
    # 真出问题运维 ls -l public/storage 一眼看得出，脚本不替它做决策。
    if [ -L "$storage_link" ] || [ -e "$storage_link" ]; then
        info "public/storage 已存在，跳过创建。"
        return 0
    fi

    ln -s "$storage_target" "$storage_link"
    success "已创建 public/storage -> storage/app/public 软链接"
}

ensure_prototypes_link() {
    prototypes_source="$PROJECT_DIR/prototypes"
    prototypes_link="$ENGINE_DIR/public/prototypes"

    if [ ! -d "$prototypes_source" ]; then
        info "根目录 prototypes 不存在，跳过 public/prototypes 软链接。"
        return 0
    fi

    ensure_dir "$ENGINE_DIR/public"

    if [ -L "$prototypes_link" ]; then
        link_target=$(readlink "$prototypes_link" 2>/dev/null || printf '')
        if [ "$link_target" = "$prototypes_source" ]; then
            info "public/prototypes 已指向根目录 prototypes，跳过创建。"
        else
            warn "public/prototypes 已是软链接，但指向 ${link_target:-unknown}，跳过覆盖。"
            warn "如需切换，请人工确认后删除旧链接再重跑 cache.sh。"
        fi
        return 0
    fi

    if [ -e "$prototypes_link" ]; then
        if [ -d "$prototypes_link" ] && [ -z "$(find "$prototypes_link" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
            rmdir "$prototypes_link"
        else
            warn "public/prototypes 已存在且不是软链接，跳过创建，避免覆盖已有原型文件。"
            warn "如需启用根目录 prototypes 访问，请先迁移/删除 $prototypes_link 后重跑 cache.sh。"
            return 0
        fi
    fi

    ln -s "$prototypes_source" "$prototypes_link"
    success "已创建 public/prototypes -> 根目录 prototypes 软链接"
}

# web user 探测：先扫【运行中的 php-fpm worker 进程】拿真实 user，常见列表只作兜底。
# 为什么不能只靠常见列表（v3 初版踩的坑）：生产 fleet 上 www 和 www-data 可能【同时存在】，
# 列表会挑错（www-data 排在 www 前 → 命中 www-data，但 fpm 实际跑 www）→ chown 到错 user
# → www-fpm 仍报 append failed。只有扫运行进程才知道 fpm 真身。（生产 fleet 实际踩坑。）
# 仍探测不到时按 prod/dev 显式分流（见调用点），不退化到 $CURRENT_USER。
detect_web_user() {
    # 1) 运行中的 php-fpm worker 真实 user（master 是 root，worker 才是 web user）——最权威。
    #    comm 匹配 php-fpm；排除 root（master）；queue:work 的 comm 是 php，不会误命中。
    fpm_user=$(ps -eo user=,comm= 2>/dev/null \
               | awk '$2 ~ /php-fpm/ && $1 != "root" { print $1; exit }')
    if [ -n "$fpm_user" ] && user_exists "$fpm_user"; then
        printf '%s\n' "$fpm_user"
        return 0
    fi

    # 2) 兜底：常见 web user 列表（裸 box / fpm 没在跑时）。www 优先于 www-data。
    for candidate in www _www www-data nginx apache nobody; do
        if user_exists "$candidate"; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done

    # 探测全失败：不退化到 $CURRENT_USER。让上层按 prod/dev 显式分流。
    # 旧逻辑（退化到 $CURRENT_USER）会让 prod 误用 `sh cache.sh`（没 sudo）时把开发者
    # user 当 web user，末段 tip 进一步把这个用户拼进 "sudo crontab -u <user> -e"，运维照抄就装错了。
    return 1
}

detect_web_group() {
    user_name="$1"
    group_name=$(id -gn "$user_name" 2>/dev/null || true)

    if [ -n "$group_name" ]; then
        printf '%s\n' "$group_name"
        return 0
    fi

    printf '%s\n' "$user_name"
}

if [ ! -d "$ENGINE_DIR" ]; then
    error "目录不存在: $ENGINE_DIR"
    exit 1
fi

require_command php
# 注：composer 段早已搬到 pull.sh Step 5（composer install/update 集中处理），
# 这里不再 require composer，让纯 PHP 环境（裸 box / 容器内不装 composer）也能跑
# cache.sh 刷缓存 + 修权限。

# 显式打印实际选中的 php，方便运维 debug "PHP 版本与生产配置不匹配" 的悬案。
# 尤其当 sudo secure_path 跟 PATH 守卫双层叠加，跑出来的 php 不一定是预期那个。
info "使用 php: $(command -v php) ($(php -v 2>/dev/null | head -1))"

# 判定 prod：走 tools/_common.sh 的 is_production()，单一真值源，跟 pull.sh 同样走这个函数。
IS_PROD=0
if is_production; then
    IS_PROD=1
fi

# Escape hatch：允许运维在 detect 走不通时用环境变量强制注入 web user，
# `sudo -E WEB_USER=app sh cache.sh` 或 `WEB_USER=app sudo -E sh cache.sh`。
# 必须在 detect_web_user 调用之前判断，否则下一行的无条件赋值会覆盖外部注入。
# set -u 下用 ${WEB_USER:-} 防 unbound；user_exists 兜一手别让运维输错用户名沉默通过。
if [ -n "${WEB_USER:-}" ] && user_exists "$WEB_USER"; then
    info "使用环境变量注入的 WEB_USER=${WEB_USER}（跳过自动探测）"
else
    WEB_USER=$(detect_web_user || true)
fi
WEB_GROUP=""

if [ -n "$WEB_USER" ]; then
    WEB_GROUP=$(detect_web_group "$WEB_USER")
    info "检测到 web 用户: $WEB_USER:$WEB_GROUP"
elif [ "$IS_PROD" -eq 1 ]; then
    # prod 探测不到 web user：直接 fail，不要假修。允许退化到 $CURRENT_USER 会让运维
    # 看到一片绿后回头才发现 chown 跳过、tip 拼错用户、log 仍报 append failed。
    error "生产环境探测不到 web 用户（扫 php-fpm 运行进程 + 常见 user 列表 www/_www/www-data/nginx/apache/nobody 全失败）"
    error "→ 拒绝退化到当前用户 ${CURRENT_USER}（避免把开发者 user 当 web user，下游一连串误导）"
    error "→ 排查：ps aux | grep -E 'php-fpm|nginx'"
    error "→ 或显式注入：sudo -E WEB_USER=app sh cache.sh（escape hatch 已在脚本顶部生效）"
    exit 1
else
    # 非生产（dev）：探测失败 fallback 到 $CURRENT_USER，但显式标记，tip 段会改文案
    WEB_USER="$CURRENT_USER"
    WEB_GROUP=$(detect_web_group "$WEB_USER")
    WEB_USER_FALLBACK=1
    info "未探测到独立 web 用户，dev 环境 fallback 到当前用户 ${WEB_USER}:${WEB_GROUP}"
fi

# 提前告警：非 root 跑则后续 chown 段会整段跳过 — 放在第一屏，避免用户跑完才在末尾
# 看到 storage/logs 仍报 Permission denied 一脸懵。压到 2-3 行核心信息。
if [ "$(id -u)" -ne 0 ]; then
    warn "当前用户不是 root（uid=$(id -u) ${CURRENT_USER}），chown 段将整段跳过。"
    warn "生产侧若 storage/logs 报 Permission denied（append mode），请改用：sudo sh cache.sh"
    warn "（escape hatch：sudo -E WEB_USER=app sh cache.sh 强制注入 web user 跳过探测）"
fi

section "🔗 检查 storage / prototypes 软链接"
ensure_storage_link
ensure_prototypes_link

cd "$ENGINE_DIR"

# 兜底：vendor 不在 → 报错让用户走 pull.sh（cache.sh 不做 install，避免误装 dev
# 依赖到生产 + 跳过私包权限验证）。这是 pull.sh / cache.sh 职责边界。
if [ ! -f "$ENGINE_DIR/vendor/autoload.php" ]; then
    error "未发现 vendor/autoload.php，cache.sh 不做 composer install。"
    info "请先跑 sh pull.sh（含私包权限验证 + composer install 全套）。"
    exit 1
fi

section "📂 准备可写目录 + 预创建 daily log + 清旧 log（必须在 artisan 之前）"
# 顺序关键点（双场景行为不同）：
#   场景 A：独立跑 sh cache.sh —— predcreate 真正占首写：先 touch 出 web user owned
#     的当天 log，artisan optimize:clear 紧接着 append（不重新创建），owner 保持 web user。
#   场景 B：被 pull.sh 调 —— 当天 log 早被 root 创建锁定 owner=root，predcreate 退化
#     为 noop，真正起作用的是下面"预 chown"段（把 root owned 当天 log 改回 web user）。
#   两个场景都靠最末段 chown -R 兜 bootstrap/cache（artisan 写出的 .php 仍归 root）。
ensure_dir "$ENGINE_DIR/storage/logs"
ensure_dir "$ENGINE_DIR/bootstrap/cache"
ensure_dir "$ENGINE_DIR/scaffold/api/history"
ensure_dir "$ENGINE_DIR/scaffold/acl"
# runtimes / sql-slows 已云端化、本地仅临时缓冲：recorder 自建桶（mkdir 0775，PHP-FPM 身份），
# 不再在此 ensure_dir/chown（与一直如此的 sql-slows 对齐）。
# api/debug 已随「完成签收」功能下线（moo-scaffold 3.10.0）删除，不再 ensure/touch/chown。

predcreate_daily_logs

# 清理 LOG_KEEP_DAYS 天前的旧 log（整合自 delLogs.sh；放预建之后，今天/明天的不会被误删）
clean_old_logs

# 同样关键：在 artisan 之前先把今日 log 文件 chown 给 web user。这样 artisan 跑时
# Monolog 直接对一个已经是 web user 所有的文件 append（umask 002 + setgid 兜底
# group 可写），文件状态从一开始就正确，而不是事后 chown 修补。
#   - 用 find -exec 替代 glob，避免 POSIX sh 无 nullglob 时 "*.log" 字面量进 chown。
#   - 同时 chown + setgid 整个 storage/logs 树（含 sql/ 子目录本身）。fresh clone
#     第一次 sudo 跑时，predcreate 创建的 sql/ 目录 owner=root，artisan boot 时
#     fopen('sql/query-...') 会因父目录非 web group → 失败。早一步钉死，artisan 不挂。
if [ "$(id -u)" -eq 0 ] && [ -n "$WEB_USER" ] && [ -n "$WEB_GROUP" ]; then
    info "预 chown 今日 log 文件 + setgid storage/logs 子树 → ${WEB_USER}:${WEB_GROUP}"
    find "$ENGINE_DIR/storage/logs" -exec chown "$WEB_USER:$WEB_GROUP" {} +
    find "$ENGINE_DIR/storage/logs" -type d -exec chmod 2775 {} +
fi

section "🗑️  重建 Laravel 缓存目录"
clear_dir_contents "$ENGINE_DIR/storage/framework/cache/data"
ensure_cache_gitignore
success "storage/framework/cache/data 已重建"

section "🧹 刷新 Laravel 优化缓存"
# 轻量物理删自救：bootstrap/cache 里若有死类引用（如 Target [...] is not instantiable），
# artisan 自己都 boot 不起来，optimize:clear 同样会炸 → 单独跑 cache.sh 也救不了。
# 先物理删掉缓存的 *.php，让 artisan 能起来。
# 权衡：**只删 *.php，不碰 .gitignore** —— 旧版 rm -rf 整目录会误删 git tracked 的
# bootstrap/cache/.gitignore，导致后续生成 .php 不被 ignore、git status 假阳性。
find "$ENGINE_DIR/bootstrap/cache" -maxdepth 1 -name '*.php' -type f -delete 2>/dev/null || true

# clear-compiled 砍掉 — optimize:clear 已包含（cached bootstrap / config / cache /
# compiled / events / routes / views 全清）。静默 artisan 输出（Laravel 11+ 默认每个
# cache name 打一行 "xxx ... DONE" 噪音），失败时 dump 完整输出 + die。
if ! out=$(php artisan optimize:clear 2>&1); then
    printf '%s\n' "$out" >&2
    error "php artisan optimize:clear 失败"
    exit 1
fi
if ! out=$(php artisan optimize 2>&1); then
    printf '%s\n' "$out" >&2
    error "php artisan optimize 失败"
    exit 1
fi
success "🧹 Laravel 缓存刷新完成（optimize:clear + optimize）"

section "🔐 修正脚本权限（仅生产 root 跑）"
# 只在 root + 检测到 WEB_GROUP + 非 dev fallback 时跑，避免本地开发跑 cache.sh 改 mode
# 导致 git tracked mode（100644/100755）漂移 → git status 假阳性。
#
# 隐性约束：mode 750 = owner rwx + group rx + other 全 0。意味着 scaffold-cron 等
# 把 *.sh 装在 crontab 里的脚本，其执行用户**必须是 ${WEB_GROUP} 成员**（或就是
# ${WEB_USER} 本人），否则 cron 触发即 permission denied。装 cron 时核对：
#   id <cron_user> 看输出含 ${WEB_GROUP}；如不含：usermod -aG ${WEB_GROUP} <cron_user>
if [ "$(id -u)" -eq 0 ] && [ -n "$WEB_GROUP" ] && [ "${WEB_USER_FALLBACK:-0}" -eq 0 ]; then
    for script_path in "$PROJECT_DIR"/*.sh; do
        [ -f "$script_path" ] || continue
        chmod 750 "$script_path"
        chgrp "$WEB_GROUP" "$script_path"
    done
    success "根目录 shell 脚本权限已更新为 750 + chgrp ${WEB_GROUP}（生产 cron 友好）"
else
    info "（非 root / 无真实 web user）跳过 chmod，避免 git tracked mode 漂移"
fi

section "👮 兜底 chown + 修正目录 setgid + 文件 mode"
# 在 artisan 之后再 chown -R 一次：artisan optimize 会在 bootstrap/cache/ 生成
# config.php / routes-v7.php / packages.php / services.php 等，sudo 场景以 root 创建，
# 需要补 chown 回 web user。上面"预 chown"只动 storage/logs 当天 log，这里是完整覆盖。
#
# **不动 public/**：public/index.php / .htaccess 等是 git tracked mode 0644，
# set_tree_permissions 改 0664 会让 git status 暴露 modified。public/storage 软链接
# 终端在 storage/app/public（已含在 storage 树），无需单独 chown public/。
# **不动 vendor/**：composer 装包是 root，vendor 全 root:root 0644，PHP-FPM 只需读，
# other 位 r 已满足。不进 chown 范围避免 chown -R 跑数千文件浪费时间。
if [ "$(id -u)" -eq 0 ]; then
    # WEB_USER/WEB_GROUP 走到这里必非空（prod 探测失败已 exit 1，dev fallback 赋 $CURRENT_USER）
    info "执行 chown -R ${WEB_USER}:${WEB_GROUP} ..."
    chown -R "$WEB_USER:$WEB_GROUP" \
        "$ENGINE_DIR/storage" \
        "$ENGINE_DIR/bootstrap/cache" \
        "$ENGINE_DIR/scaffold/api/history" \
        "$ENGINE_DIR/scaffold/acl"
    success "属主已修正为 ${WEB_USER}:${WEB_GROUP}（public/ 和 vendor/ 见上方注释解释为何排除）"
else
    warn "当前用户不是 root，已跳过 chown -R 兜底（artisan 创建的 bootstrap/cache 文件归当前用户）"
    warn "→ 生产侧：sudo sh cache.sh"
fi

info "统一设置 storage、bootstrap/cache、scaffold 目录权限。"
set_tree_permissions "$ENGINE_DIR/storage"
set_tree_permissions "$ENGINE_DIR/bootstrap/cache"
set_tree_permissions "$ENGINE_DIR/scaffold/api/history"
set_tree_permissions "$ENGINE_DIR/scaffold/acl"
success "目录权限已修正为目录 775 / 文件 664"

section "⚡ reload php-fpm（清 OPcache + realpath_cache 的 fopen 失败缓存）"
# 治本：worker 在 00:00 第一次 fopen 失败（如老 log 文件 root:root 644）后，OPcache
# 把"不可写"状态缓存住，即便此后 chown 修对了，那个 worker 进程仍报 append failed，
# 直到 pm.max_requests 触发回收 / 手动 reload。这是"cache.sh 跑完仍报"的隐性原因。
# 这台 box 是 systemd —— 只走 systemctl，不做 sysvinit / 容器 SIGUSR2 兼容（镀金已砍）。
# reload 是 graceful（不断连接），失败不影响 cache.sh 退出，只 WARN 提示兜底动作。
if [ "$(id -u)" -eq 0 ] && command -v systemctl >/dev/null 2>&1; then
    fpm_service=""
    # 短列表：php-fpm（自编译 / Arch）+ php8.2-fpm（本 box PHP 8.2）。
    for svc in php-fpm php8.2-fpm; do
        if systemctl is-active --quiet "$svc" 2>/dev/null; then
            fpm_service="$svc"
            break
        fi
    done
    # 动态兜底：上面 list 没命中 → systemctl 列出所有 active 含 fpm 的 unit（cheap robust）
    if [ -z "$fpm_service" ]; then
        fpm_service=$(systemctl list-units --type=service --state=active --no-legend 2>/dev/null \
                      | awk '/^[[:space:]]*(php|fpm)/ && /fpm/ {print $1; exit}')
    fi
    if [ -n "$fpm_service" ]; then
        info "执行 systemctl reload ${fpm_service}"
        if systemctl reload "$fpm_service" 2>/dev/null; then
            success "${fpm_service} 已 reload（php-fpm worker 全部重启，新 worker 不带旧 realpath_cache）"
            # queue worker / supervisor 等长进程不受 php-fpm reload 影响 —— 最易被忘，单独提示。
            info "⚠️  注意：queue worker / supervisor 等长进程不受 php-fpm reload 影响。"
            info "    若仍报 append failed，需单独 restart：sudo systemctl restart laravel-worker"
            info "    或走 Laravel 内置：cd ${ENGINE_DIR} && php artisan queue:restart"
        else
            warn "${fpm_service} reload 失败 — worker 仍持旧 fopen 失败缓存"
            warn "兜底：sudo systemctl restart ${fpm_service}（会瞬断 1-2s）/ sh opcache.sh"
        fi
    else
        info "未发现 active 的 php-fpm 服务，跳过 reload（如手动起的 php-fpm，请自行 reload）"
    fi
else
    info "非 root / 无 systemctl，跳过 php-fpm reload"
    info "（如生产报 'could not be opened in append mode' 即便 chown 修对仍未消：sudo systemctl reload php-fpm）"
fi

# M3: vendor/charsen/moo-scaffold 实体校验（生产环境必须是实体目录，不是 symlink）
# 走 tools/_common.sh 的 is_production()，跟前面 IS_PROD 判定走同一函数。cheap 信号。
if is_production; then
    MOO_VENDOR="$ENGINE_DIR/vendor/charsen/moo-scaffold"
    if [ -L "$MOO_VENDOR" ]; then
        warn "⚠️  生产 vendor/charsen/moo-scaffold 是 symlink（应为实体目录）"
        warn "   表明 composer 走了 path repo（开发模式）而非 vcs gitee。"
        warn "   检查 engine/composer.json repositories.scaffold.type 是 'vcs' 还是 'path'。"
    elif [ ! -d "$MOO_VENDOR" ]; then
        warn "⚠️  生产 vendor/charsen/moo-scaffold 完全缺失 — 跑 sudo sh pull.sh"
    else
        info "vendor/charsen/moo-scaffold 是实体目录（生产正确状态）"
    fi
fi

section "🎉 完成"
success "🎉 cache.sh 执行完成"

info "如仍有缓存异常，可继续检查: cd engine && php artisan about"
info "如仍有 OPcache 残留，可继续执行: sh opcache.sh"
info "如 scaffold 数据未刷新，可继续执行: cd engine && php artisan moo:fresh"

# cron 跑（非交互终端）时不打教学 tip，避免每次跑都刷屏 cron 日志，真 WARN 被淹没。
# 显式 `sudo sh cache.sh --tips` 可强制打开。判定 `[ -t 1 ]` = stdout 是 tty。
if [ ! -t 1 ] && [ "$SHOW_TIPS" != "1" ]; then
    exit 0
fi

info ""
if [ "${WEB_USER_FALLBACK:-0}" -eq 1 ]; then
    info "🛡️  daily log 治本建议（cache.sh 之外、只做一次）："
    info "  本次未探测到独立 web user（dev fallback 到 ${WEB_USER}），跳过生产侧建议。"
    info "  上 prod 跑 cache.sh 时这里会给出针对真实 web user 的具体 crontab / systemd 命令。"
else
    info "🛡️  daily log 治本（cache.sh 之外、只做一次，详情见 DEPLOY-CHECKLIST.md）："
    info "  1) Laravel scheduler crontab 用 web user (${WEB_USER}) 跑（不要 root）"
    info "  2) queue worker / supervisor / systemd 的 User= 字段配 ${WEB_USER}（不要 root）"
    info "  3) PHP-FPM systemd drop-in 显式 UMask=0002"
    info "  4) ${WEB_USER} crontab 加 '55 23 * * * cd ${PROJECT_DIR} && sh cache.sh' 每天预创建次日 log"
fi
