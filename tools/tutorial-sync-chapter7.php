<?php

declare(strict_types=1);

/**
 * 把第 7 章需要的同版本参考文件同步进「从零跟做」项目。
 *
 * 默认 dry-run；传 --execute 才写入。被覆盖的旧文件会先备份到：
 *   <target>/.tutorial-backups/chapter7-YYYYmmdd-HHMMSS/
 */
$options = getopt('', ['target:', 'execute', 'help']);

if (isset($options['help'])) {
    echo <<<'HELP'
Usage:
  php tools/tutorial-sync-chapter7.php --target=/path/to/moo-engine-from-zero
  php tools/tutorial-sync-chapter7.php --target=/path/to/moo-engine-from-zero --execute

The target must be a project root containing engine/artisan and engine/composer.json.
Without --execute the command only prints the copy plan.
HELP;
    echo PHP_EOL;
    exit(0);
}

$targetInput = $options['target'] ?? null;
if (! is_string($targetInput) || trim($targetInput) === '') {
    fail('缺少 --target=<从零项目根目录>。先加 --help 查看示例。');
}

$sourceRoot = realpath(dirname(__DIR__));
$targetRoot = realpath($targetInput);
if ($sourceRoot === false) {
    fail('无法解析教程仓库根目录。');
}
if ($targetRoot === false) {
    fail("目标目录不存在：{$targetInput}");
}
if ($sourceRoot === $targetRoot) {
    fail('目标不能是教程参考仓库自身；请指向独立的从零跟做项目。');
}
if (! is_file($targetRoot.'/engine/artisan') || ! is_file($targetRoot.'/engine/composer.json')) {
    fail('目标不像 moo-engine 项目：必须包含 engine/artisan 与 engine/composer.json。');
}

$paths = [
    // 7.2 三个必需 host 契约 + 教程默认头像上传/Notification 媒体实现
    'engine/app/Admin/Controllers/UploadController.php',
    'engine/app/Admin/Controllers/Traits/BaseActionTrait.php',
    'engine/app/Support/TemporaryUploadPruner.php',
    'engine/app/Models/Traits/MediaSynchronous.php',
    'engine/app/Models/Notification.php',
    'engine/app/Notifications/SendBlessMessage.php',

    // 7.3 Personnel 登录控制器
    'engine/app/Admin/Controllers/AuthController.php',

    // 7.5 种子数据
    'engine/database/seeders/DatabaseSeeder.php',
    'engine/database/seeders/RoleSeeder.php',
    'engine/database/seeders/DepartmentSeeder.php',
    'engine/database/seeders/PositionSeeder.php',
    'engine/database/seeders/PersonnelSeeder.php',

    // 7.6 操作日志
    'engine/app/Http/Middleware/OperationLog.php',

    // 7.7 最终测试基建
    'engine/tests/TestCase.php',
    'engine/tests/Feature/AuthTest.php',
    'engine/tests/Feature/FoodAclTest.php',
    'engine/tests/Feature/MonitorTest.php',
    'engine/tests/Feature/JwtAutoRefreshTest.php',
    'engine/tests/Feature/SeederIntegrityTest.php',
    'engine/tests/Feature/RegressionTest.php',
    'engine/tests/Feature/UploadTest.php',

    // 7.6 起复用的 HTTP 诊断助手
    'tools/tutorial-http.sh',
];

$deprecatedPaths = [
    'engine/app/Admin/Controllers/Traits/UploaderTrait.php',
];

foreach ($paths as $relative) {
    if (! is_file($sourceRoot.'/'.$relative)) {
        fail("参考仓库缺少文件：{$relative}");
    }
}

$execute = isset($options['execute']);
$changes = [];
foreach ($paths as $relative) {
    $source = $sourceRoot.'/'.$relative;
    $target = $targetRoot.'/'.$relative;
    if (is_file($target) && hash_file('sha256', $source) === hash_file('sha256', $target)) {
        printf("  = unchanged  %s\n", $relative);

        continue;
    }

    $action = is_file($target) ? 'overwrite' : 'create';
    printf("  %s  %s\n", $action === 'overwrite' ? '~ overwrite' : '+ create   ', $relative);
    $changes[] = [$relative, $source, $target, $action];
}

foreach ($deprecatedPaths as $relative) {
    $target = $targetRoot.'/'.$relative;
    if (! is_file($target)) {
        continue;
    }

    printf("  - delete    %s\n", $relative);
    $changes[] = [$relative, null, $target, 'delete'];
}

if ($changes === []) {
    echo "✅ 第 7 章参考文件已经全部同步，无需改动。\n";
    exit(0);
}

if (! $execute) {
    printf("\nDRY-RUN：计划改动 %d 个文件；确认后重跑并加 --execute。\n", count($changes));
    exit(0);
}

$backupRoot = $targetRoot.'/.tutorial-backups/chapter7-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
$backedUp = 0;
$copied = 0;

foreach ($changes as [$relative, $source, $target, $action]) {
    if ($action === 'overwrite' || $action === 'delete') {
        $backup = $backupRoot.'/'.$relative;
        ensureDirectory(dirname($backup));
        if (! copy($target, $backup)) {
            fail("备份失败：{$relative}");
        }
        $backedUp++;
    }

    if ($action === 'delete') {
        if (! unlink($target)) {
            fail("删除弃用文件失败：{$relative}");
        }

        continue;
    }

    ensureDirectory(dirname($target));
    if (! copy($source, $target)) {
        fail("复制失败：{$relative}");
    }
    $copied++;
}

printf("\n✅ 已同步 %d 个文件。\n", $copied);
if ($backedUp > 0) {
    printf("↩ 已备份 %d 个旧文件到：%s\n", $backedUp, $backupRoot);
}
echo "下一步继续完成 config/auth.php、moo-system 配置、ACL 白名单与迁移。\n";

function ensureDirectory(string $directory): void
{
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        fail("无法创建目录：{$directory}");
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "❌ {$message}\n");
    exit(1);
}
