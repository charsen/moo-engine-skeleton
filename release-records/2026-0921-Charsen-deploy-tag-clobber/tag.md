# 部署 tag 自愈、日志权限与 scaffold 硬下限（moo-engine-skeleton 0.2.4）

状态：**准备发版（未打 tag）** —— master 与 `dev` 已同步到同一点（树一致），工作区干净。服务器部署由用户执行；**无新增迁移**。

## 发布形态

- 本仓：待打 annotated patch tag `0.2.4` → `master` 顶点（含本记录）。
- 依赖前置：`charsen/moo-scaffold` **`2.2.1`** —— 该 tag 已由 scaffold 仓发布并推送（annotated tag 在远端），本批把三份 manifest 的约束统一抬到它。
- 不包含：服务器部署（含迁移，若有）；其它 host 与扩展包各自的发版由各仓自行记录。

## 包含范围（自 tag `0.2.3` 起 3 条非合并提交）

- **部署脚本 tag-clobber 自愈**（``pull.sh` / `tools/_common.sh` / `ComposerProfilesTest``）：`composer update 私包` 在 `git fetch --tags composer` 报
  `! [rejected] X -> X (would clobber existing tag)` 时，识别「已发布 tag 被上游强推重指」这一分类，
  删私包 vendor 后全新克隆重试一次；`pull.sh` 四处 vendor 救援分支改为 `no-merge-base|tag-clobber`，
  并抽出 `explain_private_vendor_purge` 统一解释。成因：moo-upload `0.1.10` / moo-page `0.1.5`
  两个已发布 tag 在 2026-09-19 11:52 被重指，导致依赖它们的 host 部署整段失败。
- **依赖基线抬至 `charsen/moo-scaffold ^2.2.1`**：本地 profile 与生产 profile 一并抬升，测试 profile 保持 `dev-dev`。
  仅 manifest 变更，无运行时行为。
- **日志 channel 权限显式 `0664`**（`single` / `daily` / `auth` / `dev`）。


## 数据库与回退

- **本批无新增迁移**：`0.2.3..master` 的 `engine/database/migrations` 无变化。
- 回退：代码回退到 tag `0.2.3`；本批不含结构变更，无数据回退动作。


## 验证证据

- `ComposerProfilesTest` **4 通过 / 30 断言**。
- 三条 profile 的 `composer validate --strict` 均通过。
- **未执行**：本仓全量测试、`release-check.sh`、真机页面验收。

## 部署

- 测试服：`sh test-pull.sh`（Host 追 `dev` + `composer.test.json`）。
- 生产：`sh pull.sh --tag 0.2.4`（由用户在服务器执行）。
- ⚠ **部署脚本自愈只在目标 tag 内生效**：编排器执行的是目标 tag 里的 `pull.sh`，所以本批修好的 tag-clobber 分支要在用 `--tag 0.2.4`（或更高）部署时才生效，不替代对已故障主机的手工解封（删 `vendor/charsen/moo-upload` / `moo-page` 后按同一 tag 重跑）。

## 本批追加：scaffold 2.2.3 与 ACL 产物重生（2026-09-21 补充）

| 项 | 内容 |
| --- | --- |
| 依赖前置 | `charsen/moo-scaffold` 抬至 **`^2.2.3`**（本批修了 `AclActionResolver` 三处缺陷：生成期 boot 上下文未设 `$method`、领域 Gate 授权在生成期误伤、跨控制器目标 key 算错） |
| ACL 产物 | 重跑 `moo:auth admin`：白名单剔除与权限点冲突的 key，`whitelist ∩ actions` 归 0；destroy-forever 动作经 `@acl` 声明 `danger: 1`，`config('actions.<app>.danger')` 写入产物 |
| 文档 | `PRIVATE-COMPOSER-PACKAGES.md` 生成表随之重刷 |

### ⚠️ 部署前需人工补授

剔除的 2 个 key —— `48d3ca3e656e3566`（**登录列表** `LoginManagementController::index`）、
`84470713dcb9a7c9`（**个人中心** `AdminController::index`）—— 此前同时落进白名单（凡登录者放行）
是生成器缺陷（二者本就有 `@acl`）。收敛为正常权限点后，**本仓角色数据中无任何角色持有它们**，
补授前非 root 进不去「个人中心」与「登录列表」。

- [ ] 部署后在「系统 → 授权管理」把这两项授给需要的角色（个人中心建议给全部后台角色，登录列表建议只给管理员类角色）。
- [ ] 用非 root 账号实测：个人中心可打开、登录列表按预期可见/不可见。

### 授权页「全选」行为变化

destroy-forever 类动作不再被「全选」自动勾选（须逐个显式勾选），且已有勾选状态不被全选改动。

## 正式发版前剩余项

- [ ] 打 annotated tag `0.2.4` 并推送（`dev` + `master`），核对远端解引用与双线可达。
- [ ] 推送本仓未推送提交（当前均只在本地；host 从 git 拉包，远端无新提交与 tag 时生产仍解析旧约束）。
- [ ] 目标环境部署与页面验收（按 `DEPLOY-CHECKLIST.md` 记录发布边界与抽样结果）。
