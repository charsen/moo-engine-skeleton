<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Description: 操作日志中间件（host 侧，生产项目实现的精简版）
 *
 * moo-system 提供 system_operation_logs 表和 AddOperationLogJob，但「什么时候记、
 * 记什么」由 host 决定 —— 本中间件就是这个采集点，挂在 admin / moo-system 组上。
 * terminate()：响应发出后才执行，不拖慢请求。
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Mooeen\System\Jobs\AddOperationLogJob;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OperationLog
{
    /** 入参里这些键的值不入库（防密码/token 泄漏进日志） */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }

    /**
     * Terminable 中间件：在 HTTP 响应发送之后才运行
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! config('logging.operation')) {
            return;
        }

        // 通知轮询太高频，不记
        if (str_contains($request->decodedPath(), 'admin/notifications')) {
            return;
        }

        $user_id   = null;
        $user_name = '';

        if (Auth::guard('admin')->check()) {
            $user = Auth::guard('admin')->user();

            // 超级管理员不记录日志（包内 Job 也有同样的兜底）
            if ($user->isRoot()) {
                return;
            }

            $user_id   = $user->id;
            $user_name = $user->real_name;
        }

        // 成功响应不存 body，失败响应存下来便于排查。
        // 按 60000 字节截断：response_content 是 MySQL TEXT（最大 65535 字节），
        // 不能用 mb_substr(..., 60000) 按「字符」截；中文/emoji 可能占 3~4 字节，
        // 看似 6 万字符实际早已超列宽，strict mode 会让 job 失败重试。
        $content = ((int) $response->getStatusCode() !== 200 && (int) $response->getStatusCode() !== 201)
            ? mb_strcut($response->getContent() ?: '', 0, 60000, 'UTF-8')
            : '[]';

        // HTTP / artisan 入口虽定义 LARAVEL_START，phpunit 不经过这两个入口，
        // 所以统一用 PHP 请求起始时间，任何入口都可用。
        $started_at = (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

        $response_log = [
            'executed_time' => (microtime(true) - $started_at) * 1000,
            'status_code'   => $response->getStatusCode(),
            'content'       => $content,
        ];

        $request_log = [
            'user_id'    => $user_id,
            'user_name'  => $user_name,
            'uri'        => $request->path(),
            'url_path'   => $request->decodedPath(),
            'method'     => $request->method(),
            'user_agent' => $request->userAgent(),
            'ip'         => $request->ip(),
            'input'      => $this->sanitizeInput($request->all()),
            'request_at' => now(),
            'language'   => app()->getLocale(),
        ];

        try {
            AddOperationLogJob::dispatch($request_log, $response_log);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function sanitizeInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $input[$key] = '[FILTERED]';

                continue;
            }

            if ($value instanceof UploadedFile) {
                $input[$key] = $value->getClientOriginalName();

                continue;
            }

            if (is_array($value)) {
                $input[$key] = $this->sanitizeInput($value);
            }
        }

        return $input;
    }
}
