# HANDOFF — 换机/新人上手交接

> 本文件随仓库分发，已按脱敏守则编写（不含生产项目名与真实凭据）。
> 机器私有信息在 `CLAUDE.local.md`（gitignored，见第 4 节模板自行重建）。

## 0. 一句话现状（2026-06-12 历史批次，2026-07 已同步包来源口径）

生态四仓库（moo-engine-skeleton / moo-scaffold / moo-system / moo-scaffold-cloud）
在 2026-06-12 批次收口时全部干净、全部已推送；当前以本文件后续的 2026-07 包来源说明为准。

最近批次（06-11 晚 ~ 06-12 晚，已全部入 master）：

- **全面审查修复 + 第 9 章**：iResource 幻影路由、跨守卫过期续签、筛选死代码、
  登录专用限流、慢 SQL 开关、文档纠错；第 9 章「日常增量开发」9.1~9.9
  （文件名是 `docs/09-增量开发工作流.md`，H1 才叫「日常增量开发」——按文件名找）：
  加字段 / moo:adder / 移动端只读 Food + 守卫隔离实证 / FoodResource 链式字段控制。
  测试 21 → **43 passed**，踩坑表扩到 **27 条**（含 3 个本批实测新挖的生成器坑 #25~#27）。
- **`cbc171e`（06-12 早）教程前半大修（README + docs 01-05）**：README 删 git-lfs
  虚假前置（docs/README 已明写「无需 git-lfs」）；01 曾拆解 PHP 8.2/8.3 矛盾（2026-07 已降回 PHP 8.2+ 口径）；
  03 修 5 处事实错；05 开篇声明「适用代码状态」、Gate 伪代码补成可照抄实现并标注快照错位。
- **`018e7c7` + `4f7cfb8`（06-12）监控标准件接入（部分）**：scaffold 3.9.0 拆分留下的
  4 处遗留全清（ExceptionDispatcher 引用、死 env、死 config、docs/04/08 过时段）；
  engine 显式 require moo-monitor-laravel；docs/04 §4.5 改写、docs/08 §8.2/§8.5 改写；
  README 配套包表已提 push/mcp/migrate 三命令。
- **本次（06-12 晚）监控接入收尾**：docs/01 新增 1.7 节（装包 + 配 .env + 故意抛异常 +
  本地缓冲 + 推送云端，token `moo_5a8f9d8a03...` 已可用）；docs/10 新增第 10 章
  「云端监控进阶」（聚合告警 / **AI 辅助处理 MCP 接入**全教程独有亮点 / ≤3.8 迁移 / 多项目管理）；
  docs/README、docs/index.html、根 README、CLAUDE.md 同步更新章节表与进度。
  **测试 43 passed**（新增 MonitorTest 2 个：runtime_exception_is_recorded_to_local_buffer + 
  base_exception_is_not_reported）。
- **2026-07-05 包来源/示例修订**：开源包当前 VCS 过渡、目标 Packagist；`composer.production.json`
  改为目标生产样例；CI 改用当前 VCS 配置；补最小 `UploadController` 与上传路由，头像表单不再 404。
  **测试 45 passed**。

> 术语速查（本节与第 7 节的黑话都有出处）：「iResource 幻影路由」→ docs 第 2 章
> （02:172 附近的「幻影路由」说明）；「跨守卫过期续签」等审查修复项 → docs 第 7 章
> `RegressionTest` 一节（07:196）；「守卫隔离实证」→ docs 第 9 章 9.8 节；
> 「guard claim 动态化」→ moo-system 1.6.12；`prv`/`lock_subject` → docs 第 4 章（见第 7 节）。

| 仓库 | 状态 | 备注 |
|---|---|---|
| moo-engine-skeleton | tag `0.1.0` / `0.2.0`，之后已积一大批提交（见上） | 十章教程 + 网页引导器 + CI workflow，45 测试全绿 |
| moo-scaffold | 3.x 开发中，LICENSE(MIT) 已补 | **开源包**，目标发布 Packagist；当前过渡期仍可用 VCS |
| moo-system | tag 至 `1.6.12` | **商业包**（proprietary）；1.6.12 含 guard claim 动态化 |
| moo-scaffold-cloud | 干净、已推送 | 云端监控面板，本交接按需克隆 |

## 1. 新机环境

```bash
# PHP：当前仓库按 PHP 8.2+ 可安装版本解析。
brew install php composer node mariadb
brew services start mariadb

# 数据库：brew 装的 MariaDB 初始 root 无密码（走 unix_socket），先设密码再建库：
mysql_secure_installation        # 按提示设 root 密码（教程示例值 7777）
mysql -uroot -p -e "CREATE DATABASE moo_skeleton CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

**仓库访问权是前置**：当前过渡期 `moo-scaffold` / `moo-monitor-laravel` 仍可通过 VCS 解析，
`moo-system` 必须通过 VCS 授权分发。先联系作者把你的 Gitee 账号加为相应仓库成员/协作者；
Packagist 同步开源包目标版本后，开源包将不再需要 Gitee 权限。

## 2. 克隆

```bash
mkdir wwwroot && cd wwwroot
git clone https://gitee.com/charsen/moo-engine-skeleton.git
# 按需：moo-scaffold-cloud
```

> ⚠ **依赖获取方式**：`engine/composer.json` 当前通过 VCS 解析三个 moo-* 包，不再依赖本地同级 path。
> `moo-system` 是商业包，缺授权时第 3 节 `composer install` 会失败。开源包目标走 Packagist；
> 在 Packagist 同步目标版本前，若 VCS 无权限也会安装失败。

## 3. skeleton 初始化（同 README「方式 A」）

```bash
cd moo-engine-skeleton/engine
composer install
cp .env.example .env && php artisan key:generate     # .env 预填 moo_skeleton/root/7777，通常只需改 DB_PASSWORD
php artisan jwt:secret --force
php artisan vendor:publish --provider="Mooeen\Scaffold\ScaffoldProvider" --tag=public --force
php artisan migrate --seed       # 种子账号两个，归属与验证见下方「首登自检」
php artisan moo:account:add charsen --password=skeleton2026 --role=admin
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
php artisan test                 # ✅ 应 45 passed —— 环境一切正常的硬指标
```

**首登自检**（两个种子账号分属两个守卫、两个登录入口，各自拿到 token 即装好了）：

```bash
# admin 守卫（Personnel，后台管理入口 api/admin）
curl -s -X POST http://127.0.0.1:8088/api/admin/authenticate \
  -H 'Content-Type: application/json' -d '{"account":"13800000000","password":"admin888"}'
# user 守卫（自建 User，移动端入口 app）
curl -s -X POST http://127.0.0.1:8088/app/authenticate \
  -H 'Content-Type: application/json' -d '{"email":"admin@example.com","password":"password"}'
```

> 前端构建（可选）：根路径 welcome 页用 `@vite`，未 `npm install && npm run build`
> 时访问 `/` 会报 `Vite manifest not found`——只跑后端接口教程可忽略；
> 管理端静态资源走上面的 `vendor:publish`，不受影响。

**不随 git 走、需重建的**：`engine/.env`、`vendor/`、`scaffold/accounts.yaml`、
`public/vendor/scaffold/`、数据库数据、`CLAUDE.local.md`——以上命令 + 第 4 节全覆盖。

## 4. 重建 `CLAUDE.local.md`（机器私有注记，gitignored）

```markdown
# CLAUDE.local.md —— 本机私有注记（不随仓库分发）
- php：PHP 8.2+ 即可
- 数据库真实凭据：root / <你的密码>
- Git 远程：https://gitee.com/charsen/moo-engine-skeleton.git（当前私有）
- 包访问：当前过渡期 scaffold/monitor 可用 VCS；moo-system 必须有授权；cloud 按需
```

## 5. 关键决策与守则（新会话必读，CLAUDE.md 有完整版）

1. **包定位**：moo-scaffold / moo-monitor-laravel 开源（MIT，目标发 Packagist）；
   moo-system 商业（proprietary，必须 VCS 授权）。
   教程第 1~6 章零付费依赖是核心卖点。
2. **脱敏守则**：本仓库一切资料不得出现作者具体生产项目名称（统一"作者生产项目"指代）。
   工作树已全量脱敏；**历史提交信息未清**（见待办 #2）。
3. **架构要点**：移动端 user 守卫永久用自建 User（email 登录）；admin 守卫第 7 章起用
   Personnel；Gate `acl_authentication` 是 **host 契约**（宿主项目自己实现、moo-system
   只调用，契约 stub 由 `--tag=moo-system-stubs` 发布）且**多态**（同一 Gate 服务两个
   守卫各自的用户模型）。
4. **教程代码 vs 仓库代码**：第 3~6 章的"中间态"代码只内联在文档里，**仓库只存第 7 章后
   的最终态**——照前几章敲代码时发现仓库"对不上"是正常的，不是你错了。第 5 章开篇已有
   「适用代码状态」声明与三处快照错位标注（`cbc171e`），其余章节遇到差异以文档内联代码为准。

## 6. 待办清单（按优先级）

| # | 事项 | 等什么 |
|---|---|---|
| 1 | 开源包发布同步：moo-scaffold 3.x 与 moo-monitor-laravel 0.1.x 在 Packagist 可解析后，删除 `engine/composer.json` / docs 里的 scaffold+monitor VCS 过渡配置，只保留 moo-system VCS | 作者操作 |
| 2 | 本仓库公开时的历史脱敏：历史压缩 vs 推新公开仓库，二选一 | 作者决策 |
| 3 | moo-system 商业化：LICENSE 授权条款、分发凭证机制（现状只能找作者人肉给源码，见第 2 节）。原列的「`--tag=moo-system-stubs` 修复」经核疑为过时待办——该 tag 已存在且可用（`MooeenSystemServiceProvider` 发布 6 个契约 stub，`Doctor` 仍引导使用），需作者确认已完成或补最小复现后再派工 | 作者决策 |
| 4 | CI 首跑：GitHub 镜像后配 secret `MOO_PACKAGES_DEPLOY_KEY`，按报错微调 `.github/workflows/tests.yml`（未实测） | 镜像后 |
| 5 | Gitee Pages：仓库公开后，服务 → Pages → 部署目录 `docs/` | 公开后 |
| 6 | 版本：`0.2.0` 后已积一大批提交（审查修复加固 + 第 9 章 + 守护测试 + 教程前半大修 + 监控接入 + 包来源修订 + 上传端点，21→45 passed），建议打 `0.3.0` | 作者决策 |
| 7 | **监控标准件接入**（monitor+cloud）：完整执行计划见 `plans/monitor-cloud-integration.md` §0.5。**已完成**：#1（docs/01 新增 1.7 节）、#5（docs/10 新增第 10 章）、#7 其余（docs/README、引导器 CHAPTERS、根 README、CLAUDE.md 同步）、#3/#4/#6（018e7c7 / 4f7cfb8 已清四处遗留）、#8（沿途引线 2 处：坑 #6 / #26）、监控 Feature 测试（MonitorTest 2 个方法，43 passed）。cloud token = `moo_5a8f9d8a03273ff0715ae232a660c0ba7bc2f325` 可用（作者提供）。**本待办已完成** | 已完成 ✅ |
| 8 | 剩余工程活（可委托 AI）：移动端 `PUT app/me/password` 改密码（零付费 track 的账号自管理）。最小 UploaderController 已在骨架补齐，头像表单上传不再 404 | 作者决策 |

## 7. 已知局限备忘

- jwt-auth：过期但仍在续期窗口内的 token 调 logout 返回 200 但未真正拉黑
  （库设计局限，docs 第 4 章已记录）；
- 坑 #22：生产 `cache:clear` 会清空 JWT 黑名单 → 已注销 token 复活（docs 第 8 章）。
- jwt-auth：续签出的新 token **不携带 `prv` 声明**（`lock_subject=true` 往 token 写入的
  主体模型声明，定义与作用见 docs 第 4 章 4.1 末尾及该章末「主体查询」说明）——首轮
  refresh 后该层防护即失效（上游 vendor `Manager::buildRefreshClaims` 只保留
  persistent_claims+sub+iat，`guard` 在 persistent_claims 里所以存活、`prv` 不在）。
  当前跨守卫混用仍被 guard claim 校验 + 两套主键空间兜住（有 `RegressionTest` 守护，
  见 docs 第 7 章 07:196），但别把 `lock_subject=true` 当作长效保证。
