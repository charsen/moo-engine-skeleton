# 计划：监控标准件接入（moo-monitor-laravel + moo-scaffold-cloud）

> 写给后续执行者（人或 AI 会话）的完整交接计划。**动手前先通读本文档 + 仓库根 CLAUDE.md**。
> 制定于 2026-06-12，作者与 AI 讨论定稿；若 moo-monitor-laravel 此后又有演进，以包的当前源码为准，本文档只锁定「编排与定位」决策。
> 2026-07 同步：本文是历史执行计划，旧文中的本地 path / 同级目录说法已不再作为安装口径。
> 当前过渡期通过 Composer VCS 获取 `moo-monitor-laravel` / `moo-scaffold` / `moo-system`；
> 目标状态为 monitor / scaffold 发布到 Packagist，只有 `moo-system` 保留 VCS 授权。

## 0. 一句话任务

把监控做成本骨架的**强制标准件**：教程第 1 章末尾新增 1.7 节（装 moo-monitor-laravel + 连 moo-scaffold-cloud），改写第 4/8 章的过时段落，新增第 10 章云端进阶，并顺手修掉 moo-scaffold 3.9.0 拆分留下的 4 处遗留。

## 0.5 ⚠ 执行状态更新（2026-06-12 rebase 后，先读这段再看下文）

本计划制定当天，作者已用 `018e7c7` + `4f7cfb8` 两个提交完成了一部分：

- **已完成**：§3 表的 #6（骨架接入：engine 显式 require monitor 包；当前过渡期通过 VCS 解析；§4 的 4 处遗留全清，bootstrap 已无 ExceptionDispatcher）、#3（docs/04 §4.5 已改写）、#4（docs/08 §8.2/§8.5 已改写）、#7 的 README 部分（配套包表已提 push/mcp/migrate 三命令）。
- **作废**：#2（docs/03/04 内联 bootstrap 补 reportable 行）——MonitorProvider **自动注册** reportable 钩子，宿主一行都不用写，1.7 节也因此更简单（装包 + env 即生效）。
- **剩余待做**：#1（docs/01 新增 1.7 节——注意 monitor 同时是 scaffold 的传递依赖，第 1 章单独装与第 2 章装 scaffold 不冲突，composer 会去重）、#5（第 10 章云端进阶）、#7 其余（docs/README 章节表、引导器 CHAPTERS、CLAUDE.md 定位句、HANDOFF）、#8（沿途引线）、监控 Feature 测试。
- 验收③ 调整为：全仓 grep `ExceptionDispatcher|SCAFFOLD_SQL_SLOW|SCAFFOLD_RUNTIME|SCAFFOLD_CLOUD` 已清零（仅允许出现在本文档），保持即可。

## 1. 背景（前情，缺这段会误判）

1. **2026-06-12，moo-scaffold 3.9.0**（commit `1103c11`）把监控链路整体拆给了新包
   **`charsen/moo-monitor-laravel`**（MIT，当前 VCS 过渡，目标发 Packagist）：
   - headless 采集 SDK：运行时异常 + 慢 SQL，hash 聚合、敏感字段脱敏；
   - 本地缓冲优先：落盘 `storage/moo-monitor/`（不连云也完整可用）；
   - 命名空间 `Mooeen\Monitor`，provider `MonitorProvider`，config `moo-monitor.php`，env 前缀 `MOO_MONITOR_*`；
   - 云端推送 `moo:cloud:push`（增量、幂等）→ moo-scaffold-cloud；**`moo:cloud:mcp`** 可让 AI 工具读云端错误并回写处理状态；
   - 自带「从 moo-scaffold ≤3.8 本地目录迁移」能力。
2. **moo-scaffold-cloud** 是接收端 SaaS（多项目集中查看/告警/处置；免费档 ≤3 项目；
   鉴权 = 项目 token 放 POST body）。细节见该仓库 `docs/requirements.md`。
3. **本骨架通过 path symlink 实时消费 moo-scaffold**——3.9.0 已即时生效，骨架现存 **4 处遗留**（见 §4，其中 1 处是潜伏炸弹）。
4. 骨架当前状态：九章教程 + 网页引导器（docs/index.html），`php artisan test` = **41 passed**，踩坑表 **27 条**。当前编排：
   1 装Laravel → 2 moo-scaffold+foods → 3 JWT(自建User) → 4 JWT加固(**4.5 异常采集** ← 老接法所在) → 5 ACL → 6 移动端 → 7 moo-system(商业) → 8 部署(**8.2/8.5 慢SQL段** ← 已过时) → 9 增量开发。

## 2. 定位决策（为什么这么编排，不要走样）

- **本骨架是作者团队未来后端项目的强制基线**，开源只是展示思路——监控与 JWT/限流/操作日志同级，是**必装标准件**。教程措辞必须是「本骨架约定/必装」，**禁止写成"可选/顺手/按需"**。
- **作者拍板：监控教学放在第 1、2 章之间**。理由：① 裸 Laravel 上配置最简单；② 新手最怕「不知道哪儿出错了、在哪看、连日志文件在哪都不知道」——监控先行，后面 2~9 章的全部 27 个坑都有了"去哪看"的答案；③ 到第 10 章连云端时，读者推上去的是自己一路踩坑攒出的**真实数据**。
- **实现方式 = 第 1 章追加 1.7 节，不新开章**——避免 2~9 章全部改名的编号雪崩（文件名、引导器、坑表互引）。
- 外部读者没有 cloud 账号：1.7 写明「本地落盘已完整可用；云端段需注册（免费档 ≤3 项目）」，不破坏强制叙事。

## 3. 编排定稿（执行清单）

| # | 位置 | 内容 | 性质 |
|---|---|---|---|
| 1 | **docs/01 新增 1.7「接入监控（本骨架标准件）」** | 装包（当前过渡期先声明 `git@gitee.com:charsen/moo-monitor-laravel.git` VCS，再 `composer require "charsen/moo-monitor-laravel:dev-master as 0.1.99"`；Packagist 目标版本可解析后改为 `composer require "charsen/moo-monitor-laravel:^0.1"`）→ `.env` 配 `MOO_MONITOR_*` + cloud token → **故意 throw 一个异常** → 看 `storage/moo-monitor/` 落盘 → `moo:cloud:push` → 云端面板看到它。闭环 ≤15 分钟，主题句：「从此报错有地方看」 | 新增 |
| 2 | docs/03、docs/04 内联的 bootstrap/app.php 代码段 | 必须带上 1.7 加的 reportable 行（读者跟做第 3/4 章会整段重写 bootstrap，不带就抄丢了——历史上 ch6 的 TestCase 微调就踩过同类断点） | 改写 |
| 3 | docs/04 §4.5「异常采集与节流」 | 重写：监控已在 1.7 上岗，本节只讲 dontReport / throttle 与监控的边界关系 | 改写 |
| 4 | docs/08 §8.2 写权限段 + §8.5 慢SQL段 | 落盘目录 `scaffold/` → `storage/moo-monitor/`；env 改 `MOO_MONITOR_SQL_SLOW_*`；生产 www-data 写权限说明同步 | 改写 |
| 5 | **新增 docs/10（云端进阶）** | 聚合/告警、`moo:cloud:mcp`（AI 读错回写——全教程独有亮点，单独一节）、≤3.8 迁移、多项目管理 | 新章 |
| 6 | 骨架代码 | `engine/composer.json` require 必含 monitor 包（+repositories path 条目）；`.env.example` 换 `MOO_MONITOR_*`；bootstrap 接线替换；修掉 §4 的 4 处遗留 | 标准件 |
| 7 | 周边同步 | docs/README 章节表 +1.7 提示+第10章；引导器 index.html CHAPTERS 数组 + 报错文案「01~09」→含 10；根 README 章节表/账号表无关但「✨包含什么」加监控一条；CLAUDE.md 写入「横切设施取舍以团队生产必需为准」的定位 + 第 10 章进度；HANDOFF §0/§6 | 同步 |
| 8 | （酌情）第 2~9 章沿途坑位 | 挑 3~5 个真会产生异常的坑（如 #26 的 500）补一句「去 storage/moo-monitor/ 看，它已经被记下了」——克制，别每坑都加 | 增润 |

## 4. 必修的 3.9.0 遗留（做 #6 时一并清掉）

| 遗留 | 位置 | severity |
|---|---|---|
| 引用已删除的 `Mooeen\Scaffold\Support\ExceptionDispatcher` | `engine/bootstrap/app.php:13` 和 `:51` | **高**：潜伏 class-not-found——测试 41 绿只因懒加载（无真实异常上报触发），生产一出未捕获异常就炸 |
| 死 env 开关 `SCAFFOLD_SQL_SLOW_*` | `engine/.env.example:74-75`（本机 `.env` 也有，顺手清） | 中 |
| 发布版 config 死段 runtime/sql_slow/cloud | `engine/config/scaffold.php` ~209-260 | 低：scaffold 3.9.0 不再读 |
| 文档过时 | docs/04 §4.5、docs/08 §8.2/§8.5 | 中（即本计划 #3/#4） |

## 5. 实施约定（沿用本仓库已验证的工作流）

- **三棒流水线**：实操（每条命令照实记录到 /tmp worklog——教学棒的唯一素材，原则1不许编造）→ 教学（按 worklog 写文档）→ 观察修正（审查+清冒烟数据+提交推送）。历史上 agent 曾死于 API 错误/会话额度——主线要按工作树实际状态接管收口。
- **硬指标**：`php artisan test` 全绿（现 41，新增监控相关测试后以实际为准——至少 1 个「异常被记录到本地缓冲」的 Feature 测试）；`moo-system check` 6/6；pint；真机冒烟（8088，多 worker `--no-reload`）。
- **脱敏守则**：一切产出（含提交信息）不得出现作者具体生产项目名。提交前必跑脱敏 grep（**项目名清单本身也不入仓库**，在本机 CLAUDE.local.md 的「脱敏 grep 清单」里；没有该文件就向作者要清单）：`git diff HEAD | grep -iE '<清单>'` 应为空。
- **计数强迫症**：测试数/坑表条数散布在 根README、CLAUDE.md、HANDOFF、docs/README、docs/07 多处——改完全仓 grep 旧数字清零。坑表新增条目编号接现有最大号（现 27）。
- **教程时间线**：1.7 写的是「裸 Laravel 时点」——别引用第 2 章之后才有的概念；第 7 章那种「此刻跑出 X 是对的」的时点注释手法可复用。

## 6. 阻塞项与验收

- **阻塞**：cloud 项目 token 由作者提供（c.mooeen.com /app 面板签发）。没 token 时：本地落盘部分（含故意 throw 的真机实录）照做，1.7 云端小节与第 10 章的面板实录**留空位并标注 TODO**，不要贴编造的截图/输出。
- **验收**：① 测试全绿且含监控新测试；② 真机实录：throw → `storage/moo-monitor/runtimes/` 出现记录（有 token 则 + push 后云端可见）；③ 4 处遗留清零（全仓 grep `ExceptionDispatcher|SCAFFOLD_SQL_SLOW|SCAFFOLD_RUNTIME|SCAFFOLD_CLOUD` 仅允许出现在本计划文档）；④ 脱敏 grep 为空；⑤ 引导器能正常打开第 10 章。
