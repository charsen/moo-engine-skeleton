<?php

declare(strict_types=1);

namespace App\Moo\Scaffold;

use Illuminate\Support\ServiceProvider;
use Mooeen\Scaffold\Contracts\OperatorResolver;

/**
 * moo-scaffold 包的 host 侧接入 provider（App\Moo\<包> 集成约定）。
 *
 * 「App\Moo\<包>」是 host 收纳「对某个 moo 生态包的本地接入/覆盖」的固定落点：包提供契约与默认实现，
 * host 在自己命名空间下的 provider 里 bind 覆盖，而不是去改 vendor/。这样包能独立升级，host 的定制不丢。
 *
 * 本 provider 目前只做一件事：把 scaffold 的操作人身份契约 OperatorResolver 覆盖成 host 口径
 * （getUserId 语义，见 GetUserIdOperatorResolver）。scaffold 用 bindIf 注册默认实现，所以这里的
 * bind 稳定生效。注册于 bootstrap/providers.php。
 */
class ScaffoldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 覆盖 scaffold 默认的 GuardOperatorResolver（auth()->id()）为 host 的 getUserId 语义。
        // 一处覆盖，全生态所有 HasOperator（creator_id / updater_id 自动填充）随之改用统一口径。
        $this->app->bind(OperatorResolver::class, GetUserIdOperatorResolver::class);
    }
}
