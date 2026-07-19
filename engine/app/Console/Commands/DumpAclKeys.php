<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Mooeen\Scaffold\Foundation\Controller;
use ReflectionClass;

/**
 * ACL key 存档 —— 骨架标准件之一（ACL 存档 / 授权基准）。
 *
 * ▍解决什么问题
 * 扫描全部 admin 控制器带 @acl 的动作，导出每个 action 的：
 *  - 明文 key（aclPlainKey 口径：<app>-<module>-<controller>-<action>）
 *  - 16 位 md5 片段（substr(md5(明文), 8, 16)，即运行期真正比对的 role_actions key）
 * 另附 config/actions.php 白名单、各角色 role_actions 现值。
 *
 * ▍典型时机
 * 升级 scaffold（尤其跨大版本）前跑一次存基准。scaffold 若改了 aclPlainKey 的拼法，
 * md5 片段会整体漂移，导致「授权数据没动、但线上全员突然 403」这类静默灾难。
 * 升级后再跑一次对拍 md5 是否漂移，是授权体系的硬关卡。
 *
 * ▍覆盖范围
 * 既扫 host 自建控制器（app/Admin/Controllers/**），也扫扩展包登记进
 * config('scaffold.controller.admin.extra_modules') 的模块（如 moo-system 的后台控制器，
 * 它们在 vendor/ 下、不在 host 路径里，但 ACL key 仍反查为 admin-system-*，必须一并入册）。
 */
class DumpAclKeys extends Command
{
    protected $signature = 'app:dump-acl-keys
                            {--out= : 输出文件（相对 storage_path 或绝对；默认 app/acl/acl-keys-scaffold-<ver>.json）}';

    protected $description = '扫 admin 控制器 @acl 动作，导出明文 key + 16 位 md5 片段 + 白名单 + 角色 role_actions，落 JSON 基准供升级对拍。';

    public function handle(): int
    {
        $scaffoldVersion = $this->scaffoldVersion();

        $controllers = [];
        $actionCount = 0;

        // 1) host 自建 admin 控制器（app/Admin/Controllers/**）
        foreach (File::allFiles(app_path('Admin/Controllers')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $fqcn = $this->fqcnFromPath($file->getRealPath());
            if ($fqcn === null || ! class_exists($fqcn)) {
                continue;
            }
            $this->scanController($fqcn, $controllers, $actionCount);
        }

        // 2) 扩展包提供的 admin 模块（config scaffold.controller.admin.extra_modules）。
        //    这些控制器不在 app/ 下（如 moo-system 在 vendor/），但 ACL key 仍按 extra_modules
        //    反查为 admin-<module>-*，必须一并扫描才能与升级前基准对拍完整。
        foreach ((array) config('scaffold.controller.admin.extra_modules', []) as $namespace) {
            foreach ($this->moduleControllerFqcns((string) $namespace) as $fqcn) {
                if (class_exists($fqcn)) {
                    $this->scanController($fqcn, $controllers, $actionCount);
                }
            }
        }

        ksort($controllers);

        // 角色 role_actions 现值（辅助信息）。防御性 try/catch：DB 结构差异或尚未 seed 时读空即可，
        // 绝不因取角色失败让命令崩掉（key 对拍不依赖此段）。
        $roles = [];
        try {
            $roleModel = config('auth.providers.personnels.model') ? \Mooeen\System\Models\Role::class : null;
            if ($roleModel) {
                $roles = \Mooeen\System\Models\Role::query()->orderBy('id')->get(['id', 'role_name', 'role_next_actions'])->map(fn ($r) => [
                    'id'                 => (string) $r->id,
                    'role_name'          => $r->role_name,
                    'role_actions'       => $r->role_actions,          // getter → 数组
                    'role_actions_count' => count($r->role_actions),
                ])->all();
            }
        } catch (\Throwable $e) {
            $roles = [];
        }

        $snapshot = [
            'generated_at'        => now()->toDateTimeString(),
            'scaffold_version'    => $scaffoldVersion,
            'md5_enabled'         => (bool) config('scaffold.authorization.md5'),
            'authorization_check' => (bool) config('scaffold.authorization.check'),
            'summary'             => [
                'controllers'     => count($controllers),
                'acl_actions'     => $actionCount,
                'whitelist_count' => count((array) config('actions.admin.whitelist', [])),
                'roles'           => count($roles),
            ],
            'controllers' => $controllers,
            'whitelist'   => array_values((array) config('actions.admin.whitelist', [])),
            'roles'       => $roles,
        ];

        $out = (string) ($this->option('out') ?: "app/acl/acl-keys-scaffold-{$scaffoldVersion}.json");
        if (! str_starts_with($out, '/')) {
            $out = storage_path($out);
        }
        File::ensureDirectoryExists(dirname($out));
        File::put($out, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info(sprintf(
            'ACL 存档完成：%d 控制器 / %d 个 @acl action / 白名单 %d / 角色 %d',
            count($controllers), $actionCount, $snapshot['summary']['whitelist_count'], count($roles),
        ));
        $this->line('  scaffold: ' . $scaffoldVersion . '  md5=' . var_export($snapshot['md5_enabled'], true));
        $this->line('  out: ' . $out);

        return self::SUCCESS;
    }

    /**
     * 扫描单个控制器 FQCN 的 @acl 动作，累加进 $controllers / $actionCount。
     */
    private function scanController(string $fqcn, array &$controllers, int &$actionCount): void
    {
        $ref = new ReflectionClass($fqcn);
        if ($ref->isAbstract() || $ref->isTrait()) {
            return;
        }

        $actions = [];
        foreach ($ref->getMethods() as $method) {
            if ($method->class !== $fqcn || ! $method->isPublic() || $method->isStatic()) {
                continue;
            }
            $doc = $method->getDocComment() ?: '';
            if (! str_contains($doc, '@acl')) {
                continue;
            }

            $target = $fqcn . '::' . $method->getName();
            $plain  = Controller::aclPlainKey($target);
            $md5key = substr(md5($plain), 8, 16);

            $actions[] = [
                'action'    => $method->getName(),
                'acl_label' => $this->extractAclLabel($doc),
                'plain_key' => $plain,
                'md5_key'   => $md5key,
            ];
            $actionCount++;
        }

        if (! empty($actions)) {
            usort($actions, fn ($a, $b) => strcmp($a['action'], $b['action']));
            $controllers[$fqcn] = $actions;
        }
    }

    /**
     * 由 extra_modules 命名空间（如 Mooeen\System\Http\Controllers\Admin）反查其目录下的控制器 FQCN。
     * 走 Composer PSR-4 前缀表定位目录，仅扫顶层 *.php（Traits/ 等子目录不计）。
     *
     * @return array<int, string>
     */
    private function moduleControllerFqcns(string $namespace): array
    {
        $namespace = trim($namespace, '\\');
        if ($namespace === '') {
            return [];
        }

        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader   = require base_path('vendor/autoload.php');
        $prefixes = $loader->getPrefixesPsr4();

        $bestPrefix = null;
        $bestDir    = null;
        foreach ($prefixes as $prefix => $dirs) {
            if (str_starts_with($namespace . '\\', $prefix) && (($bestPrefix === null) || strlen($prefix) > strlen($bestPrefix))) {
                $bestPrefix = $prefix;
                $bestDir    = $dirs[0] ?? null;
            }
        }
        if ($bestDir === null) {
            return [];
        }

        $sub = trim(substr($namespace . '\\', strlen($bestPrefix)), '\\');
        $dir = rtrim($bestDir, '/') . ($sub === '' ? '' : '/' . str_replace('\\', '/', $sub));

        $res = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $res[] = $namespace . '\\' . basename($file, '.php');
        }

        return $res;
    }

    private function scaffoldVersion(): string
    {
        try {
            $v = InstalledVersions::getPrettyVersion('charsen/moo-scaffold') ?? 'unknown';
        } catch (\Throwable) {
            $v = 'unknown';
        }

        return ltrim($v, 'v');
    }

    private function fqcnFromPath(string $path): ?string
    {
        $base = app_path('Admin/Controllers');
        if (! str_starts_with($path, $base)) {
            return null;
        }
        $rel = trim(substr($path, strlen($base)), '/');
        $rel = preg_replace('/\.php$/', '', $rel);

        return 'App\\Admin\\Controllers\\' . str_replace('/', '\\', $rel);
    }

    private function extractAclLabel(string $doc): string
    {
        if (preg_match('/@acl\s*\{([^}]*)\}/', $doc, $m)) {
            return trim($m[1]);
        }

        return '';
    }
}
