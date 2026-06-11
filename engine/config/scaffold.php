<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2026-05-30 16:24
 * @Description: Laravel Scaffold Config，配置中所有的路由，都是相对于 base_path() （必须在base_path()路径下）
 */

return [
    /**
     * 当前编码作者信息
     */
    'author' => env('SCAFFOLD_AUTHOR', ''),

    /**
     * 是否只在本地开发环境使用命令行
     */
    'only_in_local' => true,

    /**
     * 是否使用雪花 ID 主键算法
     */
    'snow_flake_id' => true,

    /**
     * 后台，授权验证
     */
    'authorization' => [
        // 是否开启 ACL 鉴权（Gate 'acl_authentication' 由 App\Providers\AuthServiceProvider 定义，
        // 角色授权流程见 docs 第 6 章；关掉则所有 checkAuthorization() 直接放行）
        'check' => true,
        // 是否通过 md5 加密别名 key
        'md5' => true,
        // 排除制定的动作，生成 api 时，header 中不包含 Authorization 参数
        'exclude_actions' => [
            'App\Admin\Controllers\AuthController@login',
            'App\Admin\Controllers\AuthController@authenticate',
        ],
    ],

    /**
     *  多语言设定
     */
    'languages' => ['en', 'zh-CN'],

    /**
     * 数据库 schema 文件路径
     */
    'database' => [
        'schema' => 'scaffold/database/',
    ],

    /**
     * API 接口配置文件的路径
     */
    'api' => [
        'schema' => 'scaffold/api/',
        'history' => 'scaffold/api/history/',
    ],

    /**
     * Eloquent ORM 的路径
     */
    'model' => [
        'path' => 'app/Models/',
    ],

    /**
     * Controller 配置
     */
    'controller' => [
        'admin' => [
            'name' => ['zh-CN' => '后台管理', 'en' => 'Admin'],
            'api_name' => '后台管理',  // 接口调试工具中的显示名称
            'path' => 'app/Admin/Controllers/',
            'requests' => ['index', 'store', 'update', 'destroyBatch', 'create', 'edit'], // 默认的 action 对应的 request 定义
            'request_path' => 'app/Admin/Requests/',
            'resource_path' => 'app/Admin/Resources/',
            'stub' => 'controller-admin',
            'trait_stub' => 'controller-admin-base-action-trait',
            'route' => 'routes/admin.php',
            // 包提供的额外 admin 模块：模块名 => 控制器命名空间（默认空）。这些控制器不在 host 的
            // controller.admin.path 下、生产环境又位于 vendor/，列在此处后即纳入 ACL（moo:auth）/
            // API 文档（moo:api）/ 路由调试 / 接口调试，并按此命名空间解析 FQCN。
            // 例：['System' => 'Mooeen\\System\\Http\\Controllers\\Admin']
            'extra_modules' => [
                // moo-system 包的后台控制器（位于 vendor/，不在 host 的 controller.admin.path 下）
                // 登记进来后即纳入 ACL（moo:auth）/ API 文档（moo:api）/ 路由调试 / 接口调试。
                'System' => 'Mooeen\\System\\Http\\Controllers\\Admin',
            ],
        ],
        'api' => [
            'name' => ['zh-CN' => '接口', 'en' => 'Api'],
            'api_name' => '客户端接口',  // 接口调试工具中的显示名称
            'path' => 'app/Api/Controllers/',
            'requests' => ['index'], // 默认的 action 对应的 request
            'request_path' => 'app/Api/Requests/',
            'resource_path' => 'app/Api/Resources/',
            'stub' => 'controller-api',
            'trait_stub' => 'controller-api-base-action-trait',
            'route' => 'routes/api.php',
        ],
    ],

    /**
     * 全局 Resource 兜底路径
     * 当 controller.{app}.resource_path 未配置，且 schema 也没有指定 resource app 时使用
     */
    'resource' => [
        'path' => 'app/Http/Resources/',
    ],

    /**
     * 生成时 资源 及  对应的类，可以自定义、及修改类的位置
     * 建议：复制文件，自定义于业务系统中，减低业务系统与本工具耦合性
     */
    'class' => [
        'resources' => [
            'base' => 'Mooeen\Scaffold\Foundation\BaseResource',
            'collection' => 'Mooeen\Scaffold\Foundation\BaseResourceCollection',
            'form' => 'Mooeen\Scaffold\Foundation\FormWidgetCollection',
            'table_columns' => 'Mooeen\Scaffold\Foundation\TableColumnsCollection',
            'columns' => 'Mooeen\Scaffold\Foundation\ColumnsCollection',
        ],
        'actions' => 'Mooeen\Scaffold\Foundation\Actions',
        'controller' => 'Mooeen\Scaffold\Foundation\Controller',
        // 注：FormRequest 基类 / 校验规则类（NumericArray / Mobile / DatetimeArray）不在此配置。
        // 这些只在 codegen 时使用，固定用 scaffold 自带类，见 CreateControllerGenerator。
    ],

    /**
     * Scaffold 路由配置
     */
    'route' => [
        'enabled' => true,
        'prefix' => 'scaffold',
        // 默认不挂额外中间件组（[]）。需要 session 的认证路由已在 routes.php 内层 group 显式挂
        // StartSession / VerifyCsrfToken；外层默认成 ['web'] 会让 UI 路由吃全局 CSRF，曾踩 419
        // 「CSRF token mismatch」（见 routes.php 顶部注释）。要额外中间件用 SCAFFOLD_MIDDLEWARE env 注入。
        // 默认挂 web group（session/cookie/csrf），AccountController 等用 session()->flash() 需要
        'middleware' => env('SCAFFOLD_MIDDLEWARE') ? explode(',', env('SCAFFOLD_MIDDLEWARE')) : ['web'],
    ],

    /**
     * Scaffold 简单账号认证
     * 账号来源：scaffold/accounts.yaml（AccountStore）；
     * 首次部署若 yaml 不存在，用 `php artisan moo:account:add` 创建首个账号。
     */
    'auth' => [
        'enabled' => true,
        'cookie_name' => 'scaffold_auth',
        // 登录会话有效期（分钟）。默认 24h；安全敏感场景可调短，便利场景可调长。
        // cookie 现在用 AES-256 加密 + HMAC 签名，但仍建议尽量短以缩小被盗用窗口。
        'ttl_minutes' => (int) env('SCAFFOLD_AUTH_TTL_MINUTES', 60 * 24),
    ],

    /**
     * 接口调试的域名配置(宿主项目 publish 后覆盖到 config/scaffold.php 改成自己的)
     */
    'hosts' => [
        '开发环境' => 'http://127.0.0.1:8088',
        '正式环境' => 'https://example.com',
    ],

    /**
     * 接口调试代理配置
     * 故意不提供 verify_tls 开关:HTTPS 证书有问题就报错,不留绕过路径。
     * 也不 follow redirect:server 重定向(如 http → https)直接显示 301/302,逼你修 hosts 配置。
     */
    'proxy' => [
        'timeout' => (int) env('SCAFFOLD_PROXY_TIMEOUT', 30),
    ],

    /**
     * 异常分发(plan 51 ExceptionDispatcher)
     * host bootstrap/app.php 一行接入:
     *     $exceptions->reportable(fn (Throwable $e) =>
     *         app(\Mooeen\Scaffold\Support\ExceptionDispatcher::class)->dispatch($e)
     *     );
     * 过滤(dontReport)交给 host Laravel `$exceptions->dontReport([...])`,这里不重复一层。
     */
    'exception' => [
        'enabled' => (bool) env('SCAFFOLD_EXCEPTION_ENABLED', true),
        // `php -r` / `php artisan tinker` 等 CLI 实验产生的异常自动跳过(避免污染 runtimes)
        'cli_experiment_skip' => true,
        // 终端敲错命令(命令不存在 / 参数缺失 / 选项非法 等 Symfony Console 用法错)自动跳过 — 是输入错,不是应用 runtime 错误
        'console_input_skip' => true,
        'mail' => [
            // null = 仅 prod 发(`!app()->isLocal()`); true/false = 强制
            'enabled' => env('SCAFFOLD_EXCEPTION_MAIL_ENABLED'),
            'to' => env('SCAFFOLD_EXCEPTION_MAIL_TO'),
            // 想品牌化:写一个继承 \Mooeen\Scaffold\Mail\ExceptionNotice 的子类,FQCN 填这里
            'mailable' => null,
        ],
        'dingtalk' => [
            'enabled' => (bool) env('SCAFFOLD_EXCEPTION_DING_ENABLED', false),
            // 钉钉机器人 access_token(webhook URL `?access_token=` 后面那段)
            'token' => env('SCAFFOLD_EXCEPTION_DING_TOKEN'),
            // 加签 secret;空字符串 = 关键词模式不签
            'secret' => env('SCAFFOLD_EXCEPTION_DING_SECRET'),
        ],
    ],

    /**
     * Runtime 错误记录器（plan 17）
     * 把 reportable 异常落盘到 scaffold/runtimes/ 下，Web UI 在 /scaffold/runtimes
     */
    'runtime' => [
        'enabled' => env('SCAFFOLD_RUNTIME_ENABLED', true),
        // 存储路径（相对于 base_path()）；与 acl/api/database 同级
        'path' => env('SCAFFOLD_RUNTIME_PATH', 'scaffold/runtimes'),
        // open 桶最大条目数，超过时静默丢弃新条目，首页红色警告
        'max_open' => (int) env('SCAFFOLD_RUNTIME_MAX_OPEN', 500),
        // 同一异常每天最多写盘次数,达到后只计不写(冻结 yaml,避免高频复发反复刷 git);<=0 不限制
        'daily_cap' => (int) env('SCAFFOLD_RUNTIME_DAILY_CAP', 10),
        // 自动脱敏的字段名关键词
        'mask_keys' => ['password', 'pwd', 'token', 'secret', 'api_key', 'authorization'],
        // 单字段超过此长度截断
        'string_truncate' => 200,
        // trace 字段最大字节数
        'trace_max_bytes' => 65536,
        // 源码 ±N 行
        'snippet_lines' => 10,
        // 顶栏徽章 open 数缓存 TTL(秒)。写入时会自动失效,TTL 仅作为安全网。
        'cache_ttl' => (int) env('SCAFFOLD_RUNTIME_CACHE_TTL', 30),
    ],

    /**
     * 慢 SQL 监听器(plan 52)
     * ScaffoldProvider boot 注册 QueryExecuted 监听,超阈值的 SQL 同时:
     *   - 落盘 scaffold/sql-slows/{open,resolved,deleted}/<hash>.yaml(套 runtime 同结构,
     *     hash 按 normalized SQL + file:line 聚合,累加 count)
     *   - 钉钉机器人(复用 SCAFFOLD_EXCEPTION_DING_TOKEN/SECRET,跟异常通知同 token)
     * 邮件 channel 不实现 — 慢 SQL 高频会刷爆收件箱。
     */
    'sql_slow' => [
        'enabled' => (bool) env('SCAFFOLD_SQL_SLOW_ENABLED', false),
        // 阈值(毫秒);超过此值才记录 + 通知
        'threshold_ms' => (int) env('SCAFFOLD_SQL_SLOW_THRESHOLD_MS', 100),
        // 存储路径(相对 base_path())
        'path' => env('SCAFFOLD_SQL_SLOW_PATH', 'scaffold/sql-slows'),
        // request.url 的 query 参数脱敏键(子串、大小写不敏感)。跟 runtime.mask_keys 同默认,
        // 各项目可独立调 —— 慢 SQL 发生在带密钥的 URL 上时,避免 token/secret 明文落盘 + 上云。
        'mask_keys' => ['password', 'pwd', 'token', 'secret', 'api_key', 'authorization'],
        // open 桶最大条目数,超过时静默丢弃新条目
        'max_open' => (int) env('SCAFFOLD_SQL_SLOW_MAX_OPEN', 500),
        // 同一慢 SQL 每天最多写盘次数,达到后只计不写(冻结 yaml,热慢查询不再每分钟反复推云端);<=0 不限制
        'daily_cap' => (int) env('SCAFFOLD_SQL_SLOW_DAILY_CAP', 10),
        // 跳过的 SQL 模式(子串匹配,任一命中即跳过)。默认避开 scaffold 自身写入 + 操作日志
        'skip_patterns' => [
            'insert into `system_operation_logs`',
            'select * from `migrations`',
        ],
        // 钉钉:默认关。复用 exception channel 的 token/secret
        'dingtalk' => [
            'enabled' => (bool) env('SCAFFOLD_SQL_SLOW_DING_ENABLED', false),
        ],
        // 顶栏徽章 open 数缓存 TTL(秒)
        'cache_ttl' => (int) env('SCAFFOLD_SQL_SLOW_CACHE_TTL', 30),
    ],

    /**
     * 云端汇聚(moo-scaffold-cloud)
     * 把本地 runtime / 慢 SQL 的 yaml 记录批量推送到云端 SaaS,多项目集中查看。
     *   - 推送由 `moo:cloud:push` 命令驱动(不在请求链路里,云端故障不影响宿主 app);
     *     enabled + schedule 同时为真时,ScaffoldProvider 自动挂每分钟调度(需宿主跑 schedule:run)。
     *   - 云端按 (project, hash) upsert,推送天然幂等;命令按 meta.updated_at 游标增量,
     *     `--all` 可忽略游标全量重推。
     *   - token 在云端「接入 Token」页生成,需同时具备 runtimes + slow_queries 两个能力。
     */
    'cloud' => [
        'enabled' => (bool) env('SCAFFOLD_CLOUD_ENABLED', false),
        // 云端站点根地址,如 https://cloud.example.com(末尾斜杠会被忽略);intake 路径自动拼 /api/v1/...
        'base_url' => rtrim((string) env('SCAFFOLD_CLOUD_URL', ''), '/'),
        // 项目 token(POST body 字段 token),需具备 runtimes + slow_queries 能力
        'token' => (string) env('SCAFFOLD_CLOUD_TOKEN', ''),
        // 单次请求超时(秒)
        'timeout' => (int) env('SCAFFOLD_CLOUD_TIMEOUT', 5),
        // 每批最多推送条数(云端 intake 支持 {records:[...]} 批量)
        'batch' => (int) env('SCAFFOLD_CLOUD_BATCH', 100),
        // TLS 证书校验(自签名内网环境可关)
        'verify' => (bool) env('SCAFFOLD_CLOUD_VERIFY', true),
        // 分类型开关:某类记录不想上云可单独关
        'push' => [
            'runtimes' => (bool) env('SCAFFOLD_CLOUD_PUSH_RUNTIMES', true),
            'slow_sql' => (bool) env('SCAFFOLD_CLOUD_PUSH_SLOW_SQL', true),
        ],
        // 是否自动挂 Laravel scheduler(每分钟)。关掉则只能手动/自建 cron 跑 moo:cloud:push
        'schedule' => (bool) env('SCAFFOLD_CLOUD_SCHEDULE', true),
        // 「本地降级为临时缓冲」:推送成功后回收本地 yaml(N>0 时)。
        //   - resolved 桶:已随推送进云端、由云端管生命周期 → 全清;
        //   - open 桶:留作聚合锚点(累加 count / max_ms),仅清 last_seen 超过 N 天的;
        //   - deleted 桶:不在推送范围(云端从未收到)→ 一律不动,避免静默丢未上云数据;
        //   - N=0 → 完全不回收(一个字节都不动,本地与云端并存,适合过渡期)。
        // 注意:dormant(>N 天未现)的 open 被清后若再复发,会以 count=1 重新计 → 云端按新发生覆盖。
        'local_retention_days' => (int) env('SCAFFOLD_CLOUD_LOCAL_RETENTION_DAYS', 7),
    ],

    /**
     * 生成前端资源的配置(仅当你有独立前端 SPA 项目跟此 Laravel 后端 sibling 部署时才用)
     * 默认 `../admin/src/` 假设前端在 `<Laravel-root>/../admin/`。无前端项目可忽略全部 frontend.* key,
     * `moo:free -a` 跑前端生成步骤会自动 skip。
     */
    'frontend' => [
        'src' => '../admin/src/',
        'models' => '../admin/src/models/',
        'views' => '../admin/src/views/',
        'types' => '../admin/types/',
    ],

    /*
     * Plan 18：配置 Web 管理界面
     */
    'config_ui' => [
        'enabled' => (bool) env('SCAFFOLD_CONFIG_UI', true),
        // 强制只读总开关（即便 APP_ENV=local 也只读）
        'readonly' => (bool) env('SCAFFOLD_CONFIG_READONLY', false),
        // env 镜像页 / diff 里需要掩码的字段（substring 匹配）
        'sensitive_keys' => ['PASSWORD', 'SECRET', 'KEY', 'TOKEN'],
    ],

    /*
     * Plan 19：Designer AI 翻译（DeepSeek 等 OpenAI 兼容上游）。
     * 走 config() 而非 ScaffoldProvider 里 raw env()——config:cache 后 env() 返 null 会让 AI 失效，
     * 固化进 config 缓存才安全；也让这几项在 config UI 可见。SCAFFOLD_AI_* env 仍是来源（向后兼容）。
     */
    'ai' => [
        'base_url' => env('SCAFFOLD_AI_BASE_URL', 'https://api.deepseek.com/v1'),
        'api_key' => env('SCAFFOLD_AI_API_KEY', ''),
        'model' => env('SCAFFOLD_AI_MODEL', 'deepseek-chat'),
        'timeout' => (int) env('SCAFFOLD_AI_TIMEOUT', 10),
    ],

    /*
     * Plan 18：开发人员账号（独立 YAML 存储）
     */
    'accounts' => [
        // YAML 主文件，路径相对 base_path()；跟随 git 同步（团队共享 + 远程部署）
        'yaml_path' => env('SCAFFOLD_ACCOUNTS_YAML', 'scaffold/accounts.yaml'),
        // 包内 stub 模板（首次导入 / artisan 命令时复制到 yaml_path）
        'stub_path' => __DIR__.'/../stubs/accounts.example.yaml',
    ],

];
