<?php

declare(strict_types=1);

return [
    'admin' => [
        // ACL 白名单：登录即可用，不需要角色授权（Gate 'acl_authentication' 第二优先级）。
        // 个人中心（moo-system AdminController）必须放行 —— 否则零授权角色（如刚建的
        // 「编辑员」）登录后连查看本人信息 / 改密码都 403，把自己锁死在门外。
        // key = substr(md5(明文), 8, 16)，明文见注释，可在 /scaffold/routes 页核对。
        'whitelist' => [
            '84470713dcb9a7c9', // admin-system-admin-index         个人中心·本人信息
            'f6d488cc41bea74a', // admin-system-admin-edit          个人中心·编辑表单
            'b00ef1ce449c970b', // admin-system-admin-update        个人中心·更新资料
            'cbc32275c4bdb06c', // admin-system-admin-password-form 个人中心·改密码表单
            '88e610dbb210a3dc', // admin-system-admin-password      个人中心·修改密码
            '1fcbfd9524aebb83', // admin-system-admin-avatar-form   个人中心·头像表单
            'd59a5622ff031201', // admin-system-admin-avatar        个人中心·更新头像
            'e389e65e330e8af2', // admin-system-admin-logins        个人中心·登录记录
        ],
        'actions' => [],
    ],
];
