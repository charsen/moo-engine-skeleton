#!/usr/bin/env sh
#
# release-check.sh — 发布门禁：脚本语法 + composer 校验 + 路由冒烟 + 全量测试
#
# 在仓库根跑：sh release-check.sh
# 任一步失败即 set -e 中止并非零退出，CI / 手动发版前都跑一遍。
#
# 检查项：
#   1) 所有 *.sh 语法（sh -n / bash -n 按 shebang 分流）+ db-yaml-drift-probe.php php -l
#   2) composer validate composer.production.json（生产 composer 合法性）
#   3) composer audit（已知漏洞；无 lock 时跳过 --locked）
#   4) composer dump-autoload --classmap-authoritative（autoload 完整性）
#   5) php artisan about / route:list（能 boot + 路由无异常）
#   6) composer test（全量测试）
#   7) git diff --check（改动无空白错误）

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ENGINE="$ROOT/engine"

cd "$ROOT"

# ---- 1) 脚本语法 --------------------------------------------------------
# sh -n 一次只解析第一个位置参数（其余当 $1/$2），必须逐个跑。
for s in pull.sh cache.sh backup.sh opcache.sh fixJob.sh release-check.sh tools/_common.sh; do
    [ -f "$s" ] && sh -n "$s"
done
# bash 专用脚本（含进程替换 / local 等 bashism）用 bash -n。
if command -v bash >/dev/null 2>&1; then
    [ -f tools/fix-nginx-storage-safe.sh ] && bash -n tools/fix-nginx-storage-safe.sh
fi
[ -f tools/db-yaml-drift-probe.php ] && php -l tools/db-yaml-drift-probe.php >/dev/null

# ---- 2~6) composer / artisan ------------------------------------------
cd "$ENGINE"
composer validate --no-check-publish composer.production.json
if [ -f composer.lock ]; then
    composer audit --locked
else
    composer audit
fi
composer dump-autoload --classmap-authoritative --no-interaction
php artisan about --only=environment
php artisan route:list --except-vendor >/dev/null
composer test

# ---- 7) 改动无空白错误 --------------------------------------------------
cd "$ROOT"
git diff --check

printf '%s\n' 'Release checks passed.'
