<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Description: ACL 授权 Gate（host 契约，仿 wisdomcity）
 *
 * moo-scaffold 的 Foundation\Controller::checkAuthorization() 只负责把
 * 「当前控制器::action」格式化成 acl key 再喂给 Gate —— Gate 本身必须由 host 定义。
 * 判定顺序：root 直通 → config/actions.php 白名单 → 角色授权动作（含 is_root 字面量）。
 */

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerAclAuthentication();
    }

    /**
     * Usage: $this->authorize('acl_authentication', $acl_key);
     */
    protected function registerAclAuthentication(): void
    {
        Gate::define('acl_authentication', function ($personnel, $acl_key) {
            // root（id=1）直通；雪花主键的系统里一般不存在，靠角色 is_root 兜底
            if ($personnel->isRoot()) {
                return true;
            }

            // 白名单动作（config/actions.php，moo:acl 生成时维护）人人可用
            $apps = config('scaffold.controller');
            foreach ($apps as $app => $config) {
                $whitelist = config('actions.'.$app.'.whitelist');
                if (isset($whitelist[0]) && in_array($acl_key, $whitelist, true)) {
                    return true;
                }
            }

            $role_actions = $personnel->getActions();

            // 角色被授了 is_root 字面量 = 超级权限（RoleSeeder 给「系统管理员」的就是它）
            if (in_array('is_root', $role_actions, true)) {
                return true;
            }

            return in_array($acl_key, $role_actions, true);
        });
    }
}
