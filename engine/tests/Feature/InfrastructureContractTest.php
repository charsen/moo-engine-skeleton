<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfrastructureContractTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_configured_infrastructure_tables_exist(): void
    {
        $this->artisan('app:check-infrastructure-tables')
            ->expectsOutputToContain('基础设施表契约检查通过。')
            ->assertSuccessful();
    }

    public function test_missing_configured_table_fails_with_diagnostic_output(): void
    {
        config()->set('queue.failed.table', 'missing_failed_jobs');

        $this->artisan('app:check-infrastructure-tables')
            ->expectsOutputToContain('missing_failed_jobs')
            ->expectsOutputToContain('基础设施表契约不完整')
            ->assertFailed();
    }

    public function test_scaffold_host_config_only_exposes_current_package_contract(): void
    {
        self::assertSame('scaffold/ai.yaml', config('scaffold.ai.yaml_path'));
        self::assertNull(config('scaffold.ai.api_key'));
        self::assertNull(config('scaffold.ai.base_url'));
        self::assertNull(config('scaffold.route.enabled'));
        self::assertNull(config('scaffold.accounts.stub_path'));
        self::assertNull(config('snowflake'));
        self::assertIsInt(config('scaffold.snowflake.data_center_id'));
        self::assertIsInt(config('scaffold.snowflake.worker_id'));
        self::assertIsString(config('scaffold.snowflake.start_time'));
    }

    public function test_async_queue_connections_wait_until_database_commit(): void
    {
        foreach (['database', 'beanstalkd', 'sqs', 'redis'] as $connection) {
            self::assertTrue(
                config("queue.connections.{$connection}.after_commit"),
                "queue connection {$connection} 必须在数据库提交后才派发任务",
            );
        }
    }
}
