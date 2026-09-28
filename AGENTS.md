# AGENTS.md

本文件适用于整个 `moo-engine-skeleton` 仓库，**只写本仓特有约束**。对话授权、提交 / 推送 / 打 tag / 发布、敏感信息与最小披露、
验证门禁、E2E 与浏览器验证、Composer 三份 manifest、跨仓公共契约等通用规则随全局 `~/.agents/AGENTS.md`，本文件不重复。
冲突时按「系统 / 用户当前指令 > 离目标最近的 `AGENTS.md` > 全局」判断；版本、命令与接口以当前代码、manifest 和测试核实。

## 开工顺序

1. 按任务阅读 `README.md`、`docs/README.md`、对应章节、`engine/` 现码和测试。
2. 涉及初始化器或发布时，同时读 `init-project`、`release-check.sh`、开发/生产两套 Composer manifest、部署脚本与本地 lock 策略。
3. 涉及 moo 包时，以本仓 manifest 和相邻包现码核实当前契约；`../某个内部 Host/PACKAGES.md` 只作生态索引，不复制 host 规则。

## 项目定位

- 本仓是 Laravel 12 后端学习教程、可运行参考骨架和项目初始化器的组合，不是某个生产 host，也不是通用“最佳实践”全集。
- `engine/` 是与教程对应的完成态参考实现；`docs/` 负责让读者从零搭出同样结果；根目录初始化器负责把教程骨架安全裁成新项目。本仓目标是「教程能从零复现」，不能只让现成 `engine/` 偶然可运行。
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

- 本地学习/开发、测试服务器和生产部署各用一份 Composer manifest（三份怎么分工见下节「Composer 三份 Manifest 与私包接入」），都不跟踪 lock。`engine/composer.lock` 由各环境本地生成并由 Git 忽略；不得创建或提交 `engine/composer.production.lock`。生产部署由 `pull.sh` 暂时把 `composer.production.json` 覆盖到 `composer.json`，失败时回滚，随后使用当前环境的本地 lock 安装并显式更新私包。
- `charsen/moo-feedback` 是公开发行包；本仓接线来源以三份 manifest 为准。私有 System、Upload、Contract 依赖授权仓访问；文档不得暗示读者能匿名安装私包。
- 版本、PHP/Laravel 支持面和命令参数以当前 manifest、包发布状态和真实 `artisan` 输出为准，不沿用历史文档数字。
- 生产部署涉及缓存、队列、多 worker、Redis、目录权限和独立 `.env`；不要把 SQLite、sync queue 或单进程教程默认值描述成生产方案。

## Composer 三份 Manifest 与私包接入

`engine/` 下有三份 Composer 清单，按环境分流，不是三套可独立演进的配置：

| 文件 | 环境 | 私包 Repository | 私包约束 |
| --- | --- | --- | --- |
| `engine/composer.json` | 本地开发 | sibling `path`（`options.symlink: true`） | `^x.y@dev` |
| `engine/composer.test.json` | 测试服务器 | Gitee `vcs` | `dev-dev`（跟随各包远端 `dev`） |
| `engine/composer.production.json` | 生产服务器 | Gitee `vcs` | **已发布稳定 semver** |

- **必须一致**：以三份 manifest 为准的私包依赖集合、PHP/Laravel 与非 Moo 运行时依赖基线、测试/生产仓库 URL，以及 `extra.moo-private-packages` 元数据。契约包无 Provider 和发布资源，两个字段均为 `null`；一致性由 `ComposerProfilesTest` 守护。
- **按环境分流**：repository 类型与包版本约束按本节表格设置，具体值从 manifest 读取，不在规则中固定版本快照。
- 本仓现状要如实理解：本地 profile 另含开发工具——10 项 `require-dev`（比测试/生产多 `beyondcode/laravel-dump-server`、`laravel/sail`、`pestphp/pest`、`pestphp/pest-plugin-laravel`）和 Laravel 默认 `scripts` 段（`setup` / `dev` / `lint` / `post-create-project-cmd` / `post-root-package-install`），测试与生产各只有 6 项 `require-dev` 和部署相关脚本（如 `clear-all`）。这是既有实态，不是私包分流违规，也不要为了“对齐”把这些开发工具搬进测试/生产 profile。
- 私包清单与访问前提见 `PRIVATE-COMPOSER-PACKAGES.md`；公共组织/姓名接口由 `moo-contract` 提供，System 注册默认绑定。`moo-feedback` 是公开包，不进入私包授权元数据，但本仓显式配置 path/VCS 用于联调。
- **新增、移除、改名私包**：三份清单同步改 `repositories`、`require` 约束与 `extra.moo-private-packages`，并同步 `PRIVATE-COMPOSER-PACKAGES.md` 的清单说明；随后在 `engine/` 跑 `php artisan test --filter ComposerProfilesTest` 与三份 `composer validate --strict --no-check-publish`。只改本地 profile 会在 CI/部署暴露；`pull.sh` 的私包列表是用 jq 从当前 profile 的 `extra.moo-private-packages` 现解析的，**不得在 `pull.sh` 里另维护一份包名列表**。
- `engine/composer.lock` 与 `engine/composer.test.lock` **不入 git**（见根 `.gitignore`），各环境由部署流程解析生成；不要把某个 profile 的 lock 当作跨环境真值。测试服首次没有 `composer.test.lock` 时会完整解析一次该 profile，之后每次只 `update` manifest 里的私包。
- 测试服固定 `sh test-pull.sh`（导出 `DEPLOY_COMPOSER_PROFILE=test` 与 `DEPLOY_BRANCH=dev` 后 `exec sh pull.sh --latest`，令 Host 追 `dev` 且用 `composer.test.json`）；生产 `sh pull.sh --tag <host-tag>`（读 `composer.production.json`，Step 4 临时覆盖到 `composer.json`）。常规部署不得使用本仓仅有的两个跳过开关：`--skip-private-pkg`（跳过私包权限验证，只给本地 path 调试）和 `--force-reset`（丢弃本地已跟踪改动）。本仓没有 `--skip-migrate` / `--no-maintenance` 参数，迁移只在收尾 Step 6.5 检测待执行项、不自动跑；参数边界见 `SCRIPTS.md` 的 `pull.sh` 段。
- **scaffold 前端资源（已解决）**：`charsen/moo-scaffold` 已进 manifest 且 `publish-tag` 为 `"public"`，部署时 `pull.sh` Step 5.5「同步私包 publish 副本」会对它执行 `vendor:publish --tag=public --force`，刷新 `engine/public/vendor/scaffold`（该目录被 `engine/.gitignore` 忽略）。scaffold 前端资源在本地仍靠 `composer.json` 的 `setup` 脚本里的 `vendor:publish --provider='Mooeen\Scaffold\ScaffoldProvider' --tag=public --force`，以及 `docs/02`、`docs/08` 的手工命令刷新——本地初始化仍然需要这些入口，但已不再是「部署不刷新」的状态。

## Schema 与生成边界

- schema YAML 是结构设计源；修改后先 `moo:fresh`，再按当前 scaffold 流程生成或增量迁移。schema、snapshot、migration 构成同一变更单元。
- `Traits`、Enum 等再生成区不能承载手写业务；Model、Controller、Request 等一次生成区再次运行前必须先读 scaffold 当前覆盖规则并审查 diff。
- 生成命令只能在 `engine/` Laravel 根运行。根仓不是 Laravel 应用，不能在根目录假设有 `artisan`。
- 删除、改名、nullable/default/unique/index 变化需同时验证空库安装和存量升级；禁止手改数据库后再倒推教程。

## API、认证与 host 契约

- 后台与移动端 guard 必须隔离，JWT 的 guard claim、persistent claims、blacklist、refresh 和退出语义不可混用。
- Moo 扩展包使用同一个 `moo-<name>` stem 对齐 Composer 包名、Host 配置文件、配置命名空间、配置发布标签和后台中间件组；例如 `charsen/moo-foo`、`config/moo-foo.php`、`moo-foo.*`、`moo-foo-config`、`moo-foo`。改变其中任一名称都按公共契约变更处理，同时核查包、Host 与部署缓存。
- 每个带后台路由的扩展包都由 Host 注册独立的完整认证组；不得复用可承载登录接口的 `admin` 组或借用其他包的组。至少验收匿名 401、已认证但无动作权限 403，以及授权成功。
- 公开业务包需要图片/文件时只定义窄媒体契约并提供可独立运行的默认实现，不硬依赖私有上传包；私有业务包可直接依赖 `moo-upload`，并在自身 Provider 注册 namespaced purpose、消费适配器与审计 verifier。Host 只负责私包仓库解析、`moo-upload` 独立安全组、ACL/API 元数据和环境配置，不复制第二套上传状态机。
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
