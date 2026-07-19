<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * migrations 账本治理 —— 骨架标准件之一（账本治理）。
 *
 * ▍解决什么问题
 * 把一份历史生产库导进新仓库时，常见 `migrations` 表里记的是当年（如 2021/2023）的老文件名，
 * 而仓库 database/migrations/ 已经过重生成、换成了「建同一张表但文件名不同」的新文件。
 * 二者内容等价，但 `migrate:status` 按文件名逐字比对，于是把一堆早已运行过的迁移误报成
 * Pending（**假 Pending**）——你以为漏跑了，一 migrate 就重复建表报错。
 *
 * 本命令把库里的老文件名对齐成仓库的新文件名（batch 原样保留），假 Pending 归零。
 *
 * ▍典型时机
 * 导入历史库后、跑升级前的「阶段 0 前置加固」。骨架自身账本本就干净，dry-run 会直接
 * 报「无需改写」——那是正常的，说明没有漂移。
 *
 * ▍口径（骨架范式，三件套共享）
 *   dry-run（默认，只出对照表，不写库）
 *     → --execute（事务内 UPDATE + 落一份可还原 SQL manifest）
 *     → 幂等：对齐后重跑仍报「无需改写」，安全。
 *
 * ▍「同一迁移」怎么判定
 * 剥掉文件名前导时间戳（^\d{4}_\d{2}_\d{2}_\d{6}_），比较其余部分（stem，
 * 如 create_system_departments_table）。stem 相等 = 同一张表的建表迁移，只是时间戳漂了。
 */
class ReconcileMigrationsLedger extends Command
{
    protected $signature = 'app:reconcile-migrations-ledger
                            {--execute : 真正写库（默认仅 dry-run 出对照表）}';

    protected $description = '账本治理：把导入库里的老迁移文件名对齐为仓库现有新文件名（batch 保留，产出可还原 manifest，幂等）';

    public function handle(): int
    {
        $repoByStem = $this->repoMigrationsByStem();
        $dbRecords  = DB::table('migrations')->orderBy('id')->get();

        $aligned = [];   // 文件名已一致（含同名 Ran），无需处理
        $renames = [];   // 老名 → 新名，需 UPDATE
        $orphans = [];   // 库里有、仓库无同 stem 文件（历史独有 / 已消失）

        foreach ($dbRecords as $rec) {
            $stem = $this->stem($rec->migration);
            if (! isset($repoByStem[$stem])) {
                $orphans[] = ['id' => $rec->id, 'migration' => $rec->migration, 'batch' => $rec->batch];

                continue;
            }
            $new = $repoByStem[$stem];
            if ($new === $rec->migration) {
                $aligned[] = ['migration' => $rec->migration, 'batch' => $rec->batch];
            } else {
                $renames[] = ['id' => $rec->id, 'old' => $rec->migration, 'new' => $new, 'batch' => $rec->batch];
            }
        }

        // 仓库有、库里无对应 stem 记录 → 真实 Pending（真缺失，需 migrate 补跑）
        $dbStems = collect($dbRecords)->map(fn ($r) => $this->stem($r->migration))->all();
        $pending = [];
        foreach ($repoByStem as $stem => $file) {
            if (! in_array($stem, $dbStems, true)) {
                $pending[] = $file;
            }
        }

        $this->renderReport($aligned, $renames, $orphans, $pending);

        if (! $this->option('execute')) {
            $this->newLine();
            $this->warn('DRY-RUN：未写库。确认无误后追加 --execute 执行。');

            return self::SUCCESS;
        }

        if (empty($renames)) {
            $this->newLine();
            $this->info('无需改写：账本已对齐（幂等重跑安全）。');

            return self::SUCCESS;
        }

        $manifest = $this->writeManifest($dbRecords, $renames);
        $this->info("已落治理前 RESTORE manifest：{$manifest}");

        DB::transaction(function () use ($renames): void {
            foreach ($renames as $r) {
                DB::table('migrations')->where('id', $r['id'])->update(['migration' => $r['new']]);
            }
        });

        $this->newLine();
        $this->info(sprintf('已改写 %d 条记录（batch 保留）。可用 migrate:status 复核假 Pending 已归零。', count($renames)));

        return self::SUCCESS;
    }

    /**
     * 仓库 database/migrations/*.php → [stem => 新文件名(去 .php)]。
     *
     * @return array<string, string>
     */
    private function repoMigrationsByStem(): array
    {
        $out = [];
        foreach (File::files(database_path('migrations')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $name                    = $file->getFilenameWithoutExtension();
            $out[$this->stem($name)] = $name;
        }

        return $out;
    }

    /**
     * 剥掉前导时间戳，得到「迁移 stem」。
     */
    private function stem(string $migration): string
    {
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $migration) ?? $migration;
    }

    /**
     * 落治理前的整表 RESTORE SQL manifest（可还原：DELETE + 原样 INSERT）。
     */
    private function writeManifest($dbRecords, array $renames): string
    {
        $dir = storage_path('app/db');
        File::ensureDirectoryExists($dir);
        $path = $dir . '/migrations-ledger-restore-' . date('ymd-His') . '.sql';

        $lines   = [];
        $lines[] = '-- migrations 账本治理 · 治理前快照（可还原）';
        $lines[] = '-- 生成于 ' . now()->toDateTimeString();
        $lines[] = '-- 数据库: ' . DB::getDatabaseName();
        $lines[] = '-- 用法: mysql <db> < ' . basename($path) . '  （整表还原到治理前）';
        $lines[] = '-- 本次将改写的记录数: ' . count($renames);
        $lines[] = '';
        $lines[] = 'START TRANSACTION;';
        $lines[] = 'DELETE FROM `migrations`;';
        foreach ($dbRecords as $rec) {
            $lines[] = sprintf(
                'INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (%d, %s, %d);',
                $rec->id,
                $this->quote($rec->migration),
                $rec->batch,
            );
        }
        $lines[] = 'COMMIT;';
        $lines[] = '';

        File::put($path, implode("\n", $lines));

        return $path;
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function renderReport(array $aligned, array $renames, array $orphans, array $pending): void
    {
        $this->info('=== migrations 账本对照 ===');
        $this->line(sprintf(
            '已对齐(同名 Ran): %d   待改写(假 Pending→对齐): %d   孤儿记录: %d   真实缺失(需补跑): %d',
            count($aligned), count($renames), count($orphans), count($pending),
        ));

        if (! empty($renames)) {
            $this->newLine();
            $this->line('— 待改写映射（老文件名 → 新文件名，batch 保留）—');
            $this->table(
                ['id', 'batch', '老文件名 (库)', '新文件名 (仓库)'],
                array_map(fn ($r) => [$r['id'], $r['batch'], $r['old'], $r['new']], $renames),
            );
        }

        if (! empty($orphans)) {
            $this->newLine();
            $this->warn('— 孤儿记录（库有、仓库无同 stem，需人工判定）—');
            $this->table(['id', 'batch', 'migration'], array_map(fn ($o) => [$o['id'], $o['batch'], $o['migration']], $orphans));
        }

        if (! empty($pending)) {
            $this->newLine();
            $this->warn('— 真实缺失（仓库有、库无记录 → 真 Pending，需 migrate 补跑）—');
            foreach ($pending as $p) {
                $this->line('  • ' . $p);
            }
        }
    }
}
