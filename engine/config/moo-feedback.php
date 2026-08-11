<?php

declare(strict_types=1);

/*
 * moo-feedback 骨架示例配置。
 * 管理面使用独立强制认证组；匿名入口是本示例有意开启的业务用例。
 */
return [
    'admin' => [
        'prefix'     => 'api/admin',
        'name'       => 'admin.',
        'middleware' => 'moo-feedback',
    ],

    'public' => [
        'enabled'          => (bool) env('MOO_FEEDBACK_PUBLIC', true),
        'prefix'           => 'api',
        'name'             => 'feedback.',
        'middleware'       => ['api', 'throttle:30,1'],
        'required_contact' => ['feedback_contact_name', 'feedback_email'],
        'allow_target'     => false,
    ],

    // 教学默认不保存访客环境；项目确需排障信息时，完成隐私评审后再按字段开启。
    'capture' => [
        'enabled'  => false,
        'ip'       => false,
        'device'   => false,
        'platform' => false,
        'browser'  => false,
        'page_url' => false,
    ],

    'anti_spam' => [
        'throttle' => [
            'enabled'      => true,
            'window_hours' => 1,
            'max_per_ip'   => 5,
            'max_per_mail' => 3,
        ],
        'honeypot' => [
            'enabled' => true,
            'field'   => 'nickname_confirm',
        ],
        'content' => [
            'min' => 6,
            'max' => 4000,
        ],
    ],

    'redact_secrets' => true,
];
