<?php

declare(strict_types=1);

/*
 * 应用命令与定时任务注册（bootstrap/app.php 的 withRouting(commands: ...) 加载本文件）。
 *
 * 查看已注册任务：php artisan schedule:list
 * 生产跑调度：crontab 里挂一条 `* * * * * php artisan schedule:run >> /dev/null 2>&1`。
 *
 * 全量约定：每条 Schedule 都挂 withoutOverlapping()——上一轮没跑完时跳过本轮，
 * 防慢任务堆叠把 worker/DB 压垮（幂等性再好也别省这一句）。
 */

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── 队列自愈 ─────────────────────────────────────────────────────────────
// 把失败队列每 10 分钟整体重试一次（瞬时故障——网络抖动、第三方 5xx——自动恢复，
// 不必人肉 queue:retry）。真正的死信在多次重试后仍留在 failed_jobs，人工排查。
Schedule::command('queue:retry all')->everyTenMinutes()->withoutOverlapping();

// ── 每日备份挂载位 ───────────────────────────────────────────────────────
// 备份 Job 属于 P3 运维资产（backup.sh / BackupSQLJob 尚未入库）。就位后按下述挂载，
// 错开高峰、分两个时段各备一次：
// Schedule::job(new \App\Jobs\BackupSQLJob)->dailyAt('03:30')->withoutOverlapping();
// Schedule::job(new \App\Jobs\BackupSQLJob)->dailyAt('21:30')->withoutOverlapping();

// ── 探表守卫示范：从 DB 表动态注册调度 ───────────────────────────────────
// 若要按某张业务表里的行/cron 表达式动态注册任务，注册前必须 Schema::hasTable() 探一下——
// 本文件在【每次 artisan 启动】都被求值（含 console 内核），裸查表会在三种偶发路径上抛
// QueryException 让所有 artisan 命令崩掉：
//   ① 测试用 sqlite :memory:，进程起来时还没 migrate；
//   ② 新部署首次 `artisan migrate` 之前，表还不存在；
//   ③ DB 临时不可达（重启/网络）时。
// hasTable() 只能处理「连接正常但表不存在」；数据库文件尚未创建或服务不可达时它自己也会抛错。
// 因此探表必须再包一层异常兜底，确保 composer package:discover / 首次 artisan 命令不会瘫痪。
//
// 示范（把 'schedule_rules' 换成你的表；此表不存在时下面整段静默跳过）：
$hasScheduleRulesTable = false;
try {
    $hasScheduleRulesTable = Schema::hasTable('schedule_rules');
} catch (\Throwable) {
    // 首次安装、测试内存库尚未迁移、数据库临时不可达：本轮不注册动态调度。
}

if ($hasScheduleRulesTable) {
    // foreach (\App\Models\ScheduleRule::query()->where('enabled', true)->get() as $rule) {
    //     Schedule::call(fn () => /* dispatch your job */ null)
    //         ->cron($rule->cron)
    //         ->name('schedule-rule-'.$rule->id) // 命名后 schedule:list 可读、withoutOverlapping 锁按名区分
    //         ->withoutOverlapping();
    // }
}

// ── 云端监控推送 ─────────────────────────────────────────────────────────
// moo-monitor-laravel 在 cloud.enabled + cloud.schedule 同为真时【自动】挂每分钟推送调度
// （见 config/moo-monitor.php），此处无需手动注册。
