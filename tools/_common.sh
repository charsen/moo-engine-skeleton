# tools/_common.sh — pull.sh / cache.sh 共用工具函数
#
# 用法：
#   #!/usr/bin/env sh
#   set -eu
#   SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
#   . "$SCRIPT_DIR/tools/_common.sh"
#
# 设计原则：
#   - 只放**纯函数 + 一次性副作用**（PATH 守卫）。不存任何业务/项目状态。
#   - 调用者必须先 `set -eu`，本文件不重设。
#   - 调用者必须先 derive SCRIPT_DIR / PROJECT_DIR / ENGINE_DIR；is_production 用 ENGINE_DIR。
#   - 不引入新进程依赖（不用 jq / php / 任何外部命令解析配置）。
#
# 提供：
#   - info / warn / success / error / section  : 5 个打印函数（emoji + 中文 prefix）
#   - require_command <cmd>                     : 缺命令 exit 1 + 装机提示（jq / flock 等）
#   - user_exists <name|uid>                    : id 检查
#   - acquire_lock <file> <名>                  : flock 单实例锁（无 flock 则 WARN 跳过）
#   - is_production                             : .env APP_ENV 判定（带 cache + 引号兼容）
#
# 副作用（source 时自动执行）：
#   - PATH guard: prepend /usr/local/php/bin 等 PHP 路径（如未在 PATH 中）
#
# 历史背景：
#   之前 cache.sh + pull.sh 各自重复实现上述 5 类函数 + PATH 守卫，
#   共 ~50 行重复代码，且 is_production 跟 cache.sh 的 IS_PROD 判定 regex
#   独立维护过一次漂移（pull.sh 不接受 APP_ENV="production" 引号变体，
#   cache.sh 接受），是真 bug。抽出公共工具强制单一真值源。

# ---- 打印函数 5 件套 ------------------------------------------------------
info()    { printf '%s\n' "ℹ️  [INFO] $*"; }
warn()    { printf '%s\n' "⚠️  [WARN] $*" >&2; }
success() { printf '%s\n' "✅ [ OK ] $*"; }
error()   { printf '%s\n' "❌ [ERROR] $*" >&2; }
section() { printf '\n%s\n' "🚀 ==> $*"; }

# ---- 命令依赖检查 + 装机提示 ---------------------------------------------
require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        error "缺少命令: $1"
        case "$1" in
            jq)
                info "装 jq："
                info "  Debian/Ubuntu: apt install -y jq"
                info "  CentOS / RHEL: yum install -y jq"
                info "  ⚠️ Debian/Ubuntu 装时如弹 needrestart dialog → Tab + 选 <Cancel> 避免误重启 php-fpm"
                ;;
            flock)
                info "装 flock：apt install -y util-linux (Debian) / yum install util-linux (CentOS)"
                ;;
            *)
                info "请先安装后再执行本脚本"
                ;;
        esac
        exit 1
    fi
}

# ---- 用户存在性检查 -------------------------------------------------------
# 接受 name 或 uid。`id` 在两种形式下都 work，返回 0 表示该 user 解析成功。
user_exists() {
    id "$1" >/dev/null 2>&1
}

# ---- flock 单实例锁（pull.sh / cache.sh 共用）----------------------------
# 用法：acquire_lock <lock_file> <脚本名>
# 在 fd 9 上持锁，进程退出自动释放（exec 在函数内仍改主 shell 的 fd 表，POSIX 行为，
# 锁活到整个脚本退出，无需 trap 清理）。无 flock（macOS 默认无）→ WARN 跳过并发保护，不阻断。
acquire_lock() {
    _lock_file="$1"
    _lock_name="${2:-脚本}"
    if command -v flock >/dev/null 2>&1; then
        exec 9>"$_lock_file"
        if ! flock -n 9; then
            error "另一个 ${_lock_name} 正在跑（持锁 ${_lock_file}），等它完成或排查后再试"
            exit 1
        fi
    else
        warn "未找到 flock 命令（macOS 默认无），跳过并发保护"
        warn "Linux 生产 box 强烈建议装 util-linux：apt install util-linux / yum install util-linux"
    fi
}

# ---- 生产判定（带 cache + 引号兼容）-------------------------------------
# 依赖：
#   - $ENGINE_DIR (必须，从 caller 来)
#   - $PRODUCTION (可选，=1 时强制判定为 prod，不读 .env)
#
# regex 兼容 .env 里 APP_ENV=production / APP_ENV="production" / APP_ENV='production'
# （Laravel phpdotenv 都会 strip 引号后接受）。这条 regex 是**单一真值源**，cache.sh
# 跟 pull.sh 都从这里走，避免独立维护漂移。
#
# 缓存：第一次解析后存在 `_IS_PRODUCTION_CACHE`，后续直接 return。pull.sh 在
# Step 4 / Step 5.5 / Step 6 多次调用，无 cache 每次都读盘 grep .env。
_IS_PRODUCTION_CACHE=""
is_production() {
    if [ -n "$_IS_PRODUCTION_CACHE" ]; then
        return "$_IS_PRODUCTION_CACHE"
    fi
    if [ "${PRODUCTION:-0}" = "1" ]; then
        _IS_PRODUCTION_CACHE=0
        return 0
    fi
    # 防御：ENGINE_DIR 未 derive 或 .env 还没建（pull.sh 早期 source 本工具时这两个都可能空），
    # 视为"不可判定"→ 临时返回 1（非 prod）但**不写 cache**，让后续 ENGINE_DIR 就位
    # 后的下一次 call 能重新真实判定。否则首次错时序 call 会永久污染 cache，让生产
    # 全程被误判为 dev（composer.json 不切、scaffold-sync 不传 prod flag 等）。
    if [ -z "${ENGINE_DIR:-}" ]; then
        return 1
    fi
    env_file="${ENGINE_DIR}/.env"
    if [ ! -f "$env_file" ]; then
        # .env 不存在但 ENGINE_DIR 已 set → 这是确定状态（dev 首次 clone），可以 cache
        _IS_PRODUCTION_CACHE=1
        return 1
    fi
    if grep -qE "^APP_ENV=['\"]?production['\"]?[[:space:]]*$" "$env_file" 2>/dev/null; then
        _IS_PRODUCTION_CACHE=0
        return 0
    fi
    _IS_PRODUCTION_CACHE=1
    return 1
}

# ---- PATH 守卫（source 时自动执行，纯副作用）-----------------------------
# sudo 默认重置 PATH 到 secure_path（不含 /usr/local/php/bin 等运维装的非标路径），
# 导致 composer / php 走 /usr/bin/php（系统老版本如 update-alternatives 指的 php8.1）。
# 把常见 PHP 安装位置补回 PATH，覆盖 sudo 默认。
#
# 用下划线前缀临时变量避免污染 caller 命名空间，末尾 unset。
for _common_path in /usr/local/php/bin /opt/php/bin /usr/local/opt/php/bin /usr/local/bin; do
    case ":$PATH:" in
        *":$_common_path:"*) ;;
        *) [ -d "$_common_path" ] && PATH="$_common_path:$PATH" ;;
    esac
done
export PATH
unset _common_path
