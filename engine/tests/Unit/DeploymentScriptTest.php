<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentScriptTest extends TestCase
{
    public function test_cache_script_never_clears_business_cache_or_jwt_blacklist(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/cache.sh');

        self::assertIsString($script);
        self::assertStringNotContainsString('php artisan cache:clear', $script);
        self::assertStringNotContainsString('php artisan optimize:clear', $script);

        foreach (['clear-compiled', 'config:clear', 'event:clear', 'route:clear', 'view:clear'] as $command) {
            self::assertStringContainsString($command, $script);
        }

        self::assertStringContainsString('php artisan optimize', $script);
    }

    public function test_backup_keeps_ignored_table_schema_and_hides_password_from_process_args(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/backup.sh');

        self::assertIsString($script);
        self::assertStringContainsString('--ignore-table-data=', $script);
        self::assertStringNotContainsString('--ignore-table=', $script);
        self::assertStringContainsString('MYSQL_PWD="$DB_PASS"', $script);
        self::assertStringNotContainsString('-p$DB_PASS', $script);
    }

    public function test_backup_help_exits_without_running_mysqldump(): void
    {
        $script = dirname(__DIR__, 3) . '/backup.sh';
        exec('sh ' . escapeshellarg($script) . ' --help 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('不执行备份', implode("\n", $output));
        self::assertStringNotContainsString('已导出', implode("\n", $output));
    }

    public function test_project_initializer_clears_route_and_config_cache_without_database_cache_clear(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/tools/init-project.php');

        self::assertIsString($script);
        self::assertStringContainsString("['php', 'artisan', 'config:clear']", $script);
        self::assertStringContainsString("['php', 'artisan', 'route:clear']", $script);
        self::assertStringNotContainsString("['php', 'artisan', 'optimize:clear']", $script);
    }

    public function test_production_composer_clear_all_does_not_clear_business_cache(): void
    {
        $composer = file_get_contents(dirname(__DIR__, 2) . '/composer.production.json');

        self::assertIsString($composer);
        self::assertStringNotContainsString('optimize:clear', $composer);
        self::assertStringContainsString('config:clear', $composer);
        self::assertStringContainsString('route:clear', $composer);
        self::assertStringContainsString('@php artisan optimize', $composer);
    }
}
