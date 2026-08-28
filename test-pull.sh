#!/usr/bin/env sh
# test-pull.sh — Host 追 origin/dev，私包通过 composer.test.json 追各仓 origin/dev。
# pull.sh 保持唯一部署主体；本文件只选择测试 profile。

set -eu

# shellcheck disable=SC1007
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

export DEPLOY_COMPOSER_PROFILE=test
export DEPLOY_BRANCH=dev
exec sh "$SCRIPT_DIR/pull.sh" --latest "$@"
