# 引擎部署 lock 错配自愈（moo-engine-skeleton 0.2.8）

状态：**准备发版** —— 本记录随任务分支 `fix-engine-lock-stale-root-require` 提交；合入 `master` 与 `dev` 后即在该两点分别可达，并在 `master` 顶点打 annotated patch tag `0.2.8`。服务器部署由用户执行；**无新增迁移**。

## 发布形态

- 本仓：annotated patch tag `0.2.8` → `master` 顶点（含本记录）。
- 依赖前置：**无** —— 本批不动三份 Composer manifest，也不含任何扩展包约束变化。
- 不包含：服务器部署；其它 host 与扩展包各自的发版由各仓自行记录。

## 包含范围（自 tag `0.2.7` 起 2 条非合并提交 + 本记录）

- `fix(deploy): lock 错配的 root 直接依赖并入私包 update 允许集`（`63667ca`）
- `docs: 补 lock 错配自愈的坑点与排障说明`（`81240af`）
- `docs: 补 lock 错配自愈的发版记录（0.2.8）`（本记录）

本批改动文件：`pull.sh`、`tools/_common.sh`、`engine/tests/Unit/Deployment/ComposerProfilesTest.php`、`NOTES.md`、`PRIVATE-COMPOSER-PACKAGES.md`。

### 背景与成因

2026-09-23 xing-ke-homepage 生产部署在 Step 5.0 vendor 救援处 exit 3：

```
- Root composer.json requires charsen/moo-feedback ^0.1.7, found charsen/moo-feedback[0.1.7, 0.1.8]
  but the package is fixed to 0.1.3 (lock file version) by a partial update and that version does not match.
```

该包走 Packagist、不在 `extra.moo-private-packages`，Step 5 的 partial update 允许集里没有它；`-W` 只放宽带更新包**自身**的依赖，不会把该包加入允许集，所以带 `-W` 重试与脚本打印的手工命令都只会重复同一条。本仓 `pull.sh` / `tools/_common.sh` 与 xing-ke 同源，具备同一缺陷，本批同步修复（6 个同源 host 同一修复）。

### 改动

- Step 5.0 前置用 `composer install --dry-run --no-scripts` 点名「lock 落后于 manifest」的 root 直接依赖（只读 lock + manifest，不访问远端、不写 vendor），并入本次 update 允许集 `UPDATE_PKG_NAMES`；只放行 manifest 自己审查抬高的那几个包，其余 lock 依赖照旧钉死。
- 救援与主 update 的 8 处调用点改用 `UPDATE_PKG_NAMES`；删私包 vendor / manifest 循环仍只针对 `extra.moo-private-packages` 内的真私包。
- `tools/_common.sh` 新增纯函数 `composer_lock_mismatched_requires`（5 个同源 host 的副本改后仍互为字节相同）。
- `ComposerProfilesTest` 增加提取器与接线断言；`NOTES.md` 记录坑点与手工解封命令，`PRIVATE-COMPOSER-PACKAGES.md` 的常见故障定位区分「私包约束」与「lock 落后于 manifest」两类处置。

## 数据库与回退

- **本批无新增迁移**：`0.2.7..master` 的 `engine/database/migrations` 无变化。
- 回退：代码回退到 tag `0.2.7`；本批不含结构变更，无数据回退动作。

## 验证证据

- `php artisan test --filter=ComposerProfilesTest`：**5 通过 / 40 断言**（本机）。
- `sh -n pull.sh`、`sh -n tools/_common.sh`、`git diff --check` 通过；`pull.sh` 中旧允许集残留 0 处、`UPDATE_PKG_NAMES` 调用点 8 处。
- 用 `psr/log` 夹具跑移植后 `pull.sh` 里**原样抽出**的 Step 5.0 前置块：stale 时允许集并入错配包并打印告警，随后的 `composer update` 成功、lock 恢复一致（`psr/log` 1.1.4 → 3.0.2、`psr/container` 保持 1.1.2）；修好后允许集自动回落为只含私包。
- **未执行**：服务器部署、真机页面验收、本仓全量测试。

## 部署

- 测试服：`sh test-pull.sh`（Host 追 `origin/dev` + `engine/composer.test.json`）。
- 生产：`sh pull.sh --tag 0.2.8`（由用户在服务器执行）。
- ⚠ **自愈只在目标 tag 内生效**：编排器执行的是目标 tag 里的 `pull.sh`，所以本批修好的 Step 5.0 前置要在用 `--tag 0.2.8`（或更高）部署时才生效，不替代对已故障主机的手工解封（命令见 `NOTES.md` 2026-09-23 条目）。

## 正式发版前剩余项

- [ ] 打 annotated tag `0.2.8` 并推送，核对远端解引用与 `master` / `dev` 双线可达。
- [ ] 目标环境部署与验收。
