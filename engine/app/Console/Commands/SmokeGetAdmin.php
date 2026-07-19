<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Mooeen\System\Models\Personnel;
use Throwable;

/**
 * 后台 GET 端点冒烟对拍 —— 骨架标准件之一（冒烟对拍 / 基线快照）。
 *
 * ▍解决什么问题
 * 用一个真实 admin 签一枚 token，遍历全部 `api/admin` 的 GET 路由，经 HTTP 内核逐条直调，
 * 记录每条的 http 状态与响应体 hash/摘要，落一份 JSON 快照。用作**升级前后对拍**的基线：
 * scaffold 切代 / moo-system 接入 / 依赖大版本变更前后各跑一次，diff 两份快照即可发现
 * 「谁从 200 变 500 了」「谁的响应体形状漂了」。
 *
 * ▍典型时机
 *   - 升级前：跑一次存基线（catalog 现状，5xx 应为 0）。
 *   - 升级后：再跑一次，与基线对照，回归立刻显形。
 *   - 平时：想快速确认「所有列表/详情页还活着」时的一键体检。
 *
 * ▍口径
 * 5xx 期望恒为 0。若现状已有 5xx（如某端点回归），**如实入基线**并在末尾列清单，
 * 不隐藏、不美化——基线要反映真相，才能对拍出「新引入的」回归。
 */
class SmokeGetAdmin extends Command
{
    protected $signature = 'smoke:get-admin
                            {--id=1 : {id} 占位符替换值（默认 1）}
                            {--mobile= : 以该手机号的 admin 签发 token（默认取 id 最小者）}
                            {--out=app/smoke/baseline.json : 输出文件（相对 storage_path 或绝对路径）}';

    protected $description = '遍历全部 admin GET 路由，记录状态与响应体摘要，落 JSON 基线快照（5xx 如实入册并列清单），供升级前后对拍。';

    private string $token = '';

    public function handle(): int
    {
        if (! $this->prepare()) {
            return self::FAILURE;
        }

        $fixtureId = (string) $this->option('id');
        $outPath   = (string) $this->option('out');
        if (! str_starts_with($outPath, '/')) {
            $outPath = storage_path($outPath);
        }

        $kernel  = app(Kernel::class);
        $routes  = $this->collectGetRoutes();
        $entries = [];
        $skipped = [];
        $counter = ['2xx' => 0, '3xx' => 0, '401' => 0, '403' => 0, '404' => 0, '422' => 0, '522' => 0, '5xx' => 0, 'other' => 0];
        $started = microtime(true);

        foreach ($routes as $route) {
            $rawUri = $route->uri();
            $action = ltrim($route->getActionName(), '\\');

            // 目前 admin GET 路由的路径参数只有 {id}，可安全替换；如遇其它无法构造的必填占位符则记录跳过
            $unresolved = $this->unresolvedParams($rawUri);
            if (! empty($unresolved)) {
                $skipped[] = ['uri' => $rawUri, 'action' => $action, 'reason' => 'unconstructable params: ' . implode(',', $unresolved)];

                continue;
            }

            $uri = $this->substitute($rawUri, $fixtureId);

            try {
                $request  = $this->buildRequest($uri);
                $response = $kernel->handle($request);
                $status   = $response->getStatusCode();
                $body     = (string) $response->getContent();

                $entries[] = [
                    'uri'         => $uri,
                    'raw_uri'     => $rawUri,
                    'action'      => $action,
                    'status'      => $status,
                    'category'    => $this->classify($status),
                    'body_sha256' => hash('sha256', $body),
                    'body_len'    => strlen($body),
                    'body_head'   => mb_substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 240),
                ];
            } catch (Throwable $e) {
                $status    = 599;
                $entries[] = [
                    'uri'         => $uri,
                    'raw_uri'     => $rawUri,
                    'action'      => $action,
                    'status'      => $status,
                    'category'    => 'THROWN',
                    'exception'   => get_class($e),
                    'body_head'   => mb_substr($e->getMessage(), 0, 240),
                    'body_sha256' => null,
                    'body_len'    => 0,
                ];
            }

            $counter[$this->bucket($status)]++;
        }

        $fiveXx = array_values(array_filter($entries, fn ($e) => $e['status'] >= 500));

        $snapshot = [
            'generated_at' => now()->toDateTimeString(),
            'database'     => \Illuminate\Support\Facades\DB::getDatabaseName(),
            'prefix'       => 'api/admin',
            'fixture_id'   => $fixtureId,
            'elapsed_sec'  => round(microtime(true) - $started, 2),
            'summary'      => [
                'total_routes'   => count($routes),
                'tested'         => count($entries),
                'skipped'        => count($skipped),
                'status_buckets' => $counter,
                'has_5xx'        => count($fiveXx) > 0,
                'count_5xx'      => count($fiveXx),
            ],
            'five_xx' => $fiveXx,
            'skipped' => $skipped,
            'entries' => $entries,
        ];

        $dir = dirname($outPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($outPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info(sprintf('Tested %d / %d routes (skipped %d) in %ss', count($entries), count($routes), count($skipped), $snapshot['elapsed_sec']));
        $this->line('  2xx=' . $counter['2xx'] . ' 3xx=' . $counter['3xx'] . ' 401=' . $counter['401'] . ' 403=' . $counter['403']
            . ' 404=' . $counter['404'] . ' 422=' . $counter['422'] . ' 522=' . $counter['522'] . ' 5xx=' . $counter['5xx'] . ' other=' . $counter['other']);
        $this->line('  snapshot: ' . $outPath);

        if (! empty($fiveXx)) {
            $this->newLine();
            $this->warn('⚠ 现状 5xx 路由清单（如实入基线）：');
            foreach ($fiveXx as $e) {
                $this->line(sprintf('  [%d] %s  →  %s', $e['status'], $e['uri'], $e['body_head']));
            }
        } else {
            $this->info('  5xx = 0 ✓');
        }

        return self::SUCCESS;
    }

    private function prepare(): bool
    {
        $mobile = (string) $this->option('mobile');
        $query  = Personnel::query()->whereNotNull('password');
        $admin  = $mobile !== '' ? $query->where('mobile', $mobile)->first() : $query->orderBy('id')->first();

        if (! $admin) {
            $this->error('未找到可用 admin（需有 password 的 Personnel；先跑 db:seed）。');

            return false;
        }
        $this->info("Signing token as admin id={$admin->id} real_name={$admin->real_name}");

        // 冒烟期间临时抬高 admin 限流，避免遍历时触发 429
        RateLimiter::for('admin', fn () => Limit::perMinute(1000000));

        // 必须经 admin guard 签发：裸 JWTAuth 在 CLI 下会用默认(web/users) provider 打 prv claim，
        // 导致后续 authenticate() 因 lock_subject 模型哈希不符而查不到用户 → 401（坑 #8 的近亲）。
        $this->token = app('auth')->guard('admin')->login($admin);

        return true;
    }

    private function buildRequest(string $uri): Request
    {
        $request = Request::create('/' . ltrim($uri, '/'), 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $this->token);
        $request->headers->set('Accept', 'application/json');

        return $request;
    }

    /**
     * @return Route[]
     */
    private function collectGetRoutes(): array
    {
        $out = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (! str_starts_with($route->uri(), 'api/admin')) {
                continue;
            }
            $out[] = $route;
        }
        usort($out, fn ($a, $b) => strcmp($a->uri(), $b->uri()));

        return $out;
    }

    /**
     * 除 {id} 外（可安全替换）的其它占位符视为无法构造。
     *
     * @return array<int, string>
     */
    private function unresolvedParams(string $uri): array
    {
        preg_match_all('/\{([a-zA-Z_]+)\??\}/', $uri, $m);

        return array_values(array_filter($m[1], fn ($p) => $p !== 'id'));
    }

    private function substitute(string $uri, string $id): string
    {
        return preg_replace('/\{id\??\}/', $id, $uri) ?? $uri;
    }

    private function classify(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => 'PASS',
            $status >= 300 && $status < 400 => 'REDIRECT',
            $status === 401                 => 'AUTH',
            $status === 403                 => 'ACL',
            $status === 404                 => 'NOT-FOUND',
            $status === 422                 => 'VALIDATION',
            $status === 522                 => 'BUSINESS',
            $status >= 500                  => 'SERVER-ERROR',
            default                         => 'OTHER',
        };
    }

    private function bucket(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => '2xx',
            $status >= 300 && $status < 400 => '3xx',
            $status === 401                 => '401',
            $status === 403                 => '403',
            $status === 404                 => '404',
            $status === 422                 => '422',
            $status === 522                 => '522',
            $status >= 500                  => '5xx',
            default                         => 'other',
        };
    }
}
