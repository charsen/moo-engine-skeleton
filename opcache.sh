#!/usr/bin/env sh

# opcache.sh — 精准失效 OPcache(只 invalidate 变更的 .php,不全 reset)
#
# 用法:
#   sh opcache.sh                 # 默认对比最近一次 commit
#   sh opcache.sh HEAD~3..HEAD    # 自定义提交范围
#
# 做什么:
#   1) opcache_reset() 全清(兜底)
#   2) git diff --name-only <range> -- '*.php' → opcache_invalidate(file, true) 逐个
#
# 前置:
#   - PHP CLI 启用 OPcache(opcache.enable_cli=1),否则函数不存在直接退
#
# 注意:
#   - 走 PHP CLI 调 OPcache,跟 php-fpm 的 OPcache 池独立
#   - 线上 php-fpm 改完 .php 后仍要 systemctl reload php-fpm(或 cachetool)

# 出错和未定义变量时立即退出
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_DIR="$SCRIPT_DIR"
DIFF_RANGE=${1:-}

info() {
    printf '%s\n' "ℹ️  [INFO] $*"
}

warn() {
    printf '%s\n' "⚠️  [WARN] $*" >&2
}

success() {
    printf '%s\n' "✅ [ OK ] $*"
}

error() {
    printf '%s\n' "❌ [ERROR] $*" >&2
}

section() {
    printf '\n%s\n' "🚀 ==> $*"
}

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        error "缺少命令: $1，请先安装后再执行 opcache.sh"
        exit 1
    fi
}

list_changed_php_files() {
    if [ -n "$1" ]; then
        git diff --name-only "$1" -- '*.php'
        return 0
    fi

    if git rev-parse --verify HEAD~1 >/dev/null 2>&1; then
        git diff --name-only HEAD~1 HEAD -- '*.php'
        return 0
    fi

    if git rev-parse --verify HEAD >/dev/null 2>&1; then
        git show --pretty='' --name-only HEAD -- '*.php'
        return 0
    fi

    return 0
}

require_command git
require_command php

if [ ! -d "$PROJECT_DIR/.git" ]; then
    error "当前目录不是 git 仓库根目录: $PROJECT_DIR"
    exit 1
fi

cd "$PROJECT_DIR"

section "检查运行环境"
branch_name=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || printf '%s' "unknown")
info "当前分支: $branch_name"
if [ -n "$DIFF_RANGE" ]; then
    info "自定义对比范围: $DIFF_RANGE"
else
    info "默认对比范围: 最近一次提交"
fi

if ! php -r 'exit(function_exists("opcache_reset") && function_exists("opcache_invalidate") ? 0 : 1);'; then
    error "当前 PHP 未启用 OPcache 相关函数，无法执行 reset / invalidate"
    exit 1
fi

warn "该脚本通过 PHP CLI 调用 OPcache；若线上运行在 php-fpm，必要时仍需额外重载 php-fpm。"

section "执行 opcache_reset"
reset_result=$(php -r 'var_export(opcache_reset());')
info "opcache_reset() => $reset_result"
if [ "$reset_result" = "true" ]; then
    success "OPcache reset 已执行"
else
    warn "opcache_reset 返回 false，下面仍会继续按文件尝试 invalidate"
fi

section "查找变更的 PHP 文件"
changed_files=$(list_changed_php_files "$DIFF_RANGE" | awk 'NF' | sort -u)
if [ -z "$changed_files" ]; then
    warn "未找到需要处理的 PHP 文件"
    info "可通过自定义范围执行，例如: sh opcache.sh HEAD~3..HEAD"
    exit 0
fi

file_count=$(printf '%s\n' "$changed_files" | awk 'NF {count++} END {print count + 0}')
info "共找到 $file_count 个 PHP 文件"

section "执行 opcache_invalidate"
printf '%s\n' "$changed_files" | while IFS= read -r relative_file; do
    [ -n "$relative_file" ] || continue

    absolute_file="$PROJECT_DIR/$relative_file"
    if [ ! -f "$absolute_file" ]; then
        warn "文件不存在，可能已删除，已跳过: $relative_file"
        continue
    fi

    invalidate_result=$(php -r 'var_export(opcache_invalidate($argv[1], true));' "$absolute_file")
    if [ "$invalidate_result" = "true" ]; then
        success "已失效: $relative_file"
    else
        warn "opcache_invalidate 返回 $invalidate_result: $relative_file"
    fi
done

section "完成"
success "opcache.sh 执行完成"
info "如需指定提交范围，可执行: sh opcache.sh HEAD~3..HEAD"
