<?php

declare(strict_types=1);

/*
 * Pest 引导（2-2：测试框架从 PHPUnit 收敛到 Pest）。
 *
 * 现有测试类都显式 extends Tests\TestCase，保留原类风格即可（Pest 兼容跑 PHPUnit 风格类）——
 * 迁移只换 runner，不重写用例。下面这行让 Feature 目录里【函数式】Pest 用例也自动绑到
 * Tests\TestCase（继承 adminLogin/freshJwtProcess/makeExpiredToken 等 JWT 脚手架）。
 *
 * 测试环境变量（JWT_SECRET、sqlite :memory: 等）仍由 phpunit.xml 注入，Pest 读同一份配置。
 */

pest()->extend(Tests\TestCase::class)->in('Feature');
