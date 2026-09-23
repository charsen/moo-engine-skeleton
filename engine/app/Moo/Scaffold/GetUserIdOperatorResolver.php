<?php

declare(strict_types=1);

namespace App\Moo\Scaffold;

use Mooeen\Scaffold\Contracts\OperatorResolver;

/**
 * moo 生态「当前操作人」身份解析的 host 实现 —— scaffold 共享契约 OperatorResolver 的唯一 host 绑定。
 *
 * scaffold 包默认绑 GuardOperatorResolver（裸 auth()->id()）。host 覆盖成本类，改走 getUserId()：
 * 读 config('auth.defaults.guard')（JWTAssignGuard 中间件按路由设默认守卫；游客返 null）——
 * 与全站「当前是谁」的唯一真值口径锁步，防包默认 auth()->id() 与 getUserId 逻辑漂移。
 *
 * 覆盖后，生成模型 HasOperator 的 creator_id / updater_id 自动填充全走这条口径。
 */
class GetUserIdOperatorResolver implements OperatorResolver
{
    public function id(): int|string|null
    {
        return getUserId();
    }
    /**
     * 平台 root 身份 = 人员模型的 `isRootId()`（与全站 root 判定唯一真值口径锁步）。
     *
     * 与 `id()` 同属「操作者身份」，实现在**同一处**绑定里 —— 各业务包统一经 `OperatorResolver`
     * 取用，不再各自定义契约与宿主适配器（2026-09-22 随 moo-scaffold 2.2.8 契约扩展补齐）。
     */
    public function isPlatformRoot(int|string|null $operatorId): bool
    {
        return \Mooeen\System\Models\Personnel::isRootId($operatorId);
    }

}
