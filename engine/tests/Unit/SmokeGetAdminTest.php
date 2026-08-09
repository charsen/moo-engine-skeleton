<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\SmokeGetAdmin;
use Illuminate\Console\Command;
use Tests\TestCase;

class SmokeGetAdminTest extends TestCase
{
    public function test_default_output_paths_are_unique_and_explicit_paths_remain_stable(): void
    {
        $command = new TestableSmokeGetAdmin;

        $first  = $command->resolveOutputPathForTest('');
        $second = $command->resolveOutputPathForTest('');

        self::assertNotSame($first, $second);
        self::assertStringStartsWith(storage_path('app/smoke/smoke-get-admin-'), $first);
        self::assertStringEndsWith('.json', $first);
        self::assertSame(storage_path('app/smoke/approved-baseline.json'), $command->resolveOutputPathForTest('app/smoke/approved-baseline.json'));
        self::assertSame('/tmp/approved-baseline.json', $command->resolveOutputPathForTest('/tmp/approved-baseline.json'));
    }

    public function test_server_errors_and_thrown_exceptions_fail_the_command(): void
    {
        $command = new TestableSmokeGetAdmin;

        self::assertSame(Command::SUCCESS, $command->exitCodeForTest([
            ['status' => 200],
            ['status' => 404],
            ['status' => 422],
            ['status' => 522],
        ]));
        self::assertSame(Command::FAILURE, $command->exitCodeForTest([['status' => 500]]));
        self::assertSame(Command::FAILURE, $command->exitCodeForTest([['status' => 599]]));
    }
}

class TestableSmokeGetAdmin extends SmokeGetAdmin
{
    public function resolveOutputPathForTest(string $outPath): string
    {
        return $this->resolveOutputPath($outPath);
    }

    /**
     * @param array<int, array{status: int}> $entries
     */
    public function exitCodeForTest(array $entries): int
    {
        return $this->exitCodeFor($entries);
    }
}
