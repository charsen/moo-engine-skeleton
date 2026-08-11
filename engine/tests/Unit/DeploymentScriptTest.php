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
        self::assertStringContainsString("['php', 'artisan', 'app:check-infrastructure-tables']", $script);
        self::assertStringContainsString("['php', 'artisan', 'moo:init', \$author]", $script);
        self::assertStringContainsString("['php', 'artisan', 'moo:fresh']", $script);
        self::assertStringContainsString("'reset-root-password'", $script);
        self::assertStringContainsString('convertToWebsiteProfile($engine)', $script);
        self::assertStringContainsString('convertUserMigrationToInfrastructureMigration($migration)', $script);
        self::assertStringContainsString("'passwords' => env('AUTH_PASSWORD_BROKER', 'personnels')", $script);
        self::assertStringContainsString("'throttle:site-api'", $script);
        self::assertStringContainsString("'track-scaffold-accounts'", $script);
        self::assertStringNotContainsString("'scaffold/ai.yaml'", $script);
        self::assertStringNotContainsString("['composer', 'update', '--lock'", $script);
        self::assertStringNotContainsString("'COMPOSER=composer.production.json', 'composer',\n    'update'", $script);
        self::assertStringNotContainsString("'COMPOSER=composer.production.json', 'composer', 'audit', '--locked'", $script);
        self::assertStringContainsString('init-project:start tutorial-helper-tests', $script);
        self::assertStringContainsString('removeMarkedSection(', $script);
        self::assertStringContainsString("'tools/tutorial-http.sh'", $script);
        self::assertStringContainsString("'tools/tutorial-sync-chapter7.php'", $script);
        self::assertStringContainsString('host file `config/moo-foo.php`', $script);
        self::assertStringContainsString('config namespace `moo-foo.*`', $script);
        self::assertStringContainsString('`moo-foo-config`, and middleware group `moo-foo`', $script);
        self::assertStringContainsString('`php artisan config:clear` before checking routes', $script);
    }

    public function test_release_and_pull_scripts_do_not_require_a_production_lock(): void
    {
        $release = file_get_contents(dirname(__DIR__, 3) . '/release-check.sh');
        $pull    = file_get_contents(dirname(__DIR__, 3) . '/pull.sh');

        self::assertIsString($release);
        self::assertIsString($pull);
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/composer.production.lock');
        self::assertStringNotContainsString('composer.production.lock', $release);
        self::assertStringNotContainsString('composer.production.lock', $pull);
        self::assertStringContainsString('cp "$COMPOSER_PROD" "$ENGINE_DIR/composer.json"', $pull);
        self::assertStringContainsString('rollback_composer_on_fail()', $pull);
    }

    public function test_pull_reads_target_manifest_after_checkout_and_surfaces_publish_failures(): void
    {
        $pull = file_get_contents(dirname(__DIR__, 3) . '/pull.sh');

        self::assertIsString($pull);
        $checkout = strpos($pull, 'success "🌐 主仓代码已更新"');
        $manifest = strpos($pull, 'PRIVATE_PKGS_MANIFEST=$(jq');
        self::assertIsInt($checkout);
        self::assertIsInt($manifest);
        self::assertGreaterThan($checkout, $manifest, '私包清单必须读取切换后的目标版本');
        self::assertStringNotContainsString('require_command ssh', $pull);
        self::assertStringContainsString('DEPLOY_BRANCH=${DEPLOY_BRANCH:-master}', $pull);
        self::assertStringContainsString('PUBLISH_FAILED=1', $pull);
        self::assertStringContainsString('${PUBLISH_FAILED:-0}', $pull);
    }

    public function test_ai_yaml_is_trackable_and_contains_no_public_secret(): void
    {
        $engine = dirname(__DIR__, 2);
        $ignore = file_get_contents($engine . '/.gitignore');
        $yaml   = file_get_contents($engine . '/scaffold/ai.yaml');

        self::assertIsString($ignore);
        self::assertIsString($yaml);
        self::assertStringNotContainsString('/scaffold/ai.yaml', $ignore);
        self::assertMatchesRegularExpression('/^\s*api_key:\s*[\'\"]{2}\s*$/m', $yaml);
    }

    public function test_admin_auth_debug_contract_is_present(): void
    {
        $engine = dirname(__DIR__, 2);
        $menu   = file_get_contents($engine . '/scaffold/api/admin/_menus_transform.yaml');
        $api    = file_get_contents($engine . '/scaffold/api/admin/Auth.yaml');

        self::assertFileExists($engine . '/app/Admin/Requests/Auth/AuthenticateRequest.php');
        self::assertIsString($menu);
        self::assertIsString($api);
        self::assertStringContainsString('controllers: [Auth]', $menu);
        foreach (['authenticate_post:', 'logout_post:', 'me_get:', 'refresh_post:'] as $action) {
            self::assertStringContainsString($action, $api);
        }

        $config = file_get_contents($engine . '/config/scaffold.php');
        self::assertIsString($config);
        self::assertStringContainsString('App\\Admin\\Controllers\\AuthController@authenticate', $config);
        if (is_file($engine . '/app/Api/Controllers/AuthController.php')) {
            self::assertStringContainsString('App\\Api\\Controllers\\AuthController@authenticate', $config);
        } else {
            self::assertStringNotContainsString('App\\Api\\Controllers\\AuthController@authenticate', $config);
        }
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

    // init-project:start tutorial-helper-tests
    public function test_tutorial_http_helper_loads_in_posix_shell(): void
    {
        $helper = dirname(__DIR__, 3) . '/tools/tutorial-http.sh';
        $script = '. ' . escapeshellarg($helper)
            . '; command -v tutorial_http_request >/dev/null'
            . ' && command -v tutorial_http_call >/dev/null'
            . ' && command -v tutorial_http_token >/dev/null';

        exec('sh -c ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_chapter_seven_sync_is_dry_run_by_default_and_backs_up_overwrites(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/tools/tutorial-sync-chapter7.php');

        self::assertIsString($script);
        self::assertStringContainsString("isset(\$options['execute'])", $script);
        self::assertStringContainsString('DRY-RUN', $script);
        self::assertStringContainsString('/.tutorial-backups/chapter7-', $script);
        self::assertStringContainsString("if (\$action === 'overwrite')", $script);
    }
    // init-project:end tutorial-helper-tests
}
