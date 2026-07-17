<?php

declare(strict_types=1);
/*
 * 全局辅助函数（通过 composer autoload.files 自动加载）。
 * moo-system 的部分控制器会调用这里的 toLabelValue() 生成前端 label-value 选项。
 *
 * 收录门槛：只放「本仓有真实消费者」的函数（防 helpers 变垃圾抽屉）——
 *   getUserId() ← App\Moo\Scaffold\GetUserIdOperatorResolver（scaffold 操作人契约）
 *   logAuth()  ← bootstrap/app.php 的 401 render（认证失败留痕）
 */

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;

if (! function_exists('getUserId')) {
    /**
     * 取当前登录用户 ID；游客返 null。
     *
     * 读 config('auth.defaults.guard') —— 该默认守卫由 JWTAssignGuard 中间件按路由前缀设定
     * （admin 组设 admin 守卫、client 组设 user 守卫）。全站「当前是谁」的唯一真值口径，
     * scaffold 的 OperatorResolver 与业务代码都复用它，避免各处直接 auth() 取值逻辑漂移。
     */
    function getUserId(): int|string|null
    {
        $guard = config('auth.defaults.guard');

        return auth($guard)->check() ? auth($guard)->id() : null;
    }
}

if (! function_exists('getUser')) {
    /**
     * 取当前登录用户模型；游客返 null。守卫口径同 getUserId()。
     */
    function getUser(): ?Authenticatable
    {
        return auth(config('auth.defaults.guard'))->user();
    }
}

if (! function_exists('logDev')) {
    /**
     * 开发调试打点，落 storage/logs/dev.log（与业务/生产日志隔离）。
     *
     * @param array<mixed>|string $message 数组自动 json 序列化
     */
    function logDev(string $title, array|string $message): void
    {
        $message = is_array($message) ? json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : $message;
        Log::channel('dev')->info("{$title}\n{$message}");
    }
}

if (! function_exists('logAuth')) {
    /**
     * 认证链路留痕，落 storage/logs/auth.log。
     *
     * @param mixed $message  普通消息；$parse=true 时传 JWT payload 对象，自动展开 iat/exp/nbf 可读时间
     * @param bool  $parse    是否把 $message 当 JWT payload 解析
     * @param bool  $useError 用 error 级别（默认 info）
     */
    function logAuth(string $title, mixed $message, bool $parse = false, bool $useError = false): void
    {
        // JWT claim 速查：iss 签发方 / sub 主体(user id) / exp 过期 / nbf 生效起点 / iat 签发时刻 / jti 唯一标识
        if ($parse) {
            $data            = $message->getPayload()->toArray();
            $data['iat_str'] = date('Y-m-d H:i:s', $data['iat']);
            $data['exp_str'] = date('Y-m-d H:i:s', $data['exp']);
            $data['nbf_str'] = date('Y-m-d H:i:s', $data['nbf']);
            $message         = var_export($data, true);
        }

        Log::channel('auth')->{$useError ? 'error' : 'info'}("{$title}\n{$message}");
    }
}

if (! function_exists('toLabelValue')) {
    /**
     * 把数据集转成前端「label-value」选项结构（支持树状 children 与关联子项）。
     *
     * @param array  $data        数据集（数组）
     * @param string $key_field   作为 value 的字段名
     * @param string $label_field 作为 label 的字段名
     * @param string $count_field 可选：作为 count 的字段名
     * @param array  $other       可选：关联子项 [关联字段, 子value字段, 子label字段, 前缀?]
     */
    function toLabelValue(array $data, string $key_field, string $label_field, string $count_field = '', array $other = []): array
    {
        $res = [];
        foreach ($data as $one) {
            $tmp = ['value' => $one[$key_field], 'label' => $one[$label_field]];

            if ($count_field !== '') {
                $tmp['count'] = $one[$count_field];
            }

            if (! empty($one['children'])) {
                $tmp['children'] = toLabelValue($one['children'], $key_field, $label_field, $count_field, $other);
            }

            // 处理 model 的关联数据（已是最后一级）
            if (! empty($other) && ! empty($one[$other[0]])) {
                $select = [];
                $prefix = $other[3] ?? ' · ';
                foreach ($one[$other[0]] as $o) {
                    $select[] = ['value' => $o[$other[1]], 'label' => $prefix . $o[$other[2]]];
                }
                $tmp['children'] = isset($tmp['children']) ? array_merge($tmp['children'], $select) : $select;
            }

            $res[] = $tmp;
        }

        if (empty($res)) {
            $res = [['label' => '暂无相关数据', 'value' => '']];
        }

        return $res;
    }
}
