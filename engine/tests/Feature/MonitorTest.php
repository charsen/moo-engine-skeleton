<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mooeen\Monitor\ExceptionDispatcher;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * 监控采集测试（第 1.7 节接入的 moo-monitor-laravel）
 *
 * 验证运行时异常自动记录到本地缓冲 storage/moo-monitor/runtimes/open/。
 */
class MonitorTest extends TestCase
{
    use RefreshDatabase;

    private string $runtimeBase;

    protected function setUp(): void
    {
        parent::setUp();

        // 用测试专用目录，不得清空开发者真实的 storage/moo-monitor/runtimes/open。
        $this->runtimeBase = storage_path('framework/testing/moo-monitor-' . getmypid());
        File::deleteDirectory($this->runtimeBase);
        File::ensureDirectoryExists($this->runtimeBase . '/open');

        config(['moo-monitor.runtime.path' => $this->runtimeBase]);
        $this->app->forgetInstance(RuntimeErrorRecorder::class);
        $this->app->forgetInstance(ExceptionDispatcher::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runtimeBase);

        parent::tearDown();
    }

    /** 测试异常被自动记录到本地缓冲 */
    #[Test]
    public function runtime_exception_is_recorded_to_local_buffer(): void
    {
        // 断言：监控目录一开始是空的
        $runtimesDir = $this->runtimeBase . '/open';
        $this->assertEmpty(glob("$runtimesDir/*.yaml"), 'runtimes/open 目录应该为空');

        // 通过 HTTP 请求触发异常（CLI 下会被 cli_experiment_skip 跳过）
        // 定义一个测试路由
        \Illuminate\Support\Facades\Route::get('/test-monitor-http-exception', function () {
            throw new RuntimeException('MonitorTest HTTP: 故意抛出的测试异常 ' . time());
        });

        // 发起请求，预期 500 但异常被记录
        $response = $this->get('/test-monitor-http-exception');
        $response->assertStatus(500);

        // 断言：storage/moo-monitor/runtimes/open/ 下出现了至少 1 个 .yaml 文件
        $files = glob("$runtimesDir/*.yaml");
        $this->assertNotEmpty($files, '监控应该记录至少 1 个异常到 runtimes/open/');

        // 读取最新的一个记录，验证字段结构
        $latestFile = end($files);
        $this->assertFileExists($latestFile);

        $content = file_get_contents($latestFile);
        $this->assertStringContainsString('hash:', $content, 'yaml 应含 hash 字段');
        $this->assertStringContainsString('status: open', $content, 'yaml 应含 status: open');
        $this->assertStringContainsString('exception:', $content, 'yaml 应含 exception 块');
        $this->assertStringContainsString('MonitorTest HTTP', $content, 'yaml 应含测试异常的标识');
    }

    /** 测试 BaseException 不被记录（在 dontReport 列表里） */
    #[Test]
    public function base_exception_is_not_reported(): void
    {
        $runtimesDir = $this->runtimeBase . '/open';
        $beforeCount = count(glob("$runtimesDir/*.yaml"));

        // BaseException（moo-scaffold 的业务异常）在 bootstrap/app.php 的 dontReport 列表里
        $baseException = new \Mooeen\Scaffold\Exceptions\BaseException('业务异常不应被监控记录', 522);
        app('Illuminate\Contracts\Debug\ExceptionHandler')->report($baseException);

        $afterCount = count(glob("$runtimesDir/*.yaml"));
        $this->assertEquals($beforeCount, $afterCount, 'BaseException 不应被记录到监控');
    }
}
