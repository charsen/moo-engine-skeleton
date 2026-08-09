# AGENTS.md

本文件适用于仓库根目录及全部子目录。规则冲突时依次服从：系统/用户指令、当前目录适用的代理规则、用户批准的任务方案、`notes.md`、现行教程与代码。文档和代码不一致时先查清哪一边落后；本仓的目标是“教程能从零复现”，不能只让现成 `engine/` 偶然可运行。

## 开工顺序

1. 先读 `notes.md`，再按任务阅读 `README.md`、`docs/README.md`、对应章节、`engine/` 现码和测试。
2. 涉及初始化器或发布时，同时读 `init-project`、`release-check.sh`、开发/生产两套 Composer manifest 与 lock。
3. 涉及 moo 包时，以本仓 manifest 和相邻包现码核实当前契约；`../wisdomcity/PACKAGES.md` 只作生态索引，不复制 host 规则。
4. 修改前读完目标文件及直接调用链。机械性、零语义且范围明确的小修可直接实施；非琐碎或涉及行为、接口、数据、权限、依赖、发布的改动先列计划并取得用户批准，范围或风险实质变化时重新确认。

## 长期记忆

- `notes.md` 是本仓已验证踩坑的长期底账。每条记录症状、根因、解法和日期；开工先读，踩坑确认后及时补充。
- 只写经代码、命令、测试或真实操作证实、且以后仍有复用价值的结论；不写任务进度、猜测、固定测试数量和容易过期的版本快照。
- 新证据推翻旧结论时修订原条目，避免同时保留互相矛盾的说法。
- 不记录密码、token、私有仓地址、真实业务系统名或其他不应进入公开仓库的信息。

## 项目定位

- 本仓是 Laravel 12 后端学习教程、可运行参考骨架和项目初始化器的组合，不是某个生产 host，也不是通用“最佳实践”全集。
- `engine/` 是与教程对应的完成态参考实现；`docs/` 负责让读者从零搭出同样结果；根目录初始化器负责把教程骨架安全裁成新项目。
- 教学代码刻意保持 Controller / Model / Filter / Resource 的轻量结构。不要为了架构洁癖强加 Service、Repository 或复杂分层。
- `Food` 是贯穿 schema、codegen、JWT、ACL、测试与增量开发的教学模块；删除或改名必须同时审查教程和初始化器清理行为。
- 第 3～6 章使用自建 `User` 演示 admin/user 双守卫；第 7 章的商业 `moo-system` 是可选进阶，接入后仅后台主体切到 `Personnel`，移动端 `user` 守卫仍使用 `User`。
- 公开仓库不得出现实际生产项目名称、内部域名或私有实现细节。需要说明来源时使用“作者的生产实践”等中性表述。

## 教程、代码与初始化器一致性

- 改一个命令、路径、响应、配置、测试断言或部署步骤时，必须检索 `README.md`、`docs/`、`engine/`、初始化器和 release-check 的同款描述。
- 教程中的命令应可按顺序复制执行；前置条件、运行目录、预期输出和失败判据必须写清，不能只给“最终正确代码”。
- 真实行为以可复现操作验收：Laravel 路由/测试、curl 响应、数据库结果或浏览器操作。只阅读源码不能证明教程成立。
- 不把本机 path repository、未发布版本、私有凭据或现成 vendor 偶然状态写成公开教程前提。
- `init-project` 会删除教学资产、改名、生成密钥并可重建 Git；修改它按破坏性工具审查，先验证 dry-run/清单、备份、失败回滚和路径边界。未经用户明确要求不得实际对有数据目录运行 `--force` 或 `--fresh-git`。

## Composer 与环境边界

- 本地学习/开发和生产部署使用不同 Composer manifest/lock，二者要分别保持可解析，不能用一套 lock 覆盖另一套语义。`engine/composer.lock` 仅本地生成并由 Git 忽略；`engine/composer.production.lock` 是发布输入，继续随仓库跟踪。
- 开源包按公开发行渠道安装；商业 `moo-system` 才依赖授权的私有仓访问。文档不得暗示读者能匿名安装商业包。
- 版本、PHP/Laravel 支持面和命令参数以当前 manifest、包发布状态和真实 `artisan` 输出为准，不沿用历史文档数字。
- 生产部署涉及缓存、队列、多 worker、Redis、目录权限和独立 `.env`；不要把 SQLite、sync queue 或单进程教程默认值描述成生产方案。

## Schema 与生成边界

- schema YAML 是结构设计源；修改后先 `moo:fresh`，再按当前 scaffold 流程生成或增量迁移。schema、snapshot、migration 构成同一变更单元。
- `Traits`、Enum 等再生成区不能承载手写业务；Model、Controller、Request 等一次生成区再次运行前必须先读 scaffold 当前覆盖规则并审查 diff。
- 生成命令只能在 `engine/` Laravel 根运行。根仓不是 Laravel 应用，不能在根目录假设有 `artisan`。
- 删除、改名、nullable/default/unique/index 变化需同时验证空库安装和存量升级；禁止手改数据库后再倒推教程。

## API、认证与 host 契约

- 后台与移动端 guard 必须隔离，JWT 的 guard claim、persistent claims、blacklist、refresh 和退出语义不可混用。
- 成功响应沿用当前 Resource/控制器形态，不自行增加统一 `{code,data}` 包装；验证错误为 422、未认证为 401，业务错误沿用既有 522 契约。
- Snowflake ID 对外按字符串处理；枚举保持 raw int，由调用点显式转换。
- 第 7 章 host 胶水、路由、ACL 和 seed 顺序须与当前 `moo-system` 契约一致。组织树 seed 不得用会跳过模型事件的捷径。
- `bootstrap/app.php` 负责路由/中间件/异常接线，`AppServiceProvider` 负责应用级注册；不要为了教程方便混淆职责。

## 网页引导器验收范围

- Scaffold 网页引导器只测试和验收视口宽度不小于 1024 CSS px 的电脑端。
- 手机端及宽度小于 1024 px 的电脑端不在产品、开发、测试和发布验收范围内。
- UI 改动至少在 1024 px 和一个更宽桌面视口验证；不为小屏回归阻塞交付。

## 验证与交付

- 文档改动至少运行 `git diff --check`，并逐项核对链接、路径、命令和版本。
- PHP/教程代码改动先跑相关测试，再执行仓库当前完整门禁；涉及页面流程还需真实浏览器验证，涉及接口还需 curl/数据库闭环。
- 初始化/发布相关改动运行 `./release-check.sh`，并如实说明开发与生产依赖、教程复现、真实部署哪些已验证。
- 不主动 commit、push、tag、发布或部署。提交前展示完整 diff 和验证结果并取得用户明确确认；保留用户已有改动，不混入无关文件。
