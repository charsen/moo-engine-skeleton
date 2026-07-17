<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 自建 JWT 主体的初始用户（docs 第 3 章）。
 *
 * - admin@example.com / password —— actions 带 'is_root'（第 5 章 ACL 的超级权限）；
 * - email_verified_at 必须有值：登录前置检查会拒绝未验证邮箱的账号（第 4 章）。
 *
 * 运行：php artisan db:seed --class=UserSeeder
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $user                    = User::firstOrNew(['email' => 'admin@example.com']);
        $user->name              = '管理员';
        $user->password          = 'password';            // casts 里 'password' => 'hashed' 自动加密
        $user->actions           = ['is_root'];            // 超级权限（ACL Gate 第三优先级，见第 5 章）
        $user->email_verified_at = now();
        $user->save();

        $this->command?->info('UserSeeder：admin@example.com / password（is_root 超级权限）。');
    }
}
