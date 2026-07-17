# 部署上线 Checklist

> 单次部署上线前的分级核对单。🔴 阻塞项解决前不上线，🟡 高风险评估后处理，🟢 Ops 流程逐条核。
>
> 🟢 段是**通用 Ops 流程**，骨架已按自身现状写全，可直接照用。
> 🔴 / 🟡 段是**业务分级模板**——骨架不带任何业务项，只留空模板位与填写说明；
> 各 host fork 后按自己这次上线的风险项往里填（对齐 `docs/09` 增量开发工作流沉淀的风险面）。
> 配套：机器闸门 [`release-check.sh`](./release-check.sh)、脚本索引 [`SCRIPTS.md`](./SCRIPTS.md)、
> 私包 SOP [`PRIVATE-COMPOSER-PACKAGES.md`](./PRIVATE-COMPOSER-PACKAGES.md)、教程 `docs/08-部署上线.md`。

## 🔴 阻塞项（解决前不上线）—— 业务模板位

> 骨架无内置阻塞项。每次上线若有下列类型的风险，复制一节填写，未清零不上线。
> 典型来源：新增/删除字段的数据迁移、ACL 授权缺口、富文本字段 cast 缺失（图片 72h 后 404）、
> 破坏性 schema 变更。判定风险面参考 `docs/09` 与漂移探针 `tools/db-yaml-drift-probe.php`。

<!-- 复制此模板填写每个阻塞项：
### B-<编号> ｜ <一句话问题>
- [ ] **决策方**：<架构师 / 业务侧 / 运维>
- [ ] **问题**：<具体风险与触发条件>
- [ ] **决策 / 执行**：<方案 a / b / c 或命令>
- [ ] **验收**：<通过判据>
- [ ] **详情**：<链接到本仓 plan / issue 文档>
-->

_（本次上线阻塞项：______________________ ；无则显式写"无"）_

## 🟡 高风险（评估后再决定）—— 业务模板位

> 骨架无内置高风险项。典型：前端独立 repo 调用的接口漏迁（本仓 grep 不到）、
> 默认可延后但需追踪的行为变更。

<!-- 复制此模板填写每个高风险项：
### <编号> ｜ <一句话问题>
- [ ] **决策方**：<团队>
- [ ] **决策**：<yes / no + 依据>
- [ ] **默认（N 天无反馈）**：<兜底动作>
-->

_（本次上线高风险项：______________________ ；无则显式写"无"）_

---

## 🟢 Ops 流程检查（通用，逐条核）

### O-0 ｜ 首次部署一次性步骤（new box 才需要走）

> 已上线的生产 box 不用走，跳到 O-1。新机器 / 灾备重建时按这个顺序：

- [ ] **系统包前置**（pull.sh 硬依赖，没装会 exit 1 罢工）
  ```bash
  # Debian / Ubuntu
  sudo apt install -y git openssh-client jq util-linux   # util-linux 含 flock
  # CentOS / RHEL / Alma
  sudo yum install -y git openssh-clients jq util-linux
  ```
  pull.sh 启动会 `require_command` 4 件：`git / composer / ssh / jq`；缺 `flock` 不报错但失去
  并发保护（macOS 默认无 flock，会 WARN 跳过），Linux 生产强烈建议装。装 jq 时若弹 needrestart
  dialog，Tab → 选 `<Cancel>` 避免误重启 php-fpm。
- [ ] **脚本部署完整性**（pull.sh / cache.sh / backup.sh 共用 `tools/_common.sh`，缺则同时挂）
  ```bash
  ls tools/_common.sh          # 必须存在
  git ls-files tools/_common.sh # 必须有输出（已入 git）
  ```
- [ ] **验证 PHP 在 PATH 且版本对**（sudo `secure_path` 可能跳过自定义 PHP 安装位置）
  ```bash
  which php && php -v   # 8.2+；不在或版本太低 → update-alternatives / 改 sudoers secure_path
  ```
  `tools/_common.sh` 的 PATH 守卫会补 `/usr/local/php/bin` 等常见位置，但若你的 PHP 装在别处仍需手配。
- [ ] **拉主仓**（远端用 SSH，pull.sh 私包 deploy key 全靠这个）
  ```bash
  cd /opt   # 或你约定的部署根
  git clone git@gitee.com:<你的账号>/<你的仓库>.git
  cd <你的仓库>
  git remote -v   # 期望 git@... 不是 https://...
  ```
- [ ] **不要先手动 `composer install`**：pull.sh Step 5 自动判 vendor/ 存在性 → 缺则 install、有则
  update 私包。手动容易装错引号 / dev 依赖 / 不切 production.json。
- [ ] **建 `.env`**（pull.sh 的 `is_production` 守卫依赖它）
  ```bash
  cp engine/.env.example engine/.env
  vim engine/.env      # APP_ENV=production / DB_* / REDIS_* / 其它凭据
  cd engine && php artisan key:generate && cd ..
  ```
- [ ] **配私包 deploy key**（一次性，多次部署复用）：详 [`PRIVATE-COMPOSER-PACKAGES.md`](./PRIVATE-COMPOSER-PACKAGES.md) §4.3
- [ ] **跑首次 pull.sh**（`--production` 显式声明，避免 `.env` 未建时 is_production 误判）
  ```bash
  sudo sh pull.sh --production
  ```
- [ ] **DB migrate + seed**：`cd engine && php artisan migrate --force`（首批账号按需 `--seed`）
- [ ] **storage 软链 + 权限**：pull.sh Step 6 调 cache.sh 自动处理
- [ ] **supervisor + cron**：queue worker + `schedule:run`（O-2 / O-3）+ 每晚 `sh cache.sh` + 每天
  `backup.sh`（见 [`SCRIPTS.md`](./SCRIPTS.md) crontab 标配）

### O-1 ｜ 生产 DB migration 全 applied

- [ ] `php artisan migrate:status`，所有 migration **状态 = Ran**
- [ ] 有 Pending → 评估本次是否需应用（pull.sh Step 6.5 只告警不自动跑，人工决定后 `migrate --force`）

### O-2 ｜ Queue worker + after_commit

- [ ] `config/queue.php` 各 connection `after_commit = true`
- [ ] 生产 supervisor 跑 `queue:work` 至少 1 个 worker
- [ ] 操作日志 / 登录记录走队列 → 没 worker 会"日志永远 0 条且无报错"（docs 坑 #21）

### O-3 ｜ Schedule cron 全在跑

- [ ] `crontab -l` 含 `* * * * * cd /path/to/app/engine && php artisan schedule:run >> /dev/null 2>&1`
- [ ] 验证：`php artisan schedule:list` 条数与基线对齐
  - **骨架基线 = 2 条**：`moo:cloud:push`（每分钟，`cloud.enabled` 为真时）+ `queue:retry all`（每 10 分钟）
  - host 每加一条调度，基线 +1；上线前更新此处期望数

### O-4 ｜ `.env` 配齐

- [ ] 生产 `.env` 与 `.env.example` 同步（新增键别漏：`DB_*` / `REDIS_*` / `JWT_SECRET` / 云推 token 等）
- [ ] `CACHE_STORE=redis`（雪花 ID / JWT 黑名单跨 worker 共享）+ `QUEUE_CONNECTION=redis`（见 docs 8.3）

### O-5 ｜ 运行时 / 慢 SQL 上云（moo-monitor，可选）

- [ ] `MOO_MONITOR_CLOUD_ENABLED=true` + `MOO_MONITOR_CLOUD_TOKEN=<云端接入 Token>`（见 docs 8.5 / 第 10 章）
- [ ] `storage/moo-monitor/` 可写（pull.sh 收尾 chown 已覆盖 storage）
- [ ] 端到端：生产复现一次异常 → 1-2 min 后（或手动 `php artisan moo:cloud:push`）云端出现该条

---

## 部署前 Last-mile Sanity

在 `engine/` 跑，任一不通过 → 暂缓上线找根因：

```bash
cd engine
php artisan migrate:status                          # 全 Ran
php artisan route:list --except-vendor | \
  grep -cE 'GET|POST|PUT|PATCH|DELETE|HEAD'         # 与基线对齐（骨架基线 = 27，host 随业务增长更新）
php artisan schedule:list | grep -c cron 2>/dev/null || \
  php artisan schedule:list                         # 条数与 O-3 基线对齐（骨架基线 = 2）
sh ../release-check.sh                               # 机器闸门：脚本语法 + composer + 全量测试全绿
```

> **基线怎么建**：首次部署稳定后，把当次 `route:list --except-vendor` 与 `schedule:list` 的数量
> 记进本文件（替换上面的骨架基线 27 / 2）。以后每次上线用 `wc -l` 对齐，数量漂移即代表有路由/调度
> 被意外增删——追根因再放行。可进一步把响应快照 committed 进 `engine/plans/.audit-*` 做 `git diff --no-index` 零字节对账。

## 部署后 30 min 验证

- [ ] 抽样 5 个高频 endpoint `curl` 检查响应字段集 + 200
- [ ] `storage/logs/laravel-$(date +%Y-%m-%d).log` 无 5xx / Exception
- [ ] `php artisan queue:failed` 数量与上线前对齐；`failed_jobs` 无 5 分钟内新增
- [ ] `GET /up` → 200（健康检查）
- [ ] 任一异常 → 准备 rollback

## 部署后 24h 守护

- [ ] 富文本字段（若本次有）72h 内图片是否 404（cast 缺失的典型症状）
- [ ] 抽查本次改动涉及的 ShouldQueue Job 跑过一次、`failed_jobs` 无新增
- [ ] daily log 次日 00:00 翻篇后 PHP-FPM 仍能写入（验证 cache.sh 的 predcreate + cron 生效，无 `append mode` 报错）

## Rollback 触发条件与决策表

满足**任一**立即 rollback：

| 触发条件 | 判据 | 决策 |
| --- | --- | --- |
| last-mile sanity 任一 fail | `release-check.sh` 非零 / route·schedule 基线不齐 / migrate 有 Pending | 不上线，回滚到上一个 tag |
| 30 min 内 5xx 高于基线 | 错误日志 / 监控告警明显上升 | 立即 rollback |
| 队列大面积失败 | `queue:failed` 骤增、`failed_jobs` 持续增长 | rollback + 排队列根因 |
| 本次业务风险项被命中 | 🔴/🟡 段填写的验收判据未达成 | 按该项决策方预案处理 |

**Rollback 流程**（tag 锚定发版天然可回退）：
```bash
sudo sh pull.sh --tag <上一个稳定 tag>   # detached 锚回旧版，走完整 composer/cache 收尾
cd engine && php artisan migrate:status  # 确认 schema 与回退后的代码兼容（有破坏性迁移需单独回滚 DB）
```
> ⚠️ **数据迁移不自动回滚**：若本次含破坏性 schema 变更（drop/rename 列），代码回退后数据层需按
> 迁移的 RESTORE 预案单独还原——见 `docs/09` 「一次性数据迁移可回滚范式」。备份由 `backup.sh` 每日产出。

## 决策表（拍板单）

| # | 项 | 决策方 | 状态 | 决策内容 |
| --- | --- | --- | --- | --- |
| B-_ | _（业务阻塞项）_ | | ⏳ | |
| _ | _（业务高风险项）_ | | ⏳ | |
| O-1 | migrate:status | 运维 | ⏳ | |
| O-2 | queue worker | 运维 | ⏳ | |
| O-3 | schedule cron | 运维 | ⏳ | |
| O-4 | .env 配齐 | 运维 | ⏳ | |
| O-5 | 运行时/慢SQL 上云 | 运维 | ⏳ | |

决策完成后把 ⏳ 改 ✅ 并填决策内容；已完成的上线记录可归档到文末。
