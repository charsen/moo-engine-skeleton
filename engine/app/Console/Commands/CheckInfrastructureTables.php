<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckInfrastructureTables extends Command
{
    protected $signature = 'app:check-infrastructure-tables';

    protected $description = '只读检查队列、缓存、会话和密码重置配置引用的数据库表是否存在';

    public function handle(): int
    {
        $rows      = [];
        $hasErrors = false;

        foreach ($this->checks() as $check) {
            $component  = $check['component'];
            $connection = $check['connection'];
            $table      = $check['table'];

            if ($table === '') {
                $rows[]    = [$component, $this->connectionLabel($connection), '(未配置)', 'ERROR', '缺少表名配置'];
                $hasErrors = true;

                continue;
            }

            try {
                $exists = DB::connection($connection)->getSchemaBuilder()->hasTable($table);
                $rows[] = [
                    $component,
                    $this->connectionLabel($connection),
                    $table,
                    $exists ? 'OK' : 'MISSING',
                    $exists ? '' : '数据表不存在',
                ];
                $hasErrors = $hasErrors || ! $exists;
            } catch (Throwable $e) {
                $rows[]    = [$component, $this->connectionLabel($connection), $table, 'ERROR', $e->getMessage()];
                $hasErrors = true;
            }
        }

        $this->table(['组件', '数据库连接', '数据表', '状态', '说明'], $rows);

        if ($hasErrors) {
            $this->error('基础设施表契约不完整；先检查配置，再运行 php artisan migrate。');

            return self::FAILURE;
        }

        $this->info('基础设施表契约检查通过。');

        return self::SUCCESS;
    }

    /**
     * @return list<array{component: string, connection: ?string, table: string}>
     */
    private function checks(): array
    {
        $checks = [
            [
                'component'  => '队列任务',
                'connection' => $this->nullableString(config('queue.connections.database.connection')),
                'table'      => $this->string(config('queue.connections.database.table')),
            ],
            [
                'component'  => '队列批次',
                'connection' => $this->nullableString(config('queue.batching.database')),
                'table'      => $this->string(config('queue.batching.table')),
            ],
            [
                'component'  => '数据库缓存',
                'connection' => $this->nullableString(config('cache.stores.database.connection')),
                'table'      => $this->string(config('cache.stores.database.table')),
            ],
            [
                'component'  => '数据库会话',
                'connection' => $this->nullableString(config('session.connection')),
                'table'      => $this->string(config('session.table')),
            ],
        ];

        if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            $checks[] = [
                'component'  => '失败队列',
                'connection' => $this->nullableString(config('queue.failed.database')),
                'table'      => $this->string(config('queue.failed.table')),
            ];
        }

        $broker   = $this->string(config('auth.defaults.passwords'));
        $checks[] = [
            'component'  => '密码重置',
            'connection' => null,
            'table'      => $broker === '' ? '' : $this->string(config("auth.passwords.{$broker}.table")),
        ];

        return $checks;
    }

    private function connectionLabel(?string $connection): string
    {
        return $connection ?? '(默认连接)';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value === '' ? null : $value;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
