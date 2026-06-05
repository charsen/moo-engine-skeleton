<?php

declare(strict_types=1);

use App\Models\User;
use Mooeen\System\Models\Personnel;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    | 默认守卫改为 admin（JWT），因为本骨架是 API 后端，主要入口是后台管理接口。
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'admin'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    | admin / user 两个 JWT 守卫共用 personnels provider（即 moo-system 的 Personnel）。
    | hash=false：密码在 AuthController 里手动 Hash::check 校验，不走守卫的自动校验。
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'admin' => [
            'driver' => 'jwt',
            'provider' => 'personnels',
            'hash' => false,
        ],

        // 移动端 API，与 admin 平行
        'user' => [
            'driver' => 'jwt',
            'provider' => 'personnels',
            'hash' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    | personnels.model 必须是 moo-system 的真实 FQN（moo-system check 会校验）。
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        'personnels' => [
            'driver' => 'eloquent',
            'model' => Personnel::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
