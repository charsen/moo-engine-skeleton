#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Turn a moo-engine-skeleton clone into a project-owned backend.
 *
 * The command is intentionally runnable before vendor/ exists. It prepares the
 * environment and SQLite file first, then installs dependencies and executes
 * the Laravel-side initialization.
 */
$root   = realpath(__DIR__ . '/..');
$engine = $root . '/engine';

if ($root === false || ! is_file($engine . '/composer.json')) {
    fwrite(STDERR, "Cannot locate the moo-engine-skeleton repository root.\n");
    exit(1);
}

$options = getopt('', [
    'name:',
    'app-name::',
    'description::',
    'author::',
    'app-url::',
    'profile::',
    'database::',
    'scaffold-user::',
    'scaffold-password::',
    'root-password::',
    'track-scaffold-accounts',
    'keep-demo',
    'keep-tutorial',
    'with-frontend',
    'fresh-git',
    'no-commit',
    'force',
    'help',
]);

if (isset($options['help']) || ! isset($options['name'])) {
    echo <<<'HELP'
Usage:
  php tools/init-project.php --name=vendor/project [options]

Required:
  --name=vendor/project          Composer project name.

Options:
  --app-name="Project Name"      APP_NAME and project title (defaults to name suffix).
  --description="..."           One-line project description.
  --author="Developer Name"      Scaffold code author (defaults to Git user.name or scaffold user).
  --app-url=http://project.test  APP_URL and Scaffold development debug host.
  --profile=full|website         full keeps mobile auth; website keeps a public /api slice without User auth.
  --database=sqlite             Bootstrap database; currently only sqlite is automated.
  --scaffold-user=developer     Scaffold console account name.
  --scaffold-password=...       Scaffold console password; generated when omitted.
  --root-password=...           id=1 root password; generated when omitted.
  --track-scaffold-accounts     Track bcrypt account YAML in a controlled private repository.
  --keep-demo                   Keep the Food teaching module (default: remove it).
  --keep-tutorial               Keep docs/dev-notes/plans and tutorial handoff files.
  --with-frontend               Also run npm install && npm run build.
  --fresh-git                   Archive skeleton .git beside the project and start fresh history.
  --no-commit                   With --fresh-git, initialize but do not create the first commit.
  --force                       Reinitialize, replacing local SQLite data and scaffold accounts.
  --help                        Show this help.

Example:
  php tools/init-project.php --name=acme/orders --app-name="Orders" \
    --scaffold-user=developer --fresh-git
HELP, PHP_EOL;
    exit(isset($options['help']) ? 0 : 2);
}

$projectName = trim((string) $options['name']);
if (! preg_match('/^[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$/', $projectName)) {
    fail('Invalid --name. Expected a Composer name such as acme/orders.');
}

$nameSuffix       = substr($projectName, (int) strrpos($projectName, '/') + 1);
$appName          = trim((string) ($options['app-name'] ?? $nameSuffix));
$description      = trim((string) ($options['description'] ?? ($appName . ' backend.')));
$author           = trim((string) ($options['author'] ?? (gitAuthorName() ?: ($options['scaffold-user'] ?? 'developer'))));
$appUrl           = rtrim(trim((string) ($options['app-url'] ?? 'http://127.0.0.1:8088')), '/');
$profile          = strtolower(trim((string) ($options['profile'] ?? 'full')));
$database         = strtolower(trim((string) ($options['database'] ?? 'sqlite')));
$scaffoldUser     = trim((string) ($options['scaffold-user'] ?? 'developer'));
$scaffoldPassword = (string) ($options['scaffold-password'] ?? randomPassword());
$rootPassword     = (string) ($options['root-password'] ?? randomStrongPassword());
$trackAccounts    = isset($options['track-scaffold-accounts']);
$keepDemo         = isset($options['keep-demo']);
$keepTutorial     = isset($options['keep-tutorial']);
$withFrontend     = isset($options['with-frontend']);
$freshGit         = isset($options['fresh-git']);
$noCommit         = isset($options['no-commit']);
$force            = isset($options['force']);
$marker           = $root . '/.moo-project.json';

if ($appName === '') {
    fail('--app-name cannot be empty.');
}

if ($author === '') {
    fail('--author cannot be empty.');
}

if (! in_array($profile, ['full', 'website'], true)) {
    fail('--profile must be full or website.');
}

if (filter_var($appUrl, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($appUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
    fail('--app-url must be an absolute http(s) URL.');
}

if ($profile === 'website' && ($keepDemo || $keepTutorial)) {
    fail('--profile=website cannot be combined with --keep-demo or --keep-tutorial because those teaching assets require the mobile user guard.');
}

if ($database !== 'sqlite') {
    fail('Automated initialization currently supports --database=sqlite only. Switch .env to MySQL/MariaDB after bootstrap.');
}

if (is_file($marker) && ! $force) {
    fail('This project was already initialized. Pass --force only when you intentionally want to rerun it.');
}

if (PHP_VERSION_ID < 80200) {
    fail('PHP 8.2 or newer is required. Current: ' . PHP_VERSION);
}

if (! commandExists('composer')) {
    fail('Composer is required but was not found in PATH.');
}

if ($freshGit && ! commandExists('git')) {
    fail('Git is required when --fresh-git is used.');
}

if ($freshGit && ! $noCommit && ! gitIdentityAvailable()) {
    fail('Git author identity is missing. Configure global user.name/user.email or rerun with --no-commit.');
}

headline('1/8 Project identity');
replaceComposerIdentity($engine . '/composer.json', $projectName, $description);
replaceComposerIdentity($engine . '/composer.production.json', $projectName, $description);

replaceEnvValue($engine . '/.env.example', 'APP_NAME', dotenvValue($appName));
replaceEnvValue($engine . '/.env.example', 'APP_URL', $appUrl);
replaceEnvValue($engine . '/.env.example', 'SCAFFOLD_AUTHOR', dotenvValue($author));
if (! is_file($engine . '/.env')) {
    if (! copy($engine . '/.env.example', $engine . '/.env')) {
        fail('Unable to create engine/.env from .env.example.');
    }
}
replaceEnvValue($engine . '/.env', 'APP_NAME', dotenvValue($appName));
replaceEnvValue($engine . '/.env', 'APP_URL', $appUrl);
replaceEnvValue($engine . '/.env', 'SCAFFOLD_AUTHOR', dotenvValue($author));
replaceEnvValue($engine . '/.env', 'DB_CONNECTION', 'sqlite');
commentEnvKeys($engine . '/.env', ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']);
replaceEnvValue($engine . '/.env', 'MOO_MONITOR_CLOUD_ENABLED', 'false');
replaceEnvValue($engine . '/.env', 'MOO_MONITOR_CLOUD_TOKEN', '');

$sqlite = $engine . '/database/database.sqlite';
if (is_file($sqlite) && filesize($sqlite) > 0 && ! $force) {
    fail('engine/database/database.sqlite already contains data. Move it away or pass --force.');
}
if ($force && is_file($sqlite)) {
    file_put_contents($sqlite, '');
} elseif (! is_file($sqlite) && ! touch($sqlite)) {
    fail('Unable to create the SQLite database file.');
}

if ($force) {
    removePath($engine . '/scaffold/accounts.yaml');
}

headline('2/8 Starter cleanup');
removeGeneratedRuntimeArtifacts($engine);
if (! $keepDemo) {
    removeFoodDemo($engine);
    line('Food teaching module removed.');
} else {
    line('Food teaching module retained (--keep-demo).');
}
if ($profile === 'website') {
    convertToWebsiteProfile($engine);
    line('Mobile User/JWT slice removed; public website API retained at /api.');
}
replaceScaffoldDevelopmentHost($engine . '/config/scaffold.php', $appUrl);

headline('3/8 Composer dependencies');
run(['composer', 'update', '--no-install', '--no-scripts', '--no-interaction'], $engine);
run(['composer', 'install', '--no-interaction', '--prefer-dist'], $engine);

headline('4/8 Application secrets and generated metadata');
run(['php', 'artisan', 'key:generate', '--force'], $engine);
run(['php', 'artisan', 'jwt:secret', '--force'], $engine);
run(['php', 'artisan', 'moo:init', $author], $engine);

if (! $keepDemo) {
    // Food routes are removed below; clear any route/config cache copied from the
    // skeleton before moo:auth reflects the live route table.  Do not use
    // optimize:clear here: a fresh SQLite bootstrap has no `cache` table yet,
    // while that umbrella command also clears the database-backed cache store.
    run(['php', 'artisan', 'config:clear'], $engine);
    run(['php', 'artisan', 'route:clear'], $engine);
    run(['php', 'artisan', 'moo:auth', 'admin'], $engine);
    if ($profile === 'full') {
        run(['php', 'artisan', 'moo:auth', 'api'], $engine);
    } else {
        clearApiActions($engine . '/config/actions.php');
    }
    restorePersonalCentreWhitelist($engine . '/config/actions.php');
    run([
        $engine . '/vendor/bin/pint',
        'config/actions.php',
        'lang/en/actions.php',
        'lang/en/db.php',
        'lang/en/model.php',
        'lang/en/validation.php',
        'lang/zh-CN/actions.php',
        'lang/zh-CN/db.php',
        'lang/zh-CN/model.php',
        'lang/zh-CN/validation.php',
    ], $engine);
}

headline('5/8 Database, scaffold console and assets');
run(['php', 'artisan', 'migrate', '--seed', '--force'], $engine);
run(['php', 'artisan', 'moo:fresh'], $engine);
run([
    'php', 'artisan', 'vendor:publish',
    '--provider=Mooeen\\Scaffold\\ScaffoldProvider',
    '--tag=public', '--force',
], $engine);
run([
    'php', 'artisan', 'moo:account:add', $scaffoldUser,
    '--password=' . $scaffoldPassword,
    '--role=admin',
], $engine, ['--password=']);
run([
    'php', 'artisan', 'moo-system', 'reset-root-password',
    '--password=' . $rootPassword,
    '--force',
], $engine, ['--password=']);

if ($trackAccounts) {
    removeIgnoreLine($engine . '/.gitignore', '/scaffold/accounts.yaml');
    line('scaffold/accounts.yaml will be tracked (--track-scaffold-accounts).');
}

if ($withFrontend) {
    if (! commandExists('npm')) {
        fail('npm was requested with --with-frontend but was not found in PATH.');
    }
    run(['npm', 'install'], $engine);
    run(['npm', 'run', 'build'], $engine);
}

headline('6/8 Project-owned documentation');
if (! $keepTutorial) {
    removeExplicitPaths($root, [
        'docs',
        'dev-notes',
        'plans',
        'HANDOFF.md',
        'overview.md',
        'tools/tutorial-http.sh',
        'tools/tutorial-sync-chapter7.php',
    ]);
    removeMarkedSection(
        $engine . '/tests/Unit/DeploymentScriptTest.php',
        '// init-project:start tutorial-helper-tests',
        '// init-project:end tutorial-helper-tests',
    );
}
writeProjectReadme($root . '/README.md', $appName, $description, $scaffoldUser, $appUrl, $profile, $trackAccounts);
writeEngineReadme($engine . '/README.md', $appName, $description);
writeClaudeGuide($root . '/CLAUDE.md', $appName, $description, $profile, $trackAccounts);
writeNotes($root . '/notes.md', $appName, $projectName, $keepDemo, $profile);

file_put_contents($marker, json_encode([
    'project'          => $projectName,
    'app_name'         => $appName,
    'initialized_at'   => date(DATE_ATOM),
    'database'         => $database,
    'profile'          => $profile,
    'app_url'          => $appUrl,
    'author'           => $author,
    'food_demo_kept'   => $keepDemo,
    'tutorial_kept'    => $keepTutorial,
    'scaffold_user'    => $scaffoldUser,
    'accounts_tracked' => $trackAccounts,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

headline('7/8 Verification');
run(['php', 'artisan', 'moo-system', 'check'], $engine);
run(['php', 'artisan', 'app:check-infrastructure-tables'], $engine);
run(['php', 'artisan', 'test'], $engine);
run(['php', 'artisan', 'migrate:status'], $engine);
run(['php', 'artisan', 'route:list', '--except-vendor'], $engine);
run(['composer', 'validate', '--no-check-publish', 'composer.json'], $engine);
run(['composer', 'audit', '--locked'], $engine);
run(['env', 'COMPOSER=composer.production.json', 'composer', 'validate', '--no-check-publish'], $engine);
run([$engine . '/vendor/bin/pint', '--test'], $engine);

headline('8/8 Git ownership');
$gitBackup = null;
if ($freshGit) {
    $gitDirectory = $root . '/.git';
    if (is_dir($gitDirectory)) {
        $gitBackup = dirname($root) . '/.' . basename($root) . '-skeleton-git-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        if (! rename($gitDirectory, $gitBackup)) {
            fail('Unable to archive the skeleton .git directory.');
        }
        line('Skeleton Git metadata archived at: ' . $gitBackup);
    }
    run(['git', 'init', '-b', 'master'], $root);
    run(['git', 'add', '-A'], $root);
    if (! $noCommit) {
        run(['git', 'commit', '-m', 'chore: initialize ' . $projectName . ' from moo-engine-skeleton'], $root);
    }
} else {
    line('Existing Git history retained. Use --fresh-git for a project-owned first commit.');
}

echo PHP_EOL;
echo "Project initialized successfully.\n";
echo "Project: {$projectName}\n";
echo "Scaffold: {$scaffoldUser} / {$scaffoldPassword}\n";
echo "Root: root / {$rootPassword}\n";
echo "Application URL: {$appUrl}\n";
echo "Backend: cd engine && PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload\n";
if ($gitBackup !== null) {
    echo "Recoverable skeleton Git backup: {$gitBackup}\n";
}

function headline(string $message): void
{
    echo PHP_EOL . "== {$message} ==\n";
}

function line(string $message): void
{
    echo " - {$message}\n";
}

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

/**
 * @param list<string> $command
 * @param list<string> $redactPrefixes
 */
function run(array $command, string $cwd, array $redactPrefixes = []): void
{
    $display = array_map(static function (string $part) use ($redactPrefixes): string {
        foreach ($redactPrefixes as $prefix) {
            if (str_starts_with($part, $prefix)) {
                return shellWord($prefix . '[REDACTED]');
            }
        }

        return shellWord($part);
    }, $command);
    line(implode(' ', $display));
    $shellCommand = 'cd ' . escapeshellarg($cwd) . ' && ' . implode(' ', array_map('escapeshellarg', $command));
    passthru($shellCommand, $exitCode);
    if ($exitCode !== 0) {
        fail('Command failed with exit code ' . $exitCode . ': ' . implode(' ', $command));
    }
}

function shellWord(string $value): string
{
    return preg_match('/^[A-Za-z0-9_\.\/:=@+-]+$/', $value) ? $value : escapeshellarg($value);
}

function commandExists(string $command): bool
{
    $result = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');

    return is_string($result) && trim($result) !== '';
}

function gitIdentityAvailable(): bool
{
    if (getenv('GIT_AUTHOR_NAME') && getenv('GIT_AUTHOR_EMAIL')) {
        return true;
    }

    $name  = shell_exec('git config --global --get user.name 2>/dev/null');
    $email = shell_exec('git config --global --get user.email 2>/dev/null');

    return is_string($name) && trim($name) !== '' && is_string($email) && trim($email) !== '';
}

function gitAuthorName(): string
{
    $name = shell_exec('git config --get user.name 2>/dev/null');

    return is_string($name) ? trim($name) : '';
}

function randomPassword(): string
{
    return rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'AZ'), '=');
}

function randomStrongPassword(): string
{
    return 'Moo-' . bin2hex(random_bytes(10)) . '-A9';
}

function dotenvValue(string $value): string
{
    if (preg_match('/^[A-Za-z0-9_.-]+$/', $value)) {
        return $value;
    }

    return '"' . addcslashes($value, '\\"') . '"';
}

function replaceComposerIdentity(string $path, string $name, string $description): void
{
    $content = readFileOrFail($path);
    $content = preg_replace('/"name"\s*:\s*"[^"]*"/', '"name": "' . addcslashes($name, '\\"') . '"', $content, 1, $nameCount);
    $content = preg_replace('/"description"\s*:\s*"[^"]*"/', '"description": "' . addcslashes($description, '\\"') . '"', $content, 1, $descriptionCount);
    if ($nameCount !== 1 || $descriptionCount !== 1) {
        fail('Unable to update Composer identity in ' . $path);
    }
    writeFileOrFail($path, $content);
}

function replaceEnvValue(string $path, string $key, string $value): void
{
    $content = readFileOrFail($path);
    $line    = $key . '=' . $value;
    $pattern = '/^#?\s*' . preg_quote($key, '/') . '=.*$/m';
    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, $line, $content, 1);
    } else {
        $content = rtrim($content) . PHP_EOL . $line . PHP_EOL;
    }
    writeFileOrFail($path, $content);
}

/** @param list<string> $keys */
function commentEnvKeys(string $path, array $keys): void
{
    $content = readFileOrFail($path);
    foreach ($keys as $key) {
        $content = preg_replace(
            '/^\s*#?\s*(' . preg_quote($key, '/') . '=.*)$/m',
            '# $1',
            $content,
            1,
        );
    }
    writeFileOrFail($path, $content);
}

function removeGeneratedRuntimeArtifacts(string $engine): void
{
    removeExplicitPaths($engine, [
        'scaffold/api/history',
        'scaffold/runtimes',
        'storage/moo-monitor/runtimes',
        'storage/moo-monitor/sql-slows',
    ]);
}

function removeFoodDemo(string $engine): void
{
    removeExplicitPaths($engine, [
        'app/Models/Food',
        'app/Admin/Controllers/Food',
        'app/Admin/Requests/Food',
        'app/Admin/Resources/Food',
        'app/Api/Controllers/Food',
        'app/Api/Requests/Food',
        'tests/Feature/ApiFoodTest.php',
        'tests/Feature/FoodAclTest.php',
        'tests/Feature/FoodIncrementalTest.php',
        'scaffold/database/Food.yaml',
        'scaffold/database/.snapshots/Food.yaml',
        'scaffold/api/admin/Food',
        'scaffold/api/api/Food',
        'storage/scaffold/foods.php',
    ]);

    foreach (glob($engine . '/database/migrations/*_foods_table.php') ?: [] as $migration) {
        removePath($migration);
    }

    replaceExact($engine . '/routes/admin.php', [
        "use App\\Admin\\Controllers\\Food\\FoodController;\n"                                                                                                                  => '',
        "    // FoodController\n    Route::iResource('food', FoodController::class);\n\n    Route::post('food/{id}/toggle-status', [FoodController::class, 'toggleStatus']);\n" => '',
    ]);
    replaceExact($engine . '/routes/api.php', [
        "use App\\Api\\Controllers\\Food\\FoodController;\n"                                                                                                     => '',
        "    // FoodController（只读：控制器只有 index/show，iResource 按方法注册，写路由自然不存在）\n    Route::iResource('food', FoodController::class);\n\n" => '',
    ]);

    $regressionPath = $engine . '/tests/Feature/RegressionTest.php';
    $regression     = readFileOrFail($regressionPath);
    foreach ([
        'test_phantom_destroy_route_is_not_registered',
        'test_food_price_filter_is_alive',
        'test_page_limit_is_capped',
    ] as $method) {
        $regression = removeVoidTestMethod($regression, $method);
    }
    $regression = str_replace(
        " * ④ Food IndexRequest 与 FoodFilter 字段对齐（price 等筛选不再是死代码）+ page_limit 上限。\n",
        '',
        $regression,
    );
    writeFileOrFail($regressionPath, $regression);

    replaceExact($engine . '/tests/Feature/RouteMacroTest.php', [
        '`DELETE food/batch`' => '`DELETE resources/batch`',
    ]);
    replaceExact($engine . '/bootstrap/app.php', [
        '（放行公开登录路由 + 演示 food 接口）' => '（放行公开登录路由）',
    ]);
    replaceExact($engine . '/app/Http/Middleware/SetLocale.php', [
        'Food 的 enum label、校验消息、moo-system 的多语言字段' => '业务枚举标签、校验消息、moo-system 的多语言字段',
    ]);

    writeFileOrFail($engine . '/scaffold/database/_fields.yaml', <<<'YAML'
###
# 润色，手动修改翻译（生成时不会被替换）
#
# append_fields: 为手工添加字段，一直保存
# table_fields: 数据库里的字段，会自动做增量、减量
# duplicate_fields: 数据库里重复出现的，有可能是重名了
##
table_fields:
    id: { en: 'Id', 'zh-CN': '编号' }
    deleted_at: { en: 'Deleted At', 'zh-CN': '删除于' }
    created_at: { en: 'Created At', 'zh-CN': '创建于' }
    updated_at: { en: 'Updated At', 'zh-CN': '更新于' }
YAML . PHP_EOL);

    writeFileOrFail($engine . '/scaffold/api/admin/_menus_transform.yaml', <<<'YAML'
###
# 转换 api 调试工具菜单
#
# 目录和控制器的顺序决定了显示排序
##
'Index':
    name: '根目录'
    controllers: [Auth]
'System':
    name: '系统管理'
    controllers: [Department, NotifyRobot, Personnel, Position, Role, OperationLog, LoginManagement, PersonnelOperationLog, Authorization, Admin]
YAML . PHP_EOL);

    writeFileOrFail($engine . '/scaffold/api/api/_menus_transform.yaml', <<<'YAML'
###
# 转换 api 调试工具菜单；生成首个业务模块后在此补目录顺序。
##
YAML . PHP_EOL);

    foreach (['en', 'zh-CN'] as $locale) {
        writeFileOrFail($engine . "/lang/{$locale}/model.php", "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");
        stripArrayKeys($engine . "/lang/{$locale}/db.php", [
            'food_name', 'food_category', 'price', 'calories', 'food_status', 'description', 'stock',
        ]);
        stripArrayKeys($engine . "/lang/{$locale}/validation.php", [
            'food_name', 'food_category', 'price', 'calories', 'food_status', 'description', 'stock',
        ]);
    }
}

function convertToWebsiteProfile(string $engine): void
{
    removeExplicitPaths($engine, [
        'app/Api/Controllers/AuthController.php',
        'app/Models/User.php',
        'database/factories/UserFactory.php',
        'database/seeders/UserSeeder.php',
        'tests/Feature/ApiAuthTest.php',
        'scaffold/acl/api.yaml',
    ]);
    foreach (array_merge(
        glob($engine . '/database/migrations/*_create_users_table.php') ?: [],
        glob($engine . '/database/migrations/*_add_actions_to_users_table.php') ?: [],
    ) as $migration) {
        if (str_ends_with($migration, '_create_users_table.php')) {
            convertUserMigrationToInfrastructureMigration($migration);
        } else {
            removePath($migration);
        }
    }

    replaceExact($engine . '/bootstrap/app.php', [
        "            // 客户端（移动端）接口：前缀 app，中间件组 client\n            Route::middleware('client')->prefix('app')->name('app.')->group(base_path('routes/api.php'));"                                                                         => "            // 官网公开接口：不绑定登录主体，业务端点按需增加认证或更严格限流\n            Route::middleware('site-api')->prefix('api')->name('api.')->group(base_path('routes/api.php'));",
        '（放行公开登录路由）'                                                                                                                                                                                                                              => '（放行公开登录路由）',
        "        // host 客户端（移动端）组：指定 user 守卫\n        \$middleware->group('client', [\n            'jwt.assign.guard:user',\n            'throttle:client',\n            'set.locale',\n            SubstituteBindings::class,\n        ]);" => "        // 官网公开接口组：无移动端 user 守卫，默认只做限流、本地化和路由绑定\n        \$middleware->group('site-api', [\n            'throttle:site-api',\n            'set.locale',\n            SubstituteBindings::class,\n        ]);",
        "return \$request->is('api/*') || \$request->is('app/*') || \$request->expectsJson();"                                                                                                                                                              => "return \$request->is('api/*') || \$request->expectsJson();",
    ]);

    writeFileOrFail($engine . '/routes/api.php', <<<'PHP'
<?php

declare(strict_types=1);
/*
 * 官网公开接口，统一挂载在 /api 下。
 *
 * 此处不提供移动端账户或 JWT 登录；查询接口默认公开，写接口应在调用点增加校验、
 * 来源策略、垃圾信息防护和更严格的限流。
 */

// :insert_code_here:do_not_delete
PHP . PHP_EOL);

    writeFileOrFail($engine . '/config/auth.php', <<<'PHP'
<?php

declare(strict_types=1);

use Mooeen\System\Models\Personnel;

return [
    // 官网前台没有账户体系；唯一登录主体是后台 Personnel。
    'defaults' => [
        'guard'     => env('AUTH_GUARD', 'admin'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'personnels'),
    ],

    'guards' => [
        'admin' => [
            'driver'   => 'jwt',
            'provider' => 'personnels',
        ],
    ],

    'providers' => [
        'personnels' => [
            'driver' => 'eloquent',
            'model'  => Personnel::class,
        ],
    ],

    // 保留 Laravel 基础设施契约；官网不提供公开的“忘记密码”接口。
    'passwords' => [
        'personnels' => [
            'provider' => 'personnels',
            'table'    => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
PHP . PHP_EOL);

    replaceExact($engine . '/database/seeders/DatabaseSeeder.php', [
        '顺序：自建用户（第 3 章 JWT 主体）→ 角色 → 部门 → 岗位 → 人员（人员要引用前三者）。' => '顺序：角色 → 部门 → 岗位 → 人员（人员要引用前三者）。',
        "            UserSeeder::class,\n"                                                    => '',
    ]);

    replaceExact($engine . '/app/Providers/AppServiceProvider.php', [
        "        // 接口限流（生产实践值：登录在内的后台接口 300 次/分钟，移动端 1000 次/分钟；\n        // 已登录按用户 ID 计数，未登录按 IP）"                                    => "        // 后台接口按登录人员或 IP 限流；官网公开接口按 IP 限流。\n        // 写接口还应在具体路由上增加更严格的独立限流器。",
        "        RateLimiter::for('client', function (Request \$request) {\n            return Limit::perMinute(1000)->by(\$request->user()?->id ?: \$request->ip());\n        });" => "        RateLimiter::for('site-api', function (Request \$request) {\n            return Limit::perMinute(300)->by(\$request->ip());\n        });",
    ]);
    replaceExact($engine . '/app/Helpers/helpers.php', [
        '（admin 组设 admin 守卫、client 组设 user 守卫）。全站「当前是谁」的唯一真值口径，' => '（后台组设 admin 守卫）。全站「当前是谁」的唯一真值口径，',
    ]);
    replaceExact($engine . '/config/scaffold.php', [
        "'api_name'      => '客户端接口'"                                     => "'api_name'      => '官网接口'",
        "            'App\\Api\\Controllers\\AuthController@login',\n"        => '',
        "            'App\\Api\\Controllers\\AuthController@authenticate',\n" => '',
    ]);

    $testCasePath = $engine . '/tests/TestCase.php';
    $testCase     = readFileOrFail($testCasePath);
    $testCase     = str_replace("use App\\Models\\User;\n", '', $testCase);
    $testCase     = removeProtectedMethod($testCase, 'appLogin');
    $testCase     = str_replace(
        "    protected function makeExpiredToken(string \$guard = 'admin', ?int \$iat_ago = null): string\n    {\n        // 守卫决定主体模型：admin → Personnel（moo-system）；user → 自建 User\n        \$user = \$guard === 'user'\n            ? User::where('email', 'admin@example.com')->firstOrFail()\n            : Personnel::where('mobile', '13800000000')->firstOrFail();",
        "    protected function makeExpiredToken(?int \$iat_ago = null): string\n    {\n        \$user = Personnel::where('mobile', '13800000000')->firstOrFail();",
        $testCase,
    );
    $testCase = str_replace(
        "'prv'   => sha1(\$guard === 'user' ? User::class : Personnel::class), // lock_subject 模型哈希\n            'guard' => \$guard,",
        "'prv'   => sha1(Personnel::class), // lock_subject 模型哈希\n            'guard' => 'admin',",
        $testCase,
    );
    writeFileOrFail($testCasePath, $testCase);

    replaceExact($engine . '/tests/Feature/JwtAutoRefreshTest.php', [
        "\$this->makeExpiredToken('admin', (int) config('jwt.refresh_ttl') * 60 + 7200)" => "\$this->makeExpiredToken((int) config('jwt.refresh_ttl') * 60 + 7200)",
    ]);

    $regressionPath = $engine . '/tests/Feature/RegressionTest.php';
    $regression     = readFileOrFail($regressionPath);
    foreach (['test_expired_user_token_cannot_refresh_on_admin_endpoint', 'test_expired_admin_token_cannot_refresh_on_app_endpoint'] as $method) {
        $regression = removeVoidTestMethod($regression, $method);
    }
    $regression = str_replace(
        "        self::assertSame([\n            'jwt.assign.guard:user',\n            'throttle:client',\n            'set.locale',\n            \\Illuminate\\Routing\\Middleware\\SubstituteBindings::class,\n        ], \$groups['client'] ?? null);",
        "        self::assertSame([\n            'throttle:site-api',\n            'set.locale',\n            \\Illuminate\\Routing\\Middleware\\SubstituteBindings::class,\n        ], \$groups['site-api'] ?? null);\n        self::assertArrayNotHasKey('client', \$groups);\n        self::assertArrayNotHasKey('user', config('auth.guards'));",
        $regression,
    );
    $regression = str_replace(
        "\$this->postJson('app/logout', [], ['Authorization' => 'Bearer not-a-jwt'])->assertOk();",
        "\$this->postJson('api/admin/logout', [], ['Authorization' => 'Bearer not-a-jwt'])->assertOk();",
        $regression,
    );
    writeFileOrFail($regressionPath, $regression);

    $seederTestPath = $engine . '/tests/Feature/SeederIntegrityTest.php';
    replaceExact($seederTestPath, [
        "use App\\Models\\User;\n"                                                                                                                => '',
        '/** 跑 DatabaseSeeder：User → 角色 → 部门 → 岗位 → 人员 */'                                                                              => '/** 跑 DatabaseSeeder：角色 → 部门 → 岗位 → 人员 */',
        'test_seeded_accounts_exist_for_both_guards'                                                                                              => 'test_seeded_admin_account_exists',
        "\n        // user 守卫主体：自建 User（email 登录）\n        \$this->assertNotNull(User::where('email', 'admin@example.com')->first());" => '',
    ]);
}

function convertUserMigrationToInfrastructureMigration(string $path): void
{
    $target = dirname($path) . '/' . str_replace('_create_users_table.php', '_create_auth_infrastructure_tables.php', basename($path));
    writeFileOrFail($target, <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
PHP . PHP_EOL);
    if ($target !== $path) {
        removePath($path);
    }
}

/** @param array<string,string> $replacements */
function replaceExact(string $path, array $replacements): void
{
    $content = readFileOrFail($path);
    foreach ($replacements as $search => $replacement) {
        $content = str_replace($search, $replacement, $content);
    }
    writeFileOrFail($path, $content);
}

function removeVoidTestMethod(string $content, string $method): string
{
    $pattern = '/\n    public function ' . preg_quote($method, '/') . '\(\): void\n    \{.*?\n    \}\n(?=\n    public function|\n\})/s';
    $updated = preg_replace($pattern, '', $content, 1, $count);
    if ($count !== 1) {
        if (! str_contains($content, 'function ' . $method . '(')) {
            return $content;
        }
        fail('Unable to remove demo test method: ' . $method);
    }

    return $updated;
}

function removeProtectedMethod(string $content, string $method): string
{
    $methodPos = strpos($content, '    protected function ' . $method . '(');
    if ($methodPos === false) {
        return $content;
    }

    $before = substr($content, 0, $methodPos);
    $start  = strrpos($before, "\n    /**");
    if ($start === false) {
        $start = strrpos($before, "\n    protected function");
    }
    $next = strpos($content, "\n    /**", $methodPos);
    if ($start === false || $next === false) {
        fail('Unable to remove protected method: ' . $method);
    }

    return substr($content, 0, $start) . substr($content, $next);
}

/** @param list<string> $keys */
function stripArrayKeys(string $path, array $keys): void
{
    $content = readFileOrFail($path);
    foreach ($keys as $key) {
        $content = preg_replace('/^\s*[\'\"]' . preg_quote($key, '/') . '[\'\"]\s*=>.*\R/m', '', $content);
    }
    writeFileOrFail($path, $content);
}

function restorePersonalCentreWhitelist(string $path): void
{
    $actions = require $path;
    if (! is_array($actions)) {
        fail('config/actions.php did not return an array.');
    }

    $required = [
        '84470713dcb9a7c9', 'f6d488cc41bea74a', 'b00ef1ce449c970b', 'cbc32275c4bdb06c',
        '88e610dbb210a3dc', '1fcbfd9524aebb83', 'd59a5622ff031201', 'e389e65e330e8af2',
    ];
    $current                       = $actions['admin']['whitelist'] ?? [];
    $actions['admin']['whitelist'] = array_values(array_unique(array_merge($current, $required)));

    $content = "<?php\n\ndeclare(strict_types=1);\n\n// Generated by moo:auth; personal-centre whitelist keys are restored by tools/init-project.php.\nreturn "
        . var_export($actions, true) . ";\n";
    writeFileOrFail($path, $content);
}

function clearApiActions(string $path): void
{
    $actions = require $path;
    if (! is_array($actions)) {
        fail('config/actions.php did not return an array.');
    }
    $actions['api'] = ['whitelist' => [], 'actions' => []];

    $content = "<?php\n\ndeclare(strict_types=1);\n\n// Generated by moo:auth; website profile keeps the public API ACL section empty.\nreturn "
        . var_export($actions, true) . ";\n";
    writeFileOrFail($path, $content);
}

function replaceScaffoldDevelopmentHost(string $path, string $appUrl): void
{
    $content = readFileOrFail($path);
    $updated = preg_replace_callback(
        "/'开发环境'\s*=>\s*'[^']*'/",
        static fn (): string => "'开发环境' => '" . addcslashes($appUrl, "\\'") . "'",
        $content,
        1,
        $count,
    );
    if (! is_string($updated) || $count !== 1) {
        fail('Unable to update Scaffold development host in ' . $path);
    }
    writeFileOrFail($path, $updated);
}

function removeIgnoreLine(string $path, string $line): void
{
    $content = readFileOrFail($path);
    $updated = preg_replace('/^' . preg_quote($line, '/') . '\R/m', '', $content, 1, $count);
    if (! is_string($updated)) {
        fail('Unable to update ignore file: ' . $path);
    }
    if ($count === 0 && str_contains($content, $line)) {
        fail('Unable to remove ignore rule: ' . $line);
    }
    writeFileOrFail($path, $updated);
}

/** @param list<string> $paths */
function removeExplicitPaths(string $base, array $paths): void
{
    foreach ($paths as $relative) {
        removePath($base . '/' . $relative);
    }
}

function removeMarkedSection(string $path, string $startMarker, string $endMarker): void
{
    $content  = readFileOrFail($path);
    $hasStart = str_contains($content, $startMarker);
    $hasEnd   = str_contains($content, $endMarker);

    // --force 重跑已初始化项目时，区段已经移除，保持幂等。
    if (! $hasStart && ! $hasEnd) {
        return;
    }
    if (! $hasStart || ! $hasEnd) {
        fail("Incomplete marked section in {$path}: {$startMarker} / {$endMarker}");
    }

    $pattern = '/\R[ \t]*' . preg_quote($startMarker, '/')
        . '\R.*?\R[ \t]*' . preg_quote($endMarker, '/') . '\R/s';
    $updated = preg_replace($pattern, '', $content, 1, $count);
    if (! is_string($updated) || $count !== 1) {
        fail("Unable to remove marked section in {$path}: {$startMarker} / {$endMarker}");
    }

    writeFileOrFail($path, $updated);
}

function removePath(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (! unlink($path)) {
            fail('Unable to remove file: ' . $path);
        }

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        fail('Unable to read directory: ' . $path);
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        removePath($path . '/' . $item);
    }
    if (! rmdir($path)) {
        fail('Unable to remove directory: ' . $path);
    }
}

function writeProjectReadme(
    string $path,
    string $appName,
    string $description,
    string $scaffoldUser,
    string $appUrl,
    string $profile,
    bool $trackAccounts,
): void {
    $api = $profile === 'website'
        ? 'Public website APIs are mounted at `/api`; there is no mobile User/JWT guard.'
        : 'Client APIs are mounted at `/app` and use the isolated User JWT guard.';
    $accounts = $trackAccounts
        ? 'The bcrypt-only `scaffold/accounts.yaml` is tracked because this repository was explicitly initialized as controlled/private.'
        : 'The scaffold account file is local-only and must be recreated after cloning.';
    $deployment = $profile === 'website'
        ? 'The bootstrap database is SQLite and the queue starts in sync mode. For production, either switch to MySQL/MariaDB and an asynchronous queue, or follow `SQLITE-DEPLOYMENT-TEMPLATE.md` for a constrained single-node SQLite deployment with external storage and tested backups.'
        : 'The bootstrap database is SQLite. Before production, switch `.env` to MySQL/MariaDB, assign a unique Snowflake worker identity, configure Redis and review `DEPLOY-CHECKLIST.md`.';
    $content = <<<MD
# {$appName}

{$description}

## Local development

```bash
cd engine
composer setup
php artisan moo:account:add {$scaffoldUser} --password=<choose-a-password> --role=admin
php artisan moo-system reset-root-password
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8088 --no-reload
```

Application URL: {$appUrl}. {$api}

{$deployment}

## moo-feedback example and package security

The public example uses `GET /api/feedbacks/meta` and `POST /api/feedbacks`; set
`MOO_FEEDBACK_PUBLIC=false` to disable that anonymous write surface. The admin routes
use the dedicated `moo-feedback` middleware group configured by
`config/moo-feedback.php` and `bootstrap/app.php`.

Keep every package on one `moo-<name>` stem: Composer package `charsen/moo-foo`,
host file `config/moo-foo.php`, config namespace `moo-foo.*`, publish tag
`moo-foo-config`, and middleware group `moo-foo`. Treat a rename as a public
contract change: update the package and host together, then run
`php artisan config:clear` before checking routes.

Every extension package with admin routes must have its own host middleware group
containing the complete authentication chain. Do not point package admin routes at
the login-capable `admin` group or borrow `moo-system`. Verify anonymous `401`,
authenticated-without-ACL `403`, and authorized success for each package.

## Quality gates

```bash
cd engine
php artisan moo-system check
php artisan test
composer audit --locked
./vendor/bin/pint --test
```

The scaffold development console is available at `/scaffold`. {$accounts}

This project was initialized from `moo-engine-skeleton`.
MD;
    writeFileOrFail($path, $content . PHP_EOL);
}

function writeEngineReadme(string $path, string $appName, string $description): void
{
    writeFileOrFail($path, "# {$appName} backend\n\n{$description}\n\nRun application and quality commands from this directory.\n");
}

function writeClaudeGuide(string $path, string $appName, string $description, string $profile, bool $trackAccounts): void
{
    $apiSlice = $profile === 'website'
        ? '- `app/Api/`: public website HTTP slice (`api`), without a mobile User guard'
        : '- `app/Api/`: client HTTP slice (`app`), with an isolated User guard';
    $accountRule = $trackAccounts
        ? 'This controlled private repository intentionally tracks bcrypt-only `scaffold/accounts.yaml`; never add plaintext passwords.'
        : 'Do not put generated scaffold accounts into Git; recreate them per environment.';
    $content = <<<MD
# CLAUDE.md

## Project

`{$appName}` is a Laravel 12 backend. {$description}

The Laravel application lives in `engine/`. Run Composer, Artisan, Pest/PHPUnit and Pint commands there.

## Architecture

- `app/Admin/`: admin HTTP slice (`api/admin`)
{$apiSlice}
- `app/Moo/`: host bindings and package integration contracts
- `scaffold/database/`: YAML schema truth source for generated business code
- `routes/admin.php` and `routes/api.php`: keep the scaffold insertion markers
- `composer.json`: local development package constraints
- `composer.production.json`: production package constraints consumed by deployment scripts
- Keep each extension package's config file, config namespace, publish tag and middleware group aligned on the same `moo-<name>` stem
- Every package admin surface uses a dedicated complete-auth middleware group; never reuse the login-capable `admin` group or another package's group

## Required verification

```bash
cd engine
php artisan moo-system check
php artisan test
./vendor/bin/pint --test
```

Do not put credentials, customer names, production domains or runtime dumps into Git. {$accountRule}
MD;
    writeFileOrFail($path, $content . PHP_EOL);
}

function writeNotes(string $path, string $appName, string $projectName, bool $keepDemo, string $profile): void
{
    $demo    = $keepDemo ? 'retained' : 'removed';
    $date    = date('Y-m-d');
    $content = <<<MD
# Project notes

## Initialized from moo-engine-skeleton

- Date: {$date}
- Project: {$projectName} ({$appName})
- API profile: {$profile}
- Food teaching module: {$demo}
- Bootstrap database: SQLite; production database and package pins must be reviewed before deployment
MD;
    writeFileOrFail($path, $content . PHP_EOL);
}

function readFileOrFail(string $path): string
{
    $content = @file_get_contents($path);
    if ($content === false) {
        fail('Unable to read file: ' . $path);
    }

    return $content;
}

function writeFileOrFail(string $path, string $content): void
{
    if (file_put_contents($path, $content) === false) {
        fail('Unable to write file: ' . $path);
    }
}
