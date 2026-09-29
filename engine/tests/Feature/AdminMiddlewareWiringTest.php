<?php

declare(strict_types=1);
/*
 * 后台中间件组接线守护：**每个带后台路由的扩展包都必须登记自己命名的组，且不得借用 admin**。
 *
 * 组名从各包 config 的 `admin.middleware` 派生 —— 与 `bootstrap/app.php` 的登记逻辑同源，
 * 所以这里既守住「包默认值不能写 admin」，也守住「host 必须把该组注册出来」两侧，
 * 避免再出现「host 有组、包默认写 admin」或「包指向的组 host 没有」的反向不一致。
 */

namespace Tests\Feature;

use Tests\TestCase;

class AdminMiddlewareWiringTest extends TestCase
{
    /**
     * 所有声明了后台中间件组的 `moo-*` 包配置：包名 => 组名。
     *
     * @return array<string, string>
     */
    private function packageAdminGroups(): array
    {
        $groups = [];

        foreach (config()->all() as $key => $packageConfig) {
            if (! is_string($key) || ! str_starts_with($key, 'moo-') || ! is_array($packageConfig)) {
                continue;
            }

            $group = $packageConfig['admin']['middleware'] ?? null;

            if (is_string($group) && $group !== '') {
                $groups[$key] = $group;
            }
        }

        return $groups;
    }

    public function test_every_admin_package_uses_and_registers_its_own_middleware_group(): void
    {
        $groups     = $this->packageAdminGroups();
        $registered = array_keys(app('router')->getMiddlewareGroups());

        $this->assertNotEmpty($groups, '没有发现任何声明 admin.middleware 的 moo-* 包配置，测试前提不成立');

        $offenders = [];

        foreach ($groups as $package => $group) {
            if (in_array($group, ['admin', 'web'], true)) {
                $offenders[] = "{$package} 仍借用 {$group}";

                continue;
            }

            if (! in_array($group, $registered, true)) {
                $offenders[] = "{$package} 的组 {$group} 未在 host 注册";
            }
        }

        $this->assertSame([], $offenders, "后台中间件组接线不完整：\n  " . implode("\n  ", $offenders));
    }

    public function test_package_admin_routes_carry_their_own_group(): void
    {
        $loose = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin')) {
                continue;
            }

            // 只看扩展包（Mooeen\*）注册的后台路由；host 自己的 App\* 路由可用 admin 组
            if (! str_starts_with((string) $route->getActionName(), 'Mooeen\\')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $hasPackageGroup = array_filter(
                $middleware,
                fn ($name) => is_string($name) && str_starts_with($name, 'moo-'),
            );

            if ($hasPackageGroup === []) {
                $loose[] = $route->uri();
            }
        }

        $this->assertSame(
            [],
            $loose,
            "这些扩展包后台路由没有挂到包自己的组上：\n  " . implode("\n  ", array_slice($loose, 0, 20)),
        );
    }
}
