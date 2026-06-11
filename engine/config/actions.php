<?php

declare(strict_types=1);

// ⚠️ 本文件是「再生成区」：`php artisan moo:auth admin` 会整文件重写（坑 #25），
// 注释与下方手动合并段全部会被抹掉。重跑 moo:auth 后必须把「手动合并」段的
// 8 个个人中心 key 合并回 whitelist —— FoodAclTest 里有守护断言，丢了测试会红。
return [
    'admin' => [
        // ACL 白名单：登录即可用，不需要角色授权（Gate 'acl_authentication' 第二优先级）。
        // key = substr(md5(明文), 8, 16)，明文见行尾注释，可在 /scaffold/routes 页核对。
        'whitelist' => [
            // —— moo:auth 自动产出（docblock 不带 @acl 的 action 视为「登录即可用」）——
            'acd00c2eda7d9682', // admin-system-personnel-operation-log-index 人员操作日志列表
            '48d3ca3e656e3566', // admin-system-login-management-index        登录列表
            '46c447ee55a627c6', // admin-auth-authenticate                    登录
            'dffe71cfa5a3c405', // admin-auth-logout                          退出登录
            'e9532ad1cd13af0a', // admin-auth-me                              当前登录人信息
            '42f63e42d2308d4f', // admin-auth-refresh                         主动刷新 token
            '90a2d391c09ff58d', // admin-system-authorization-update-actions  更新角色动作（包内有「可管边界」约束）
            // —— 手动合并（坑 #20/#25）：moo-system AdminController 个人中心 8 个动作 ——
            // 这些 action 带 @acl（属可授权动作），moo:auth 不会自动放进白名单，但必须放行：
            // 否则零授权角色（如刚建的「编辑员」）登录后连本人信息 / 改密码都 403，自己锁死在门外。
            '84470713dcb9a7c9', // admin-system-admin-index         个人中心·本人信息
            'f6d488cc41bea74a', // admin-system-admin-edit          个人中心·编辑表单
            'b00ef1ce449c970b', // admin-system-admin-update        个人中心·更新资料
            'cbc32275c4bdb06c', // admin-system-admin-password-form 个人中心·改密码表单
            '88e610dbb210a3dc', // admin-system-admin-password      个人中心·修改密码
            '1fcbfd9524aebb83', // admin-system-admin-avatar-form   个人中心·头像表单
            'd59a5622ff031201', // admin-system-admin-avatar        个人中心·更新头像
            'e389e65e330e8af2', // admin-system-admin-logins        个人中心·登录记录
        ],
        'actions' => [
            'module-6e1ee1805962ce1b' => [
                'controller-6acdae7d4ff27b39' => [
                    'e7746966a2caa301',
                    '4e0ea90176a9a5d4',
                    '26f8cc4c634cc762',
                    'd84c4f5251f855f0',
                    '2fbd315bd61d3ab8',
                    '5e41325cb846c3b7',
                    '72046d8c7e9dfa85',
                ],
            ],
            'module-3be4816a2540436b' => [
                'controller-dc555af4f0bdfd3c' => [
                    'b3ada2315ab43aa3',
                    '07f5f537472dab96',
                    '268ea0fecc6f1e44',
                    'b5b9ad9e015f0fde',
                ],
                'controller-b9c6987c4df140c8' => [
                    'be99d97e69677bb8',
                    '80138bd80609db61',
                    '5649fe2faeff1d6e',
                    '367fcecbd5ccd47e',
                    '0af2ab276b350948',
                    'fb4ddbb02186c8f5',
                    '02b45d4094e6a333',
                ],
                'controller-bb701f9106045d51' => [
                    '38ebec893018260f',
                    '008bb467fb6436f0',
                    '28b3c41c6235eea9',
                    'c3cc14385f0293d8',
                    '56f6db0ccd99c914',
                ],
                'controller-2fae3a4efe34b803' => [
                    '550a8dacdcee12f3',
                    '0bc502f2c475c811',
                    'aa00cbec75768ec9',
                ],
                'controller-fec08700f2af8413' => [
                    '8a244c37457907f6',
                    'feac3caa733783bd',
                ],
                'controller-01335606d5a5d365' => [
                    '48d3ca3e656e3566',
                ],
                'controller-ac28ebe832d23721' => [
                    'd59a5622ff031201',
                    'b00ef1ce449c970b',
                    '84470713dcb9a7c9',
                    '88e610dbb210a3dc',
                ],
                'controller-6351cb0893e9beeb' => [
                    '260134192d792203',
                    '80e08bf5dfe5b440',
                    'ec2cd321a60acaee',
                ],
            ],
        ],
    ],
];
