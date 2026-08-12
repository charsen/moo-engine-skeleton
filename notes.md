# notes.md — 项目踩坑随手记

本文件是本项目的「踩坑底账」：把踩过的坑一条条记下来，给未来的自己、也给接手的人省时间。

**记什么**——每条坑记清四要素：
- **症状**：当时看到的现象 / 报错信息
- **根因**：定位到的真正原因
- **解法**：怎么修好或绕过的
- **日期**：踩坑当天

**什么时候记**——踩坑当下随手记，别等事后补，细节当天最清楚；做升级、迁移、或接手项目前先通读一遍，能少踩很多重复的坑。

---

## 示例条目格式（照抄一条，填好即可）

### 一句话概括这个坑
- **日期**：YYYY-MM-DD
- **症状**：
- **根因**：
- **解法**：

---

<!-- 新坑往下加；习惯把最新的放最上面也行，自己顺手为准 -->

### 临时上传目录必须与扩展包的消费边界保持一致
- **日期**：2026-08-12
- **症状**：头像图片上传成功并返回相对路径，但提交人员资料时被判定为非法临时路径，文件无法搬入人员正式目录。
- **根因**：Host 上传端点使用 `tmp/images/...`，moo-system 默认头像管理器只接受配置约定的 `temp/...`；上传与消费分别验证时没有暴露这段跨组件契约。
- **解法**：Host 配置、上传返回值和包侧临时前缀统一为 `temp/`，用“上传临时文件 → 提交头像 → 搬入人员目录”的真实闭环测试守护；服务端按文件内容决定扩展名，并定时限量清理未被消费的过期临时文件。

### 扩展包配置改名必须连同 Host 接线原子升级
- **日期**：2026-08-11
- **症状**：扩展包已改用 `moo-foo.*`，Host 仍保留旧文件名、旧配置命名空间或旧发布标签；文件看似存在，但包读取不到 Host 覆盖，缓存环境还可能继续沿用旧值。
- **根因**：把配置文件名、`config()` 命名空间、发布标签和后台中间件组当成互不相关的实现细节，没有按同一个 `moo-<name>` 公共契约管理；Laravel 对无人消费的旧配置键不会主动报错。
- **解法**：扩展包统一用同一个 stem 命名 Composer 包、Host 配置文件、配置命名空间、发布标签和独立后台组；破坏性改名时包与 Host 同批修改，部署后清理配置缓存，并回归真实路由的 401、403 与授权成功。

### 官网 profile 删除 User 时不能连带删除共享认证基础设施
- **日期**：2026-08-11
- **症状**：官网初始化删除 `create_users_table` 迁移后，空库能建立 moo-system 表，但基础设施检查报 `sessions` 与 `password_reset_tokens` 不存在；修完表后，后台 JWT 过期测试又因辅助方法签名已裁剪、调用点未同步而失败。
- **根因**：Laravel 默认迁移把 User、密码重置 token、数据库 session 放在同一文件；按文件删除移动端主体扩大了业务裁剪范围，测试辅助方法也属于跨文件契约。
- **解法**：website profile 把该迁移改写为只创建共享基础设施表，并保留 Personnel password broker；同步改写所有 JWT 测试调用点。每种 profile 都必须在隔离空库跑完初始化器自带全量验证。

### 部署脚本必须在切换目标版本后解析私包 manifest
- **日期**：2026-08-11
- **症状**：按 tag 部署时，脚本先读当前工作树的 `composer.production.json`，再 checkout 目标 tag；目标版本新增或变更的私包可能绕过权限预检、强制更新和资源发布，publish 失败也只显示 warning 后返回成功。
- **根因**：把 manifest 解析当成静态前置检查，没有把它视为目标版本的一部分；收尾失败状态也未汇总进退出码。
- **解法**：checkout/pull 成功后才解析目标版本 manifest；私包 URL 统一用 `git ls-remote` 验证；publish、cache 或 pending migration 任一失败均以退出码 4 明确暴露。

### 初始化器删除教程工具时必须同步裁掉专属测试
- **日期**：2026-08-09
- **症状**：默认初始化会删除 `tools/tutorial-http.sh` 与 `tools/tutorial-sync-chapter7.php`，但第 7 步全量测试仍执行验证这两个文件的用例，导致新项目在其它检查全部正常时固定失败 2 条测试。
- **根因**：清理清单只覆盖教程资产，没有把引用这些资产的 `DeploymentScriptTest` 区段视为同一变更单元；在完整骨架上跑全量测试无法暴露“裁剪后”状态的问题。
- **解法**：用稳定起止标记包住教程工具专属测试；未传 `--keep-tutorial` 时，初始化器在删除工具后幂等移除该测试区段。每次修改初始化器清理行为都要在隔离副本真实跑一次默认初始化，不能只测试完成态骨架。

### 生产 manifest 不等于需要第二把 Composer lock
- **日期**：2026-08-09
- **症状**：骨架新增并跟踪 `composer.production.lock`，发布检查和部署脚本随后都把它当成必需输入，与实际生产部署方式漂移。
- **根因**：看到开发/生产两套 manifest 后，误推导成两套 lock；没有继续核对生产基准项目的 `pull.sh`、Git 跟踪状态和失败回滚流程。
- **解法**：保留 `composer.production.json`，但所有环境的 `composer.lock` 都只在本地生成并由 Git 忽略；生产 `pull.sh` 临时覆盖 `composer.json`，Composer 失败时恢复备份，成功后显式更新私包。发布规则、初始化器和教程不得再要求 `composer.production.lock`。

### 可入库 AI YAML 在公开骨架中只能保存空密钥
- **日期**：2026-08-09
- **症状**：Host 把 `scaffold/ai.yaml` 当成本地运行时文件忽略并在初始化时删除，与 moo-scaffold 的 YAML 配置契约不一致；照搬私有项目文件又会把明文密钥带进公开仓库。
- **根因**：混淆了“文件是否随项目同步”与“真实密钥是否可公开”两个边界。
- **解法**：不忽略或删除 `scaffold/ai.yaml`，公开骨架跟踪一份 `api_key` 为空的完整配置；真实密钥只允许进入明确受控的私有项目，禁止从生产 Host 复制配置值。

### GET 冒烟也可能写文件且成功退出不能代表无 5xx
- **日期**：2026-08-09
- **症状**：裸跑 `smoke:get-admin` 会反复覆盖同一个 baseline；授权导出 GET 还会在真实 public 盘留下 XLSX，端点出现 5xx 时命令仍返回 0。
- **根因**：工具把默认输出路径写死，直接使用真实 public disk，并且只把 5xx 打印为 warning、最终无条件返回 SUCCESS。
- **解法**：默认生成带微秒与随机后缀的唯一快照，只有显式 `--out` 才覆盖；执行前 `Storage::fake('public')` 并清空 fake 产物；500+（业务契约 522 除外）或捕获异常时保留报告并返回 FAILURE。

### 登录管理必须同时接通创建、刷新和失效三段
- **日期**：2026-08-09
- **症状**：后台刷新会派发 `UpdateLoginTokenJob`，但 `system_logins` 没有对应旧 token 记录，刷新次数不变；退出后登录管理仍显示有效。
- **根因**：Host 只接了刷新任务，登录时漏派发 `SaveLoginJob`，退出时也漏调 `LoginManagement::setInvalidStatus()`；更新任务找不到源记录只能静默返回。
- **解法**：登录保存 Personnel 的 WEB 端点并派发 `SaveLoginJob`，刷新继续派发 `UpdateLoginTokenJob`，退出先标记登录记录失效再永久拉黑 JWT；用一条功能测试连续断言 token MD5、刷新次数和状态。

### 事务内 dispatch 是否安全取决于队列 after_commit
- **日期**：2026-08-09
- **症状**：代码把 Job 派发写在数据库事务中，但事务回滚后任务仍可能已经进入队列，worker 随后读到不存在或未完成的数据。
- **根因**：database、beanstalkd、sqs、redis 连接的 `after_commit` 为 `false`；把 `dispatch()` 放进事务闭包本身不会延迟入队。
- **解法**：四个异步连接统一配置 `after_commit=true` 并用配置回归测试守护；确实要求事务提交前执行的少数任务必须在调用点明确设计，不能依赖全局默认碰运气。

### Host 发布配置会遮蔽扩展包的新默认契约
- **日期**：2026-08-09
- **症状**：升级 moo-scaffold 后，Host 仍保留旧的 `route.enabled`、AI 环境变量组和 `accounts.stub_path`，同时雪花节点写在包不会读取的独立 `config/snowflake.php`；配置看似完整，实际不生效。
- **根因**：已发布到 Host 的 `config/scaffold.php` 不会随扩展包升级自动更新，旧键也不会报错；业务侧复制的 BaseFilter 等基础类同样会逐渐偏离包内完成态。
- **解法**：升级包时以当前包源码的 `config()` 消费点和生成器模板为准审查 Host 配置与基础类；删除无消费键，把雪花配置收回 `scaffold.snowflake`、AI 只保留可跟踪的 YAML 路径，并用回归测试钉住旧键为空、当前键可读。公开骨架中的 YAML 必须保持空密钥。

### 改写已执行的基础迁移不会升级存量数据库
- **日期**：2026-08-09
- **症状**：把基础迁移里的死信表从 `failed_jobs` 改为 `job_failed` 后，全新测试库正常，但已跑过该迁移的开发库执行 `queue:failed` 报 1146，提示 `job_failed` 不存在。
- **根因**：Laravel 不会重跑 migrations 表中已记录的迁移；改写旧迁移只改变新库安装结果，不会对存量库执行 rename。
- **解法**：保留基础迁移先创建 Laravel 原表，另加一条可回滚的 `Schema::rename()` 迁移，再同步 `queue.failed.table`、教程和部署检查表；分别用全新测试库、`migrate --pretend`、存量库 `migrate` 和 `queue:failed` 验证。

### withMiddleware 中的自建组不会自动同步给普通 Artisan 命令
- **日期**：2026-08-09
- **症状**：把中间件别名和组从 `AppServiceProvider` 迁到 `bootstrap/app.php::withMiddleware()` 后，HTTP 请求正常，但 `moo-system check` 在 Console 中可能找不到 `moo-system` 组。
- **根因**：Laravel 12 只在解析 HTTP Kernel 时把 `withMiddleware()` 配置同步到 Router，普通 Artisan 启动只解析 Console Kernel；某些 host 会因第三方 Provider 偶然解析 HTTP Kernel 而掩盖问题，不能依赖。
- **解法**：别名和组只在 `withMiddleware()` 定义一份；`AppServiceProvider::boot()` 在 Console 环境显式解析 HTTP Kernel，让 Artisan 与 HTTP 共用同一配置，并用真实 `php artisan moo-system check --fail-fast` 验证。

### 删除本地雪花 Trait 前必须检查全部模型引用
- **日期**：2026-08-09
- **症状**：删除 `App\Models\Traits\UsingSnowFlakePrimaryKey` 后，Food 接口和相关测试在加载模型时 Fatal，提示 Composer 无法包含该 Trait 文件。
- **根因**：`Notification` 已切到 moo-scaffold 的共享 Trait，但 `Food` 仍引用旧命名空间；只检查一个示例模型便删除旧文件，留下了源码断链。
- **解法**：删除旧 Trait 前全仓检索其命名空间，把所有模型统一切到 `Mooeen\Scaffold\Concerns\UsingSnowFlakePrimaryKey`；随后运行 Food 相关测试和全量测试确认模型可加载。
