#!/usr/bin/env sh
#
# pull.sh — 从远端拉取主仓 + 私包，并验证私包权限，最后调 cache.sh 收尾
#
# 跟 cache.sh 职责严格分离：
#   - pull.sh：网络层（git pull / 私包权限 / composer 切换 / 强制 update 私包）
#   - cache.sh：本地层（缓存清理 / dumpautoload / 权限修复 chown chmod）
#
# 用法：
#   sh pull.sh                                            # 交互选 tag 发版（终端）；非交互等价 --latest
#   sh pull.sh --tag 2.0.9                                # 锚定 tag 发版（批量发版由 pull-all 经 env 透传）
#   sh pull.sh --latest                                   # 传统：追 master 最新
#   sudo sh pull.sh --production                          # 首次部署 .env 未建
#   sh pull.sh --force-reset                              # 工作区脏丢弃本地
#   sh pull.sh --help                                     # 看全部参数
#
# 退出码：
#   0  正常
#   1  仓库状态异常 / 缺命令 / 私包权限不通 / composer.json 缺失 / .env 歧义
#   3  composer install/update 失败
#   4  pull.sh 主体成功但收尾有异常（cache.sh 失败，或检测到 pending 迁移未执行——
#      git working tree 已推进，缓存/权限/schema 未跟上）
#
# 注：composer.json ↔ composer.production.json 漂移校验已移交 DEPLOY-CHECKLIST / CI
#     （v2 的 Step 2.5 parity / Step 2.6 PHP 预检 / Step 3c push 投毒探针 已下线）。
#
# 流程总览：
#   私包 manifest 预解析  读 composer.production.json .extra（名 / URL / provider / publish-tag）
#   Step 1   检查主仓工作区状态（脏则停 / --force-reset 丢弃）
#   Step 2   版本选择（--tag 锚定 checkout / --latest 或非交互追 master / 终端交互列近 5 tag 选）
#            + 拉码（composer.json 先还原到 HEAD；detached ↔ master 自动回轨）
#   Step 3   验证私包 SSH 拉取权限（逐包 ls-remote fail-fast）
#   Step 4   切 composer.json → production（仅 prod）+ 4.5 清 bootstrap/cache 防撞死类
#   Step 5   composer install + 强制 update 私包（含 vendor 救援）+ 5.5 publish 前端副本
#   Step 6   调 cache.sh 收尾（缓存清理 + 目录权限）
#   Step 6.5 pending 迁移检测（只报不跑——dev-main 直追下 pull 即隐式上线，schema 必须人工跟上）
#   Step 7   完成（退出码语义见上）

set -eu

# ---- 自更新防护：整个主体包进 main()，sh 先解析完整个文件再执行 ----------------
# Step 2 的 git checkout --detach <tag> / git pull 会替换磁盘上的 pull.sh 自身；
# 不包裹时 sh 按字节偏移续读已被替换的文件 → 错位执行/语法炸（tag 锚定来回切版本时高频触发）。
main() {

# 提前 derive SCRIPT_DIR 给 tools/_common.sh source 用（里面有 PATH 守卫等副作用，必须早跑）。
# 注意 ENGINE_DIR 在下面 arg 解析之后才 derive（因为 --engine-subdir 可覆盖）。
# shellcheck disable=SC1007  # CDPATH= cd 是惯用法（给 cd 临时清空 CDPATH 防乱跳），非手滑空格
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_DIR="$SCRIPT_DIR"

# 加载共用工具（5 件套打印 + require_command + user_exists + is_production + PATH 守卫）。
# is_production 暂时不能用，得等 ENGINE_DIR 在 arg 解析之后 derive 完才行。
# 显式存在性检查：比 sh "No such file" 默认错友好，提醒运维 tools/_common.sh 必须随脚本部署。
if [ ! -f "$SCRIPT_DIR/tools/_common.sh" ]; then
    printf '%s\n' "❌ [ERROR] $SCRIPT_DIR/tools/_common.sh 缺失" >&2
    printf '%s\n' "    pull.sh / cache.sh 共用此公共工具，缺则两脚本同时挂。" >&2
    printf '%s\n' "    确认 git pull 已拉到最新（tools/_common.sh 入 git）+ 部署时带上 tools/ 整目录。" >&2
    exit 1
fi
# shellcheck source=tools/_common.sh
. "$SCRIPT_DIR/tools/_common.sh"

# ---- 默认（环境变量优先级低于命令行参数）-------------------------------
PRODUCTION=${PRODUCTION:-0}
FORCE_RESET=${FORCE_RESET:-0}
SKIP_PRIVATE_PKG=${SKIP_PRIVATE_PKG:-0}
# 默认 Laravel 后端在 engine/ 子目录（本项目布局约定）；非标布局用 --engine-subdir 覆盖
ENGINE_SUBDIR=${ENGINE_SUBDIR:-engine}
# flock 文件路径（空 = 用 /tmp/${PROJECT_NAME}-pull.lock，自动派生）
PULL_LOCK_FILE=${PULL_LOCK_FILE:-}
# 发版版本锚定：非空 = checkout 该 tag（detached）发版；空 + 交互终端 = 列近 5 个 tag 让选；
# 空 + 非交互 = 传统追 master（向后兼容）。批量发版由 pull-all 选一次后经 RELEASE_TAG/RELEASE_LATEST env 透传全部项目。
RELEASE_TAG=${RELEASE_TAG:-}
# 显式传统模式（--latest）：跳过 tag 交互菜单，直接追 master 最新
RELEASE_LATEST=${RELEASE_LATEST:-0}

usage() {
    cat <<'EOF'
用法：sh pull.sh [选项]

选项（命令行参数 优先于 同名环境变量）：
  --production           强制按生产模式跑（等价 PRODUCTION=1）
  --force-reset          工作区脏时丢弃本地已跟踪改动后继续
  --skip-private-pkg     跳过私包权限验证（仅本地 path repo 调试）
  --tag TAG              锚定发版：checkout 指定 tag（detached）；批量发版由 pull-all 选一次经 env 透传
  --latest               显式追 master 最新（跳过 tag 交互菜单；detached 状态自动回轨 master）
  --lock-file PATH       flock 文件位置（默认 /tmp/${PROJECT_NAME}-pull.lock）
  --engine-subdir DIR    Laravel 后端子目录名（默认 engine）
  -h, --help             显示本帮助

版本选择（三选一）：
  1) --tag 2.0.9         明确锚 tag
  2) --latest            明确追 master
  3) 都不传              交互终端 → 列最近 5 个 tag 选一个（回车/0 = 退出不发版）；
                         非交互 → 等价 --latest（向后兼容）

示例：
  sh pull.sh                                            # 交互选 tag 发版
  sh pull.sh --tag 2.0.9                                # 锚定 2.0.9 发版
  sh pull.sh --latest                                   # 传统：追 master 最新
  sudo sh pull.sh --production                          # 首次部署
  sh pull.sh --force-reset                              # 覆盖本地改动

环境变量等价：
  PRODUCTION=1 sudo sh pull.sh
  RELEASE_TAG=2.0.9 sh pull.sh
EOF
}

# ---- 解析命令行参数（覆盖环境变量默认）---------------------------------
# --lock-file / --engine-subdir 缺值时先判 $# 再取 $2，避免 set -u 崩在 "$2: unbound variable"。
while [ $# -gt 0 ]; do
    case "$1" in
        --production)           PRODUCTION=1; shift;;
        --force-reset)          FORCE_RESET=1; shift;;
        --skip-private-pkg)     SKIP_PRIVATE_PKG=1; shift;;
        --tag)                  [ $# -ge 2 ] || { error "--tag 需要一个 tag 名参数"; exit 1; }
                                RELEASE_TAG="$2"; shift 2;;
        --tag=*)                RELEASE_TAG="${1#*=}"; shift;;
        --latest)               RELEASE_LATEST=1; shift;;
        --lock-file)            [ $# -ge 2 ] || { error "--lock-file 需要一个路径参数"; exit 1; }
                                PULL_LOCK_FILE="$2"; shift 2;;
        --lock-file=*)          PULL_LOCK_FILE="${1#*=}"; shift;;
        --engine-subdir)        [ $# -ge 2 ] || { error "--engine-subdir 需要一个目录名参数"; exit 1; }
                                ENGINE_SUBDIR="$2"; shift 2;;
        --engine-subdir=*)      ENGINE_SUBDIR="${1#*=}"; shift;;
        -h|--help)              usage; exit 0;;
        *) printf '❌ 未知参数: %s\n用 --help 看用法\n' "$1" >&2; exit 1;;
    esac
done

# bug#1: export PRODUCTION，让子进程 cache.sh 的 is_production 与父进程判定一致。
# 否则 .env 未建的首次部署路径上裸调 cache.sh，子进程读不到 PRODUCTION → 误跑 dev 模式
# （cache.sh M3 symlink 校验等被静默跳过）。
export PRODUCTION
# 同理 export ENGINE_SUBDIR：Step 6 调的 cache.sh 也支持 --engine-subdir / 同名 env，传过去
# 保证两脚本走同一后端子目录（否则 pull.sh 用 --engine-subdir foo 时 cache.sh 仍按默认 engine/）。
export ENGINE_SUBDIR

# ---- 派生路径（在 arg 解析之后用最终的 ENGINE_SUBDIR）-----------------
# SCRIPT_DIR / PROJECT_DIR 已在文件顶部 derive 给 tools/_common.sh source 用；这里只 derive
# ENGINE_DIR + PROJECT_NAME（依赖 arg 解析后的 ENGINE_SUBDIR）。
ENGINE_DIR="$PROJECT_DIR/$ENGINE_SUBDIR"
# 项目名从仓库根目录名自动取（跨项目复用 0 改动；LOCK_FILE 默认按这个派生）
PROJECT_NAME=$(basename "$PROJECT_DIR")
# 没指定 --lock-file 则按项目名派生
[ -z "$PULL_LOCK_FILE" ] && PULL_LOCK_FILE="/tmp/${PROJECT_NAME}-pull.lock"

# 注：info / warn / success / error / section / require_command / user_exists /
# is_production 都从 tools/_common.sh 来（顶部已 source）。is_production 跟 cache.sh
# 走同一个函数，regex 单点维护避免漂移。

# 显式拒绝"无 .env 又没声明 PRODUCTION"的歧义场景
assert_env_decided() {
    if [ -f "$ENGINE_DIR/.env" ]; then
        return 0
    fi
    if [ "$PRODUCTION" = "1" ]; then
        warn "engine/.env 不存在，但 --production / PRODUCTION=1 已显式声明 → 按生产走"
        return 0
    fi
    error "engine/.env 不存在，且未声明 --production。请选其一："
    info "  开发机首次 clone：cp engine/.env.example engine/.env 后再跑"
    info "  生产首次部署：sudo sh pull.sh --production（进入生产分支）"
    exit 1
}

# ---- 前置检查 -----------------------------------------------------------
# 不查 sh（自己就在 sh 里跑，多余）。
require_command git
require_command composer
require_command ssh
require_command jq

if [ ! -d "$PROJECT_DIR/.git" ]; then
    error "当前目录不是 git 仓库根目录: $PROJECT_DIR"
    exit 1
fi
if [ ! -d "$ENGINE_DIR" ]; then
    error "未找到 engine 目录: $ENGINE_DIR"
    exit 1
fi
if [ ! -f "$PROJECT_DIR/cache.sh" ]; then
    error "未找到 cache.sh: $PROJECT_DIR/cache.sh"
    exit 1
fi

cd "$PROJECT_DIR"

# ---- flock 防并发（多个 pull.sh 同 worktree 操作 race）----
acquire_lock "$PULL_LOCK_FILE" "pull.sh"

# 进入主流程前先把 .env 歧义场景拒绝
assert_env_decided

# ---- 私包 manifest 预解析（一次性，后续循环直接读，不再重复 jq 调 URL）----
# 一条 jq 直接产五字段 manifest：name|repo-key|provider-rel|publish-tag|url。前四字段从
# .extra."moo-private-packages" 逐项读，第五字段 url 就地从 .repositories.<repo-key>.url 关联
# （URL 缺失时输出空第五字段，交给下面 awk 统一 fail-fast 校验）。
# 后续 Step 3 / 5 / 5.5 所有循环直接读这个预解析串（v2 散在 6 处 jq，此处收敛为一次调用、
# 免去逐包 jq + mktemp 临时文件累积回读）。加新私包零改动 pull.sh，只改各仓 composer json manifest 数组即可。
if [ ! -f "$ENGINE_DIR/composer.production.json" ]; then
    error "缺 $ENGINE_DIR/composer.production.json，无法解析私包 manifest / URL"
    exit 1
fi

PRIVATE_PKGS_MANIFEST=$(jq -r '. as $root | .extra."moo-private-packages" // [] | .[] | [.name, ."repo-key", ."provider-rel", (.["publish-tag"] // ""), ($root.repositories[."repo-key"].url // "")] | join("|")' "$ENGINE_DIR/composer.production.json" 2>/dev/null)
if [ -z "$PRIVATE_PKGS_MANIFEST" ]; then
    error ".extra.\"moo-private-packages\" 缺失或为空 — 无法识别私包列表"
    info "在 composer.production.json 加形如："
    info '  "extra": { "moo-private-packages": [ {"name":"charsen/moo-x","repo-key":"x","provider-rel":"src/XProvider.php","publish-tag":"public"} ] }'
    exit 1
fi

# URL fail-fast 校验：第五字段（url）为空即缺 .repositories.<repo-key>.url，不能等到 Step 3/5 才报。
missing_url_pkgs=$(printf '%s\n' "$PRIVATE_PKGS_MANIFEST" | awk -F'|' '$5==""{print $1}' | tr '\n' ' ' | sed 's/[[:space:]]*$//')
if [ -n "$missing_url_pkgs" ]; then
    error "无法从 composer.production.json 解析 repositories.<repo-key>.url（对应: ${missing_url_pkgs}）"
    info "应该有形如：\"repositories\": { \"x\": { \"type\": \"vcs\", \"url\": \"git@...\" } }"
    exit 1
fi

# 提取所有包名空格分隔（Step 5 一次性 composer update 用）
PRIVATE_PKG_NAMES=$(printf '%s\n' "$PRIVATE_PKGS_MANIFEST" | awk -F'|' '{print $1}' | tr '\n' ' ' | sed 's/[[:space:]]*$//')
PRIVATE_PKG_COUNT=$(printf '%s\n' "$PRIVATE_PKGS_MANIFEST" | grep -c .)
info "私包 manifest 已解析（${PRIVATE_PKG_COUNT} 个：${PRIVATE_PKG_NAMES}）"

# 删私包 vendor 目录，逼 composer 下次做全新克隆而非在旧 checkout 上 update。
# 私包被换根 force-push（如清敏感数据 filter-branch/BFG）后，vendor 里残留的旧 checkout 的 master
# 与新 origin/master 无共同祖先，composer update 覆盖前跑 getUnpushedChanges（git diff origin/master...master）
# 会 fatal: no merge base 整体中断（真实踩坑：moo-monitor-laravel 换根后 fleet 部署全崩，clearcache 无效——
# 分叉在 vendor checkout 不在缓存）。只删 vendor/charsen/*（git source 安装、会被历史改写波及）；
# 公共包是 dist zip 无 .git、不跑该检查，保留以免全量重下。
purge_private_vendor() {
    # 用 $ENGINE_DIR 绝对路径删，不依赖调用时 cwd 恰好在 ENGINE_DIR（各 rescue/update 分支
    # cwd 已进 engine，但绝对路径消掉这层隐式耦合，将来挪调用点也不会误删他处 vendor）。
    for _pkg in $PRIVATE_PKG_NAMES; do
        [ -n "$_pkg" ] && rm -rf "$ENGINE_DIR/vendor/${_pkg}"
    done
}

# ---- Step 1: 检查主仓工作区状态 ----------------------------------------

section "🔍 Step 1: 检查主仓工作区状态"
branch_name=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || printf '%s' "unknown")
if [ "$branch_name" = "HEAD" ]; then
    # 上次按 tag 锚定发版留下的 detached 状态；本次选 tag 会重新锚定，选 --latest 会自动回轨 master
    info "当前状态: tag 锚定（detached @ $(git describe --tags --always 2>/dev/null || git rev-parse --short HEAD)）"
else
    info "当前分支: $branch_name"
fi

# 已跟踪改动脏检查 + FORCE_RESET 丢弃。
# --untracked-files=no：忽略未跟踪文件（前端 build / log / cache 等不该 block deploy — git pull 不动 untracked）。
# 唯一豁免：<engine-subdir>/composer.json —— Step 4 production 切换的 cp 覆盖产物，生产常态"脏"。
status_output=$(git status --short --untracked-files=no \
    | grep -v "^.M ${ENGINE_SUBDIR}/composer.json$" \
    || true)
if [ -n "$status_output" ]; then
    if [ "$FORCE_RESET" = "1" ]; then
        warn "FORCE_RESET=1，丢弃本地已跟踪改动"
        git restore --source=HEAD --worktree --staged .
        success "本地改动已恢复到 HEAD"
    else
        warn "检测到未提交改动，为避免误覆盖，已停止执行："
        printf '%s\n' "$status_output"
        info "处理：有价值先 git stash / commit+push，无价值丢弃后重跑，或 --force-reset 全丢。详见 DEPLOY-CHECKLIST"
        exit 1
    fi
else
    success "✨ 工作区干净"
fi

# ---- Step 2: 拉远端主仓 -------------------------------------------------

section "🌐 Step 2: 拉远端主仓"
# composer.json 在生产是 Step 4 的 production 切换产物，工作区常态"脏"。pull 前先还原到 HEAD，
# 否则上游一改 composer.json（如私包切包提交），git pull --ff-only 会因"本地改动会被覆盖"而中止。
# Step 4 随后会重新 cp production 覆盖，幂等无副作用。
if ! git diff --quiet HEAD -- "$ENGINE_SUBDIR/composer.json" 2>/dev/null; then
    git checkout HEAD -- "$ENGINE_SUBDIR/composer.json"
    info "已还原 composer.json 到 HEAD（Step 4 会重新切 production）"
fi
# 注：旧版这里有一段「untracked 冲突自愈」（fetch 后算 incoming ∩ 本地 untracked、撞名的先备份
# 到 /tmp 再让 pull 通过），是为 scaffold runtimes/慢SQL/api 等服务器侧生成的 working-tree 文件兜底。
# 这些已云端化、服务器不再产生需提交/冲突的文件 → 源头消失，随云端化「砍本地」方向移除。
# 万一仍撞上（理论上不再发生），git pull --ff-only 会带原生报错中止、Step 2 失败（set -e 退出），按提示人工处理。

# ---- 版本选择：--tag 锚定 / --latest 追 master / 交互菜单 / 非交互兜底 latest ----
# 批量发版（pull-all）选一次后经 --tag 透传，这里拿到即非交互直达；
# 手动单项目无参 + 交互终端 → 列最近 5 个 tag 让选（回车/0 = 退出，发版动作必须显式）。
tags_fetched=0
if [ -z "$RELEASE_TAG" ] && [ "$RELEASE_LATEST" != "1" ]; then
    if [ -t 0 ]; then
        info "拉取 tag 列表…"
        git fetch --tags --force --quiet
        tags_fetched=1
        tag_list=$(git for-each-ref --sort=-creatordate --format='%(refname:short)' refs/tags | sed -n '1,5p')
        if [ -z "$tag_list" ]; then
            warn "仓库没有任何 tag，回退追 master 最新"
            RELEASE_LATEST=1
        else
            tag_count=$(printf '%s\n' "$tag_list" | wc -l | tr -d ' ')
            printf '\n🏷️  请选择发版版本：\n'
            i=1
            printf '%s\n' "$tag_list" | while IFS= read -r t; do
                printf '  %s) %s\n' "$i" "$t"
                i=$((i + 1))
            done
            printf '  m) 追 master 最新（传统模式）\n'
            printf '  0) 🚪 退出，不发版\n\n'
            printf '👉 请输入序号（1-%s / m，回车或 0 退出）: ' "$tag_count"
            tag_choice=""
            read -r tag_choice || true
            case "$tag_choice" in
                ''|0)  info "👋 已退出，未发版。"; exit 0;;
                m|M)   RELEASE_LATEST=1;;
                *[!0-9]*) error "无效的版本选择：$tag_choice"; exit 1;;
                *)
                    if [ "$tag_choice" -lt 1 ] || [ "$tag_choice" -gt "$tag_count" ]; then
                        error "无效的版本选择：$tag_choice"
                        exit 1
                    fi
                    RELEASE_TAG=$(printf '%s\n' "$tag_list" | sed -n "${tag_choice}p")
                    ;;
            esac
        fi
    else
        # 非交互（管道/自动化）且未显式指定版本：保持旧行为追 master，向后兼容
        RELEASE_LATEST=1
    fi
fi

if [ -n "$RELEASE_TAG" ]; then
    info "锚定发版 tag：$RELEASE_TAG"
    [ "$tags_fetched" = "1" ] || git fetch --tags --force --quiet
    if ! git rev-parse -q --verify "refs/tags/$RELEASE_TAG" >/dev/null; then
        error "tag 不存在：$RELEASE_TAG（git tag -l 查看可用 tag）"
        exit 1
    fi
    # detached 锚定：工作区 Step 1 已保证干净（composer.json 已还原），checkout 安全
    git checkout --detach -q "refs/tags/$RELEASE_TAG"
    success "🏷️  已锚定 $RELEASE_TAG（$(git rev-parse --short HEAD)）"
else
    # 传统追 master：上次 tag 锚定留下的 detached 状态先回轨 master 再 pull
    if [ "$(git rev-parse --abbrev-ref HEAD)" = "HEAD" ]; then
        info "当前处于 tag 锚定（detached），回轨 master"
        git checkout -q master
    fi
    info "执行 git pull --ff-only"
    git pull --ff-only
fi
success "🌐 主仓代码已更新"

# ---- Step 3: 验证私包拉取权限 -------------------------------------------

if [ "$SKIP_PRIVATE_PKG" = "1" ]; then
    section "🔐 Step 3: 跳过私包权限验证（SKIP_PRIVATE_PKG=1）"
    warn "已跳过私包权限验证。后续 composer 拉取可能因 deploy key 缺失失败"
else
    section "🔐 Step 3: 验证私包拉取权限"

    # 3a. 基础 SSH 联通（所有私包同 gitee host，只测一次）
    # StrictHostKeyChecking=accept-new 首次 known_hosts 不报错。
    # （本地开发机走 ssh-agent 管理 key；生产 box 防 deploy key 被覆盖应在 ~/.ssh/config
    # 显式配 IdentitiesOnly + IdentityFile。）
    ssh_out=$(ssh -o BatchMode=yes -o ConnectTimeout=10 \
                  -o StrictHostKeyChecking=accept-new \
                  -T git@gitee.com 2>&1 || true)
    # gitee SSH 成功消息含 ANSI 色码，用两个 anchor 词跨过 ANSI 判通：
    # "authenticated" + "GITEE"（GITEE.COM does not provide shell access，不会被 ANSI 切）。
    case "$ssh_out" in
        *authenticated*GITEE*)
            success "🔐 SSH 到 gitee 已通"
            ;;
        *)
            error "SSH 到 gitee 失败"
            info "--- ssh 实际输出 ---"
            printf '%s\n' "$ssh_out" >&2
            info "--- 输出结束 ---"
            info "含 'Permission denied' → 需配 deploy key（详 PRIVATE-COMPOSER-PACKAGES.md §4.3）"
            info "含 'Host key verification' → ssh known_hosts 问题；其他 → 把输出贴给开发排查"
            exit 1
            ;;
    esac

    # 3b. 遍历每个私包 ls-remote 读权限 fail-fast。
    # deploy key 是 per-repo 在 gitee 配的：SSH 通 ≠ 每个私包都有读权限。
    while IFS='|' read -r pkg_name _ _ _ pkg_url; do
        [ -z "$pkg_name" ] && continue
        # 一次 ls-remote 同时判权限（exit code）+ 取 HEAD（输出），省掉旧版"先探权限再取 HEAD"
        # 的第二次 ls-remote —— 6 个包等于砍掉一半 SSH 握手（12 → 6 次）。
        # set -e 下把命令替换放进 if 条件豁免（失败不中止，走下面 error 分支自己 exit）。
        if ! pkg_head_line=$(git ls-remote "$pkg_url" HEAD 2>/dev/null); then
            error "对 ${pkg_name} 私包无读权限（SSH 通但拉不到 repo）: $pkg_url"
            info "可能原因："
            info "  - gitee 仓库 ${pkg_name} 不存在 / 不是你的"
            info "  - deploy key 没加到这个具体 repo（个人 SSH key 不够，要在 repo 单独加 deploy key）"
            info "  - SSH config 走的是别的 key（ssh -vT git@gitee.com 看用了哪把）"
            exit 1
        fi
        pkg_head=$(printf '%s\n' "$pkg_head_line" | awk '{print substr($1, 1, 7)}')
        success "📦 ${pkg_name}: pull HEAD=${pkg_head}"
    done <<EOF
$PRIVATE_PKGS_MANIFEST
EOF
fi

# ---- Step 4: 切换 composer.json 为 production（仅生产）---------------

section "⚙️  Step 4: 切换 composer.json 为 production 配置"
COMPOSER_PROD="$ENGINE_DIR/composer.production.json"
COMPOSER_BAK="$ENGINE_DIR/composer.json.pull-bak"
SWITCHED=0  # 标记本轮是否真的切了，失败时只回滚切过的

if is_production; then
    if [ ! -f "$COMPOSER_PROD" ]; then
        error "找不到 $COMPOSER_PROD — 仓库不完整或被误删，无法生产部署"
        exit 1
    fi
    if diff -q "$COMPOSER_PROD" "$ENGINE_DIR/composer.json" >/dev/null 2>&1; then
        info "composer.json 已经是 production 配置，无需切"
    else
        # 切换前备份当前 composer.json（半切回滚保护）
        cp "$ENGINE_DIR/composer.json" "$COMPOSER_BAK"
        cp "$COMPOSER_PROD" "$ENGINE_DIR/composer.json"
        SWITCHED=1
        success "⚙️  composer.json 切换为 production（旧版备份到 composer.json.pull-bak）"
    fi
else
    info "非生产环境（.env APP_ENV ≠ production），保持当前 composer.json"
    info "本地开发 path repo + symlink 不动"
fi

# Step 5 失败时回滚 Step 4 的切换：composer.json 还原 + vendor 标记重装
rollback_composer_on_fail() {
    if [ "$SWITCHED" = "1" ] && [ -f "$COMPOSER_BAK" ]; then
        warn "composer 操作失败，回滚 Step 4 的 composer.json 切换"
        mv "$COMPOSER_BAK" "$ENGINE_DIR/composer.json"
        warn "vendor/ 状态可能半装，建议 rm -rf engine/vendor 后重跑 pull.sh"
    fi
}

# ---- Step 4.5: 清掉 Laravel 编译缓存（防 post-autoload-dump 启动 artisan 时撞死类引用）----
# 背景：删某 Listener 后，生产机 bootstrap/cache/events.php 仍持旧映射，Step 5 composer
# install 的 post-autoload-dump 钩子跑 artisan config:clear 时，artisan 启动解析死类映射 →
# "class does not exist" → 整个 composer 操作崩。这些 cache 都由 artisan 命令自动重建
# （post-autoload-dump 自己会跑 config:clear / clear-compiled / package:discover），物理删零风险、强幂等。
section "🧹 Step 4.5: 清掉 Laravel 编译缓存（防止 artisan 启动撞已删类）"
CACHE_DIR="$ENGINE_DIR/bootstrap/cache"
if [ -d "$CACHE_DIR" ]; then
    # bug#3: 用 find -delete（与脚本其它处统一），不再 `for f in $(find ...)` 未引号分词。
    # -maxdepth 1 -type f -name '*.php'：只删本层 .php，保留 .gitignore 等。
    find "$CACHE_DIR" -maxdepth 1 -name '*.php' -type f -delete 2>/dev/null || true
    success "🧹 已清掉 bootstrap/cache/*.php，post-autoload-dump 会重建"
else
    info "bootstrap/cache 不存在，跳过"
fi

# ---- Step 5: 安装依赖 + 强制更新私包 --------------------------------

section "📦 Step 5: composer 安装 + 强制更新私包"
cd "$ENGINE_DIR"

# composer 不该用 root 跑（plugin 安全 + owner 漂移），但默认不强切：切了会撞 git 2.35+ dubious
# ownership（要 safe.directory）+ 目标用户 home 可能不可写（composer cache 创建失败）。默认 root
# 跑（生产内部信任域可接受），需严格隔离时显式 export COMPOSER_USER=www-data（+ 配 safe.directory + home 可写）。
COMPOSER_RUNNER="composer"
if [ -n "${COMPOSER_USER:-}" ] && [ "$(id -u)" = "0" ]; then
    if id "$COMPOSER_USER" >/dev/null 2>&1; then
        info "COMPOSER_USER=$COMPOSER_USER 显式指定，composer 段切到该用户跑"
        info "前置：1) git config --global --add safe.directory $PROJECT_DIR  2) $COMPOSER_USER 有可写 home"
        COMPOSER_RUNNER="sudo -u $COMPOSER_USER -H composer"
    else
        warn "COMPOSER_USER=$COMPOSER_USER 用户不存在，回退 root 跑"
    fi
fi

# Step 5.0: 私包 vendor Provider 完整性自检 + 自愈（真实踩坑：path repo 路径在服务器不存在
# → 装失败 → vendor 缺 ServiceProvider → artisan 加载 provider 时炸）。
# 救援用 update <私包列表> 而非 install：install 严守 lock，碰上 dev "dev-master" 跟 prod
# "^x.x" 错配会直接拒绝；update 只更私包本身、自动调和 lock 跟 require。
# --no-scripts 跳过 post-autoload-dump 的 artisan boot，避免 Provider 缺失时炸（chicken-egg）。
NEED_RESCUE=0
MISSING_PKGS=""
while IFS='|' read -r pkg_name _ provider_rel _ _; do
    [ -z "$pkg_name" ] && continue
    if [ ! -f "vendor/${pkg_name}/${provider_rel}" ]; then
        warn "vendor/${pkg_name}/${provider_rel} 缺失"
        NEED_RESCUE=1
        MISSING_PKGS="${MISSING_PKGS}${pkg_name} "
    fi
done <<EOF
$PRIVATE_PKGS_MANIFEST
EOF

if [ "$NEED_RESCUE" = "1" ] && [ -f "$ENGINE_DIR/composer.production.json" ]; then
    warn "私包 Provider 文件缺失：${MISSING_PKGS}— 自动救援"
    # 救援标志：本地 dev 不带 --no-dev，避免把 Pest / Pint 等开发依赖一并删掉
    rescue_flags="--optimize-autoloader --no-scripts"
    if is_production; then
        rescue_flags="$rescue_flags --no-dev"
    fi
    # 分两支：有 lock → partial update 私包调和错配 + install 同步；无 lock → 跳过 partial
    # update（composer 直接拒绝 "Cannot update only a partial set ... without a lock file"），
    # 直接全量 install。多数仓库 .gitignore 排了 composer.lock（首次部署 / 清空 vendor 后必无），
    # 不分支会必然踩这个 BUG（真实踩坑）。
    if [ -f "composer.lock" ]; then
        info "（有 composer.lock → partial update 私包调和 lock 错配，再 install 同步）"
        # 一次性 update 全部私包（包含缺失 + 未缺失，composer 自己 dedupe）
        # shellcheck disable=SC2086
        if ! rescue_out=$($COMPOSER_RUNNER update $PRIVATE_PKG_NAMES $rescue_flags 2>&1); then
            printf '%s\n' "$rescue_out"
            case "$rescue_out" in
                *"lock file version"*|*"with-all-dependencies"*|*"conflicts with"*)
                    # 私包收紧了对 lock 内依赖的 require/conflict（如 moo-system 安全驱动要求 framework ≥12.61.1、
                    # conflict guzzle <7.12.1）→ 带 -W 重试一次，私包声明什么就解什么（含 root 依赖），
                    # 上界仍受 host composer.json 约束（不跨大版本）。收紧约束的私包 commit 即升级 review。
                    warn "私包 update 失败 — 私包约束要求升级 lock 内依赖 → 带 -W 重试一次（按私包声明连带升级）"
                    # shellcheck disable=SC2086
                    $COMPOSER_RUNNER update $PRIVATE_PKG_NAMES --with-all-dependencies $rescue_flags || {
                        rollback_composer_on_fail
                        error "vendor 救援 update 失败（带 -W 重试后仍失败）— 需人工调和依赖约束"
                        info "手动调和："
                        info "  composer update $PRIVATE_PKG_NAMES --with-all-dependencies $rescue_flags"
                        exit 3
                    }
                    ;;
                *)
                    # 私包历史被改写 force-push 后旧 vendor checkout 分叉 → getUnpushedChanges 无 merge-base 崩。
                    # 删私包 vendor 逼全新克隆（跳过 getUnpushedChanges），再重试一次。详见 purge_private_vendor 注释。
                    warn "私包 update 失败 — 疑似旧 vendor checkout 历史分叉（no merge base）→ 删私包 vendor 全新克隆重试一次"
                    purge_private_vendor
                    # shellcheck disable=SC2086
                    $COMPOSER_RUNNER update $PRIVATE_PKG_NAMES $rescue_flags || {
                        rollback_composer_on_fail
                        error "vendor 救援 update 失败（删私包 vendor 重试后仍失败）— 可能 SSH key 没配 / 私包没权限 / 网络不通"
                        info "手动调和："
                        info "  rm -rf vendor/charsen && composer update $PRIVATE_PKG_NAMES $rescue_flags"
                        info "  composer install $rescue_flags"
                        exit 3
                    }
                    ;;
            esac
        fi
    else
        info "（无 composer.lock → 跳过 partial update，直接 install 重新解析依赖 + 生成 lock）"
    fi
    # 全量 install：有 lock 按 lock 装齐；无 lock 等同 update，按 composer.json 解析 + 生成新 lock。
    # 无 lock 分支下这是首个碰私包克隆的命令，历史分叉的 no-merge-base 会在这里首次触发 → 同样删私包 vendor 重试。
    # shellcheck disable=SC2086
    if ! $COMPOSER_RUNNER install $rescue_flags; then
        warn "私包 install 失败 — 疑似旧 vendor checkout 历史分叉（no merge base）→ 删私包 vendor 全新克隆重试一次"
        purge_private_vendor
        # shellcheck disable=SC2086
        $COMPOSER_RUNNER install $rescue_flags || {
            rollback_composer_on_fail
            error "vendor 救援 install 失败（删私包 vendor 重试后仍失败）— 可能 SSH key 没配 / 私包没权限 / 网络不通"
            info "手动调和："
            info "  rm -rf vendor/charsen && composer install $rescue_flags"
            exit 3
        }
    fi
    # 清 bootstrap/cache 防 stale provider 列表（如 dev 依赖已 --no-dev 删但 cache 仍引用）
    find bootstrap/cache -maxdepth 1 -name "*.php" -delete 2>/dev/null || true
    success "🩹 vendor 救回 + bootstrap/cache 清理"
fi

# 首次部署 / vendor 完全不存在：跑完整 install
if [ ! -f "vendor/autoload.php" ]; then
    info "vendor/ 不存在，跑 composer install"
    if is_production; then
        # shellcheck disable=SC2086
        $COMPOSER_RUNNER install --no-dev --optimize-autoloader || { rollback_composer_on_fail; error "composer install 失败"; exit 3; }
    else
        # shellcheck disable=SC2086
        $COMPOSER_RUNNER install --optimize-autoloader || { rollback_composer_on_fail; error "composer install 失败"; exit 3; }
    fi
    success "📦 首次 composer install 完成"
fi

# 强制更新所有私包到最新（vcs 模式下拉 gitee 锁定的 tag，path 模式下重建 symlink）
# 用 update 不是 install，确保即使 composer.lock 锁了旧 commit 也能拉新版。
info "强制更新私包: $PRIVATE_PKG_NAMES"
update_flags="--optimize-autoloader"
# 不加 --with-dependencies / -W：只更新私包本身，不动 root 其他依赖
# （laravel/framework 等 transitive 升级需单独 review，避免不可控连带升级）。
# 唯一例外：失败输出命中「lock 依赖约束冲突」签名时，带 -W 重试一次（见下方 case 分支）
# ——私包 composer.json 的 require/conflict 即对 host 的升级声明，收紧约束的私包 commit 即 review；
# 其余时间 lock 依赖照旧钉死，不做无差别连带升级。
if is_production; then
    update_flags="$update_flags --no-dev"
fi
# 私包历史被换根 force-push 后，旧 vendor checkout 与新 origin/master 无共同祖先，
# composer update 覆盖前的 getUnpushedChanges（git diff origin/master...master）会 fatal: no merge base。
# 删私包 vendor 逼全新克隆（不跑该检查）后重试一次。详见 purge_private_vendor 注释。
# 关键：这条 update 每次部署都跑、此时 vendor 是健康的，所以只在输出真含 "no merge base" 时才 purge。
# 若无差别地"任何失败都先删 6 个私包 vendor 再重试"，gitee 瞬时网络抖动等失败会误删健康 vendor，
# 重试再败则线上 vendor 处于被删的残状态 —— 健康 vendor 绝不能因网络抖动被误删。
# 捕获输出既判成败又留证据；成功/失败都把输出 printf 回终端，别吞掉 composer 的 Syncing 等进度信息。
# shellcheck disable=SC2086
if ! update_out=$($COMPOSER_RUNNER update $PRIVATE_PKG_NAMES $update_flags 2>&1); then
    printf '%s\n' "$update_out"
    case "$update_out" in
        *"no merge base"*)
            warn "私包 update 失败 — 输出含 no merge base，旧 vendor checkout 历史分叉 → 删私包 vendor 全新克隆重试一次"
            purge_private_vendor
            # shellcheck disable=SC2086
            $COMPOSER_RUNNER update $PRIVATE_PKG_NAMES $update_flags || { rollback_composer_on_fail; error "composer update 私包失败（删私包 vendor 重试后仍失败）: $PRIVATE_PKG_NAMES"; exit 3; }
            ;;
        *"lock file version"*|*"with-all-dependencies"*|*"conflicts with"*)
            # 私包收紧了对 lock 内依赖的 require/conflict（如 moo-system 安全驱动要求 framework ≥12.61.1、
            # conflict guzzle <7.12.1）→ 带 -W 重试一次，私包声明什么就解什么（含 root 依赖），
            # 上界仍受 host composer.json 约束（不跨大版本）。收紧约束的私包 commit 即升级 review。
            warn "私包 update 失败 — 私包约束要求升级 lock 内依赖 → 带 -W 重试一次（按私包声明连带升级）"
            # shellcheck disable=SC2086
            $COMPOSER_RUNNER update $PRIVATE_PKG_NAMES --with-all-dependencies $update_flags || { rollback_composer_on_fail; error "composer update 私包失败（带 -W 重试后仍失败）: $PRIVATE_PKG_NAMES"; exit 3; }
            ;;
        *)
            rollback_composer_on_fail
            error "composer update 私包失败: $PRIVATE_PKG_NAMES"
            exit 3
            ;;
    esac
else
    printf '%s\n' "$update_out"
fi

# 全程成功，备份可以丢
if [ -f "$COMPOSER_BAK" ]; then
    rm -f "$COMPOSER_BAK"
fi

# ---- Step 5.5: 同步私包 publish 副本（JS/CSS/images 等前端资源）-------

section "📤 Step 5.5: 同步私包 publish 副本"
# 私包前端资源（JS/CSS/images）是浏览器加载的 public/vendor/<pkg>/* 副本，必须 vendor:publish
# 重刷，否则包内 UI/JS 改动用户看不到。只 publish publish-tag 非空的包（空 = 无前端资源）。
# set -e 下 `var=$(cmd)` 命令替换失败会直接中止脚本（POSIX sh 无 pipefail，`if X|tail` 判的是
# tail 的 exit code、X 失败彻底静默）。把赋值放进 if 条件豁免 set -e，失败走 else 而非中止。
while IFS='|' read -r pkg_name _ _ publish_tag _; do
    [ -z "$pkg_name" ] && continue
    if [ -z "$publish_tag" ]; then
        info "📤 ${pkg_name} 无 publish-tag，跳"
        continue
    fi
    if publish_output=$(php artisan vendor:publish --tag="$publish_tag" --force 2>&1); then
        success "📤 ${pkg_name} publish 副本已刷"
    else
        warn "📤 ${pkg_name} vendor:publish 失败，前端资源可能是旧版"
        warn "完整输出："
        printf '%s\n' "$publish_output" >&2
    fi
done <<EOF
$PRIVATE_PKGS_MANIFEST
EOF

# 私包 vendor 形态校验：prod 必须是实体目录(vcs)，dev 必须是 symlink(path repo)。
# prod 看到 symlink 表明 composer.json 切换没生效 / composer cache 命中老态 / vcs 拉取失败 —
# 跟 cache.sh M3 段口径同步，避免 pull.sh "success" 与 cache.sh "warn" 两条相反信号让运维困惑。
while IFS='|' read -r pkg_name _ _ _ _; do
    [ -z "$pkg_name" ] && continue
    pkg_dir="vendor/${pkg_name}"
    if [ -L "$pkg_dir" ]; then
        symlink_target=$(readlink "$pkg_dir" 2>/dev/null || printf 'unknown')
        if is_production; then
            warn "⚠️  生产 ${pkg_dir} 是 symlink（应为实体目录）→ ${symlink_target:-unknown}"
            warn "   表明 Step 4 composer.json 切换没生效 / composer cache 命中老态 / vcs 拉取失败"
            warn "   排查：cd engine && jq .repositories composer.json"
            warn "        rm -rf ${pkg_dir} && composer update ${pkg_name}"
        else
            success "🔗 ${pkg_name} 装好（symlink → ${symlink_target:-unknown}）"
        fi
    elif [ -d "$pkg_dir" ]; then
        pkg_head=$(cd "$pkg_dir" && git rev-parse --short HEAD 2>/dev/null || printf 'unknown')
        success "🔗 ${pkg_name} 装好（实体目录，HEAD=${pkg_head}）"
    else
        warn "${pkg_dir} 既不是 symlink 也不是目录，composer 装失败？"
    fi
done <<EOF
$PRIVATE_PKGS_MANIFEST
EOF

cd "$PROJECT_DIR"

# ---- Step 6: 调 cache.sh 收尾（缓存清理 + 权限修复）------------------

section "🧹 Step 6: 调 cache.sh 收尾"
info "执行 cache.sh"
# 软失败模式：cache.sh 可能 exit 1 的合法场景
# （prod 探测不到 web user / artisan optimize 异常等）若让 pull.sh 跟着 die，前面 git
# pull / composer / vendor:publish 的成果就被吞了 —— 但 git working tree 已经推进到新 commit。
# 所以软失败：把 cache.sh 失败转成 WARN，让运维看到"deploy 主体成功 + 缓存收尾未完"的真实状态。
# 父进程已 export PRODUCTION（bug#1），子进程 cache.sh 的 is_production 判定与父进程一致。
CACHE_FAILED=0
if sh "$PROJECT_DIR/cache.sh"; then
    success "🧹 cache.sh 已完成"
else
    cache_exit_code=$?
    CACHE_FAILED=1
    warn "cache.sh 退出码 ${cache_exit_code}（缓存刷新 / 权限修复未完成）"
    warn "前面 git pull / composer / vendor:publish 已成功，git working tree 已推进。"
    warn "排查 cache.sh 失败后单独重跑：sudo sh $PROJECT_DIR/cache.sh"
    warn "（最常见原因：prod 探测不到 web user → sudo -E WEB_USER=app sh cache.sh）"
fi

# ---- Step 6.5: pending 迁移检测（只报不跑）-----------------------------

# 私包 pin dev-master = "pull 即隐式上线"：包提交带 schema 变更而 migrate 没跟上时，
# 新代码会写不存在的列——有 fail-open 兜底的链路静默丢数据、没兜底的直接 500。
# 这里只检测告警不自动跑 migrate，保留人工决定权；
# 检测不到 php / artisan 跑不动时降级为提示，不影响退出码。
section "🔎 Step 6.5: pending 迁移检测"
MIGRATE_PENDING=0
if command -v php >/dev/null 2>&1; then
    pending_count=$(cd "$ENGINE_DIR" && php artisan migrate:status 2>/dev/null | grep -c 'Pending' || true)
    if [ "${pending_count:-0}" -gt 0 ] 2>/dev/null; then
        MIGRATE_PENDING=1
        warn "检测到 ${pending_count} 支 Pending 迁移 —— 代码已推进但 schema 未跟上"
        warn "确认后执行：cd ${ENGINE_DIR} && php artisan migrate --force && php artisan queue:restart"
    else
        success "无 pending 迁移"
    fi
else
    warn "php 不在 PATH，跳过 pending 迁移检测（请自行核对 php artisan migrate:status）"
fi

# ---- Step 7: 完成 -----------------------------------------------------

section "🎉 完成"
# 退出码语义（cron 监控按这个写告警规则）：
#   0  完全成功    1  仓库/权限/.env 异常    3  composer install/update 失败
#   4  pull.sh 主体成功但收尾有异常（cache.sh 失败：缓存/权限/log 预创建/php-fpm reload
#      没完成；或 pending 迁移未执行：schema 落后于代码）—— "看似绿其实坏"的盲区，
#      cron 监控必须区分这态。
if [ "${CACHE_FAILED:-0}" = "1" ] || [ "${MIGRATE_PENDING:-0}" = "1" ]; then
    warn "================================================================"
    if [ "${CACHE_FAILED:-0}" = "1" ]; then
        warn "⚠️  pull.sh 主体完成但 cache.sh 收尾失败 → 凌晨 0:00 daily log 翻篇可能 500"
        warn "    cron 监控请同时 grep 'cache.sh 退出码' 不止 grep '❌ ERROR'"
    fi
    if [ "${MIGRATE_PENDING:-0}" = "1" ]; then
        warn "⚠️  存在 pending 迁移：cd ${ENGINE_DIR} && php artisan migrate --force && php artisan queue:restart"
    fi
    warn "================================================================"
    info "建议后续抽查：cd engine && git log -5；sudo sh cache.sh 单独重跑看真实失败原因。详见 DEPLOY-CHECKLIST"
    exit 4
fi

success "🎉 pull.sh 全流程执行完成"
info "建议后续抽查：cd engine && git log -5 / php artisan migrate:status / 浏览器抽查高频接口。详见 DEPLOY-CHECKLIST"

# ---- main() 收口（自更新防护，与文件头 main() { 配对）--------------------------
}
main "$@"
