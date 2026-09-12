#!/usr/bin/env sh
#
# private-packages.sh — 私包 manifest 解析 / 读权限预检 / publish 副本刷新的共享实现
#
# 由各 Host 的 pull.sh（xing-ke-homepage 是 pull-engine.sh）在 source tools/_common.sh 之后
# source。对应原先散在各仓 pull 脚本里的三段同构逻辑：
#   Step 2.9  从 Composer profile 解析私包 manifest（name|repo-key|provider-rel|publish-tag|url）
#   Step 3b   逐包 git ls-remote：一次调用同时判读权限（exit code / 空输出）与取 HEAD
#   Step 5.5  逐包 php artisan vendor:publish --tag=<tag> --force 刷新前端副本
#
# 依赖（都由调用方保证，本库不自带）：
#   - 外部命令：jq / awk / sed / tr / grep / git / php（与原内联实现完全一致）
#   - Shell 函数：info / success / warn —— 来自 tools/_common.sh，调用方必须先 source 它。
#     本库只调用、不定义这三个函数；private_packages_publish 依赖它们打印用户可见文案。
#     其余函数不依赖 _common.sh。
#
# 约定：
#   - 全部输入走参数，不读调用方的全局变量；不 exit（失败只 return 1，由调用方决定如何收尾）。
#   - 需要「同时取值 + 判成败」的函数（manifest / ls_remote）在调用方命令替换里执行，
#     运行于子 shell，临时变量不会回写调用方；publish 用 _pp_ 前缀临时变量并在返回前清理。
#   - 不并入 tools/_common.sh：那里明文约定「不引入新进程依赖（不用 jq/php）」，本库需要 jq/php。
#
# 变更本文件必须 6 仓同步（6 份 tools/_lib/private-packages.sh 保持字节相同）。

# 打印五字段 manifest：name|repo-key|provider-rel|publish-tag|url，每包一行。
# jq 表达式与各仓 pull 脚本原内联单行逐字节一致；URL 缺失时第五字段为空串，
# 交由调用方 fail-fast（不能静默跳过）。
private_packages_manifest() {
    [ "$#" -eq 1 ] || return 1
    jq -r '. as $root | .extra."moo-private-packages" // [] | .[] | [.name, ."repo-key", ."provider-rel", (.["publish-tag"] // ""), ($root.repositories[."repo-key"].url // "")] | join("|")' "$1" 2>/dev/null || return 1
}

# 空格连接的包名列表（Step 5 一次性 composer update 用）；等价于原
# `awk -F'|' '{print $1}' | tr '\n' ' ' | sed 's/[[:space:]]*$//'`。
private_packages_names() {
    [ "$#" -eq 1 ] || return 1
    printf '%s\n' "$1" | awk -F'|' '{print $1}' | tr '\n' ' ' | sed 's/[[:space:]]*$//'
}

# manifest 行数（原 `grep -c .` 等价）；空输入输出 0。
private_packages_count() {
    [ "$#" -eq 1 ] || return 1
    printf '%s\n' "$1" | grep -c .
}

# 第五字段（url）为空的包名，空格连接（原 `awk -F'|' '$5==""{print $1}' | ...` 等价）。
private_packages_missing_urls() {
    [ "$#" -eq 1 ] || return 1
    printf '%s\n' "$1" | awk -F'|' '$5==""{print $1}' | tr '\n' ' ' | sed 's/[[:space:]]*$//'
}

# 单次 git ls-remote：输出截 8 位 sha（与全脚本 rev-parse --short=8 展示口径一致）。
# 失败（git 非零退出 / 输出为空）return 1；调用方保留自己的 error/info 文案后自行 exit。
# 用法：if ! pkg_head_line=$(private_package_ls_remote "$pkg_url" "$pkg_ref"); then ...
private_package_ls_remote() {
    [ "$#" -eq 2 ] || return 1
    _pp_ls_remote_out=$(git ls-remote "$1" "$2" 2>/dev/null) || return 1
    [ -n "$_pp_ls_remote_out" ] || return 1
    printf '%s\n' "$_pp_ls_remote_out" | awk '{print substr($1, 1, 8)}'
}

# 遍历 publish-tag 非空的包执行 vendor:publish --force（前端 JS/CSS 副本刷新）。
# 全成功 return 0；任一失败 return 1（调用方据此置 PUBLISH_FAILED=1 并在收尾汇总）。
# 文案与原内联 while 循环逐字一致；publish 失败时额外把完整输出打到 stderr。
private_packages_publish() {
    [ "$#" -eq 1 ] || return 1
    _pp_publish_failed=0
    while IFS='|' read -r pkg_name _ _ publish_tag _; do
        [ -z "$pkg_name" ] && continue
        if [ -z "$publish_tag" ]; then
            info "📤 ${pkg_name} 无 publish-tag，跳"
            continue
        fi
        if publish_output=$(php artisan vendor:publish --tag="$publish_tag" --force 2>&1); then
            success "📤 ${pkg_name} publish 副本已刷"
        else
            _pp_publish_failed=1
            warn "📤 ${pkg_name} vendor:publish 失败，前端资源可能是旧版"
            warn "完整输出："
            printf '%s\n' "$publish_output" >&2
        fi
    done <<EOF
$1
EOF
    if [ "$_pp_publish_failed" = "0" ]; then
        unset _pp_publish_failed
        return 0
    fi
    unset _pp_publish_failed
    return 1
}
