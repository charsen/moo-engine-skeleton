<?php
// 本文件由 `php artisan moo:auth <app>` 再生成。重跑 admin 后，必须把下面标注的
// moo-system 个人中心白名单重新合并，避免零授权角色登录后连自己的资料也无法访问。
return [
    'admin' => [
        'whitelist' => [
            'acd00c2eda7d9682',
            '48d3ca3e656e3566',
            '84470713dcb9a7c9',
            '46c447ee55a627c6',
            'dffe71cfa5a3c405',
            'e9532ad1cd13af0a',
            '42f63e42d2308d4f',
            '90a2d391c09ff58d',
            // moo-system AdminController 个人中心（手动合并，生成器不会自动加入）
            'f6d488cc41bea74a',
            'b00ef1ce449c970b',
            'cbc32275c4bdb06c',
            '88e610dbb210a3dc',
            '1fcbfd9524aebb83',
            'd59a5622ff031201',
            'e389e65e330e8af2'
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
                    '72046d8c7e9dfa85'
                ]
            ],
            'module-3be4816a2540436b' => [
                'controller-dc555af4f0bdfd3c' => [
                    'b3ada2315ab43aa3',
                    '07f5f537472dab96',
                    '268ea0fecc6f1e44',
                    'b5b9ad9e015f0fde'
                ],
                'controller-b9c6987c4df140c8' => [
                    'be99d97e69677bb8',
                    '80138bd80609db61',
                    '02b45d4094e6a333',
                    '5649fe2faeff1d6e',
                    '367fcecbd5ccd47e',
                    '0af2ab276b350948',
                    'fb4ddbb02186c8f5'
                ],
                'controller-bb701f9106045d51' => [
                    '38ebec893018260f',
                    '008bb467fb6436f0',
                    '28b3c41c6235eea9',
                    'c3cc14385f0293d8',
                    '56f6db0ccd99c914'
                ],
                'controller-2fae3a4efe34b803' => [
                    '550a8dacdcee12f3',
                    '0bc502f2c475c811',
                    'e1c8cf49f438668c',
                    'aa00cbec75768ec9'
                ],
                'controller-fec08700f2af8413' => [
                    '8a244c37457907f6',
                    'feac3caa733783bd'
                ],
                'controller-01335606d5a5d365' => [
                    '48d3ca3e656e3566'
                ],
                'controller-ac28ebe832d23721' => [
                    'd59a5622ff031201',
                    'b00ef1ce449c970b',
                    '84470713dcb9a7c9',
                    '88e610dbb210a3dc'
                ],
                'controller-6351cb0893e9beeb' => [
                    '260134192d792203',
                    '80e08bf5dfe5b440',
                    'ec2cd321a60acaee'
                ]
            ],
            'module-f7d6ad66457d4adb' => [
                'controller-29ad9b2ec5c315dc' => [
                    '81a7090a4e00c848'
                ]
            ],
            'module-0ed177064f20b4a3' => [
                'controller-993f79ec16f5deb5' => [
                    '652c84b03adbd74c',
                    '5ca28ffefd824a06',
                    '87245788fdc15b7c',
                    '345e34b8c381fddf',
                    '1d2e70b9a4e70aee',
                    'dfa736a713cb5912',
                    '1eadb7dfdc8911ec'
                ]
            ]
        ]
    ],
    'mobi' => [
        'whitelist' => [
            'c41a4daab44bfd01',
            'b8596c602ce9318b',
            '3f326e7170416c8e',
            '893f7015c92d55f6',
            'fca1a653d0ebc08a',
            '8b182a3d251b771a'
        ],
        'actions' => []
    ]
];
