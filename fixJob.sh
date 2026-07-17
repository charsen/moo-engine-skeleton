#!/usr/bin/env sh
#
# fixJob.sh — 注册表驱动的单项目 queue worker 重启器（supervisor）
#
# 干什么：supervisorctl reread + update 后 restart 指定项目的 queue worker —— 让 worker 加载新代码。
#   何时调：改了 Job 类 / worker 报旧 cache 错 / 部署后（pull.sh 收尾后手动或自动化调）。
#   一次只重启一个项目；加项目改下面 WORKERS 注册表一行即可，菜单 / 参数 / 校验全自动。
#
# 用法：
#   sudo sh fixJob.sh                # 交互单选要重启哪个项目（supervisorctl 通常需 root）
#   sudo sh fixJob.sh your-project   # 直接重启 key=your-project 的 worker（cron / 自动化友好，不交互）
#   sudo sh fixJob.sh 1              # 按菜单序号直接重启
#
# ⚠️ 骨架发的是**模板**：下面 WORKERS 是示例占位。首次部署时把它改成你实际的 supervisor pool，
#    格式每行 `key|显示名|supervisor pool 名`。示例（单项目最小）：
#      WORKERS='your-project|示例项目|your-project-queue-worker'
#    多项目 fleet 就每行加一条，key 保持唯一即可。

set -eu

# ---- 项目 → supervisor pool 注册表（唯一编辑点：加项目 = 加一行）------------
# 格式：key|显示名|supervisor pool 名
# 部署时替换为真实 pool；未替换时下面的守卫会提示这仍是模板占位。
WORKERS='your-project|示例项目|your-project-queue-worker
your-project-test|示例测试环境|your-project-test-queue-worker'

WORKER_COUNT=$(printf '%s\n' "$WORKERS" | grep -c .)

# ---- 模板占位守卫：还没改注册表就跑 → 提示先配置，避免对不存在的 pool 瞎重启 --------
case "$WORKERS" in
    *your-project*)
        printf '%s\n' "⚠️  fixJob.sh 仍是骨架模板（WORKERS 注册表是 your-project 占位）。"
        printf '%s\n' "    请先把 WORKERS 改成你实际的 supervisor pool，再运行本脚本。"
        printf '%s\n' ""
        ;;
esac

# ---- root 预警（supervisorctl 通常要 root；不阻断，让运维自己决定）---------
if [ "$(id -u)" -ne 0 ]; then
    printf '%s\n' "⚠️  当前不是 root（uid=$(id -u)）。supervisorctl 多半需要 root，restart 可能失败。"
    printf '%s\n' "    标准用法：sudo sh fixJob.sh [key|序号]"
    printf '%s\n' ""
fi

# ---- 渲染菜单 -------------------------------------------------------------
print_menu() {
    printf '%s\n' "🔧 请选择要重启 queue worker 的项目（单选）："
    i=0
    printf '%s\n' "$WORKERS" | while IFS='|' read -r key name pool; do
        [ -z "$key" ] && continue
        i=$((i + 1))
        printf '  %d) %s  [%s -> %s]\n' "$i" "$name" "$key" "$pool"
    done
    printf '%s\n' "  0) 🚪 退出，不重启"
}

# ---- 取选择：优先参数（序号 or key），否则交互问 ---------------------------
if [ "$#" -gt 0 ]; then
    sel="$1"
else
    print_menu
    printf '%s\n' ""
    printf "👉 请输入序号 [1-%s] 或项目 key: " "$WORKER_COUNT"
    read sel || true   # 非交互（EOF/cron 无 stdin）下不因 set -e 提前中断
fi

# 去空格；${sel:-} 兜底 read 在 EOF 下可能不赋值的极端情况（配合 set -u）
sel=$(printf '%s' "${sel:-}" | tr -d ' ')
if [ "$sel" = "0" ] || [ -z "$sel" ]; then
    printf '%s\n' "👋 未选择任何项目，已退出。"
    exit 0
fi

# ---- 解析选择 → 命中注册表一行（纯数字按序号；否则按 key 精确匹配，不分大小写）--
row=""
case "$sel" in
    ''|*[!0-9]*)
        sel_lc=$(printf '%s' "$sel" | tr 'A-Z' 'a-z')
        # 循环体最后一句必须恒成功（用 if/fi 而非 "&& printf"），否则命中非末行时 while 退 1 →
        # row=$(…) 继承非零 → set -e 在赋值后即静默退出。
        row=$(printf '%s\n' "$WORKERS" | while IFS='|' read -r key name pool; do
            [ -z "$key" ] && continue
            if [ "$(printf '%s' "$key" | tr 'A-Z' 'a-z')" = "$sel_lc" ]; then
                printf '%s|%s|%s\n' "$key" "$name" "$pool"
            fi
        done)
        ;;
    *)
        [ "$sel" -ge 1 ] && [ "$sel" -le "$WORKER_COUNT" ] && row=$(printf '%s\n' "$WORKERS" | sed -n "${sel}p")
        ;;
esac

if [ -z "$row" ]; then
    valid_keys=$(printf '%s\n' "$WORKERS" | cut -d'|' -f1 | tr '\n' ' ')
    printf '%s\n' "❌ 无效的项目：${sel}（有效 key：${valid_keys}/ 序号 1-${WORKER_COUNT}）" >&2
    exit 1
fi

name=$(printf '%s' "$row" | cut -d'|' -f2)
pool=$(printf '%s' "$row" | cut -d'|' -f3)

# ---- 重启该项目的 queue worker -------------------------------------------
# pool 名带引号传给 supervisorctl，让它自己展开 ":*" 组通配（避免 shell 对 * 做文件名展开）。
printf '%s\n' "🔄 [${name}] supervisorctl reread + update + restart ${pool}:* …"
supervisorctl reread
supervisorctl update
supervisorctl restart "${pool}:*"
printf '%s\n' "✅ [${name}] queue worker 已重启（${pool}）"
