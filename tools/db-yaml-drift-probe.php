<?php

declare(strict_types=1);

/**
 * DB ↔ scaffold YAML 漂移探查（一次性）
 *
 * 读取 engine/scaffold/database/*.yaml，与 host 数据库
 * information_schema.columns 对账，输出 markdown 报告到
 * tools/db-yaml-drift.md。
 *
 * 不改 engine 代码，不依赖 Laravel runtime。仅复用 composer 的
 * symfony/yaml 与 PDO。run from repo root: php tools/db-yaml-drift-probe.php
 */

$repo = dirname(__DIR__);
require $repo . '/engine/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

// --- 1) 读 engine/.env 拿 DB 凭据 -----------------------------------------
$env = [];
foreach (preg_split('/\R/', file_get_contents($repo . '/engine/.env')) as $line) {
    if (! preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $m)) {
        continue;
    }
    $val = trim($m[2]);
    if (preg_match('/^"(.*)"$/', $val, $q)) {
        $val = $q[1];
    } elseif (preg_match("/^'(.*)'$/", $val, $q)) {
        $val = $q[1];
    }
    $val      = preg_replace('/\s+#.*$/', '', $val);
    $env[$m[1]] = $val;
}

$schema = $env['DB_DATABASE'];
$pdo    = new PDO(
    "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$schema};charset=utf8mb4",
    $env['DB_USERNAME'],
    $env['DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// --- 2) 解析全部 YAML -----------------------------------------------------

// YAML type → MySQL DATA_TYPE
$typeMap = [
    'char' => 'char', 'varchar' => 'varchar',
    'tinytext' => 'tinytext', 'tinyText' => 'tinytext',
    'text' => 'text',
    'mediumtext' => 'mediumtext', 'mediumText' => 'mediumtext',
    'longtext' => 'longtext', 'longText' => 'longtext',
    'int' => 'int', 'tinyint' => 'tinyint', 'bigint' => 'bigint',
    'date' => 'date',
    'datetime' => 'datetime', 'dateTime' => 'datetime',
    'timestamp' => 'timestamp', 'time' => 'time',
    'bool' => 'tinyint', 'boolean' => 'tinyint',
    'binary' => 'blob',
    'json' => 'json', 'jsonb' => 'json',
    'decimal' => 'decimal',
    'double' => 'double',
    'float' => 'float',
];

$shorthandDefaults = [
    'id'         => ['type' => 'bigint',    'required' => true],
    'deleted_at' => ['type' => 'timestamp', 'required' => false],
    'created_at' => ['type' => 'timestamp', 'required' => false],
    'updated_at' => ['type' => 'timestamp', 'required' => false],
];

$expected = []; // [table => [col => [yaml_type, type, nullable, size, yaml_file]]]
foreach (glob($repo . '/engine/scaffold/database/*.yaml') as $file) {
    $name = basename($file);
    if ($name === '_fields.yaml') {
        continue;
    }

    $data = Yaml::parseFile($file);
    if (! isset($data['tables'])) {
        continue;
    }

    foreach ($data['tables'] as $table => $def) {
        $fields = $def['fields'] ?? [];
        $cols   = [];

        foreach ($fields as $fname => $attr) {
            if (empty($attr)) {
                if (! isset($shorthandDefaults[$fname])) {
                    $cols[$fname] = ['type' => null, 'nullable' => null, 'size' => null,
                        'yaml_type' => '?', 'yaml_file' => $name, 'warn' => '未知简写'];
                    continue;
                }
                $attr = $shorthandDefaults[$fname];
            }

            $type     = $attr['type'] ?? null;
            $required = $attr['required'] ?? true;
            $nullable = ! $required;
            $size     = null;

            if (in_array($type, ['varchar', 'char'], true)) {
                $rawSize = $attr['size'] ?? 32;
                if (is_string($rawSize) && str_contains($rawSize, ',')) {
                    [, $rawSize] = explode(',', $rawSize);
                }
                $size = (int) $rawSize;
            }

            $cols[$fname] = [
                'yaml_type' => $type,
                'type'      => $typeMap[$type] ?? $type,
                'nullable'  => $nullable,
                'size'      => $size,
                'yaml_file' => $name,
            ];
        }

        $expected[$table] = $cols;
    }
}

// --- 3) 查 DB 实际列 ------------------------------------------------------

// (a) 所有列（限定到 YAML 涉及表）
$tableList    = array_keys($expected);
$placeholders = implode(',', array_fill(0, count($tableList), '?'));
$stmt         = $pdo->prepare("
    SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
    FROM information_schema.columns
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($placeholders)
    ORDER BY TABLE_NAME, ORDINAL_POSITION
");
$stmt->execute(array_merge([$schema], $tableList));

$actual = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $actual[$r['TABLE_NAME']][$r['COLUMN_NAME']] = [
        'type'     => strtolower($r['DATA_TYPE']),
        'nullable' => $r['IS_NULLABLE'] === 'YES',
        'size'     => $r['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int) $r['CHARACTER_MAXIMUM_LENGTH'] : null,
    ];
}

// (b) 整个 schema 的表清单（用于发现 DB-only 表）
$allTables = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = ?');
$allTables->execute([$schema]);
$allDbTables = array_column($allTables->fetchAll(PDO::FETCH_ASSOC), 'TABLE_NAME');

// --- 4) 对账 --------------------------------------------------------------

$missingTablesInDb   = [];
$missingTablesInYaml = array_values(array_diff($allDbTables, $tableList));
$drifts              = [];

foreach ($expected as $table => $cols) {
    if (! isset($actual[$table])) {
        $missingTablesInDb[] = $table;
        continue;
    }

    foreach ($cols as $col => $exp) {
        $act = $actual[$table][$col] ?? null;
        if ($act === null) {
            $drifts[] = ['table' => $table, 'col' => $col, 'sev' => 'high', 'kind' => 'missing_in_db',
                'detail' => sprintf('YAML 声明 %s (%s), DB 不存在', $exp['yaml_type'] ?? '?', $exp['yaml_file'])];
            continue;
        }

        if (! empty($exp['warn'])) {
            $drifts[] = ['table' => $table, 'col' => $col, 'sev' => 'high', 'kind' => 'unknown_shorthand',
                'detail' => sprintf('YAML 未知简写 `%s: {}` in %s', $col, $exp['yaml_file'])];
        }

        if ($exp['type'] !== null && $exp['type'] !== $act['type']) {
            $drifts[] = ['table' => $table, 'col' => $col, 'sev' => 'high', 'kind' => 'type_mismatch',
                'detail' => sprintf('YAML %s (→%s) vs DB %s', $exp['yaml_type'], $exp['type'], $act['type'])];
        }

        if ($exp['nullable'] !== null && $exp['nullable'] !== $act['nullable']) {
            $drifts[] = ['table' => $table, 'col' => $col, 'sev' => 'mid', 'kind' => 'nullable_mismatch',
                'detail' => sprintf('YAML nullable=%s vs DB nullable=%s',
                    $exp['nullable'] ? 'true' : 'false',
                    $act['nullable'] ? 'true' : 'false')];
        }

        if ($exp['size'] !== null && $act['size'] !== null && $exp['size'] !== $act['size']) {
            $drifts[] = ['table' => $table, 'col' => $col, 'sev' => 'low', 'kind' => 'size_mismatch',
                'detail' => sprintf('YAML size=%d vs DB size=%d', $exp['size'], $act['size'])];
        }
    }

    foreach ($actual[$table] as $col => $act) {
        if (! isset($cols[$col])) {
            $drifts[] = ['table' => $table, 'col' => $col, 'sev' => 'mid', 'kind' => 'missing_in_yaml',
                'detail' => sprintf('DB 有 %s, YAML 无声明', $act['type'])];
        }
    }
}

// --- 5) 输出 markdown -----------------------------------------------------

$by = ['high' => [], 'mid' => [], 'low' => []];
foreach ($drifts as $d) {
    $by[$d['sev']][] = $d;
}

$out   = [];
$out[] = '# DB ↔ scaffold YAML 漂移探查';
$out[] = '';
$out[] = sprintf('- 探查时间：%s', date('Y-m-d H:i'));
$out[] = sprintf('- DB：`%s` @ %s:%s', $schema, $env['DB_HOST'], $env['DB_PORT']);
$out[] = sprintf('- YAML 覆盖表数：%d', count($expected));
$out[] = sprintf('- DB 实际表数（整个 schema）：%d', count($allDbTables));
$out[] = sprintf('- 表缺失（YAML 有但 DB 无）：%d', count($missingTablesInDb));
$out[] = sprintf('- 表多余（DB 有但 YAML 无）：%d', count($missingTablesInYaml));
$out[] = sprintf('- 列级 drift：high=%d / mid=%d / low=%d', count($by['high']), count($by['mid']), count($by['low']));
$out[] = '';

$out[] = '## 维度说明';
$out[] = '';
$out[] = '- **high**：列在 DB 缺失（YAML 声明了但表里没有）/ 类型族不一致 / 未识别的 YAML 简写';
$out[] = '- **mid**：nullable 不一致 / 列在 YAML 缺失（DB 有列但 YAML 未声明）';
$out[] = '- **low**：varchar / char size 不一致';
$out[] = '- 未覆盖维度：default 值、comment、index、unsigned、decimal precision/scale（首轮探查刻意省略，看清形态再决定要不要加）';
$out[] = '';
$out[] = '比对前 YAML → MySQL 类型映射沿用 `CreateMigrationGenerator::buildFieldsCode()`（bool→tinyint, binary→blob, jsonb→json, float/double 各自保留）。';
$out[] = '';

if ($missingTablesInDb) {
    $out[] = '## 表缺失：YAML 声明但 DB 无';
    $out[] = '';
    foreach ($missingTablesInDb as $t) {
        $out[] = "- `$t`";
    }
    $out[] = '';
}

if ($missingTablesInYaml) {
    // 查 database/migrations/ 里是否有针对这些表的 drop migration
    $migDir       = $repo . '/engine/database/migrations';
    $pendingDrops = [];
    foreach ($missingTablesInYaml as $t) {
        $hit = glob($migDir . '/*drop_' . $t . '_table*.php');
        if ($hit) {
            $pendingDrops[$t] = basename($hit[0]);
        }
    }

    $out[] = '## 表多余：DB 有但 YAML 无';
    $out[] = '';
    $out[] = '（已与 `engine/database/migrations` 交叉对账，识别已立 drop migration 的"待执行下线"项）';
    $out[] = '';
    foreach ($missingTablesInYaml as $t) {
        if (isset($pendingDrops[$t])) {
            $out[] = sprintf('- `%s` — 已立 drop migration: `%s`（待 `migrate` 执行）', $t, $pendingDrops[$t]);
        } else {
            $out[] = "- `$t` — 无 drop migration，**需确认是否历史遗留**";
        }
    }
    $out[] = '';
}

foreach (['high', 'mid', 'low'] as $sev) {
    if (empty($by[$sev])) {
        continue;
    }
    $out[] = sprintf('## %s (%d)', strtoupper($sev), count($by[$sev]));
    $out[] = '';
    $out[] = '| 表 | 列 | 类型 | 详情 |';
    $out[] = '|---|---|---|---|';
    foreach ($by[$sev] as $d) {
        $out[] = sprintf('| `%s` | `%s` | %s | %s |', $d['table'], $d['col'], $d['kind'], $d['detail']);
    }
    $out[] = '';
}

file_put_contents($repo . '/tools/db-yaml-drift.md', implode("\n", $out));

echo "✓ 报告已写入 tools/db-yaml-drift.md\n";
echo sprintf("  YAML 表 %d / DB 表 %d\n", count($expected), count($allDbTables));
echo sprintf("  表缺失（YAML→DB）%d / 表多余（DB→YAML）%d\n", count($missingTablesInDb), count($missingTablesInYaml));
echo sprintf("  列级 drift: high=%d mid=%d low=%d\n", count($by['high']), count($by['mid']), count($by['low']));
