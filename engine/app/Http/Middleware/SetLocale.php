<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 按请求头切换本地化语言（moo 体系多语言约定）。
 *
 * 教学最小版：从 `Accept-Language` 取首选语言标签，只接受受支持集合内的值
 * （config('scaffold.languages')，默认 en / zh-CN），命中即 app()->setLocale()，
 * 否则回落到 config('app.locale')。挂在各路由中间件组上（见 AppServiceProvider::boot()）。
 *
 * 为什么要有它：Food 的 enum label、校验消息、moo-system 的多语言字段都靠 app locale 取值；
 * 没有它则永远用 config('app.locale') 单一语言，移动端切语言不生效。
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = (array) config('scaffold.languages', ['en', 'zh-CN']);
        $fallback  = (string) config('app.locale', 'en');

        app()->setLocale($this->parseLocale($request, $supported, $fallback));

        return $next($request);
    }

    /**
     * 从 Accept-Language 解析出受支持的语言标签；解析不到则回落。
     *
     * @param array<int, string> $supported
     */
    protected function parseLocale(Request $request, array $supported, string $fallback): string
    {
        // 只取第一段（`zh-CN,zh;q=0.9,en;q=0.8` → `zh-CN`），逗号/分号都作分隔
        $header = (string) $request->server('HTTP_ACCEPT_LANGUAGE', '');
        $first  = trim((string) preg_split('/[,;]/', $header)[0]);

        // 先精确匹配（zh-CN），再退化到主语言（zh → 命中集合里以 zh 开头的项）
        if (in_array($first, $supported, true)) {
            return $first;
        }

        $primary = strtolower(substr($first, 0, 2));
        foreach ($supported as $lang) {
            if (strtolower(substr($lang, 0, 2)) === $primary && $primary !== '') {
                return $lang;
            }
        }

        return $fallback;
    }
}
