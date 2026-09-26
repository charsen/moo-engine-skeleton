<?php declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mooeen\Contract\OrgDirectory;
use Mooeen\Contract\PersonnelNameResolver;
use Mooeen\System\Contracts\OrgOptions;
use Mooeen\System\Models\Enums\StaffStatus;
use Mooeen\System\Support\EloquentOrgDirectory;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Schema::create('system_personnels', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('real_name');
        $table->integer('staff_status');
        $table->timestamp('deleted_at')->nullable();
    });
    DB::table('system_personnels')->insert([
        ['id' => '71', 'real_name' => '当前人员', 'staff_status' => StaffStatus::ON_JOB->value, 'deleted_at' => null],
        ['id' => '72', 'real_name' => '历史人员', 'staff_status' => StaffStatus::LEAVE_OFFICE->value, 'deleted_at' => '2026-01-01 00:00:00'],
    ]);
});

afterEach(function () {
    Schema::dropIfExists('system_personnels');
});

test('真实 Host 自动发现 System 并提供公共组织姓名与选项绑定', function () {
    expect(app(OrgDirectory::class))->toBeInstanceOf(EloquentOrgDirectory::class)
        ->and(app(PersonnelNameResolver::class))->toBeInstanceOf(EloquentOrgDirectory::class)
        ->and(app(OrgOptions::class))->toBeInstanceOf(\Mooeen\System\Support\OrgOptions::class);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $names = app(PersonnelNameResolver::class)->resolveNames(['71', '72', '71', '999']);
    expect($names)->toBe([71 => '当前人员', 72 => '历史人员'])
        ->and(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
});

test('Host 可以覆盖公共姓名实现且不会被 System 重新注册覆盖', function () {
    $resolver = Mockery::mock(PersonnelNameResolver::class);
    $resolver->shouldReceive('resolveNames')->once()->with(['71'])->andReturn([71 => '外部目录']);
    app()->instance(PersonnelNameResolver::class, $resolver);
    (new \Mooeen\System\MooeenSystemServiceProvider(app()))->register();

    expect(app(PersonnelNameResolver::class)->resolveNames(['71']))->toBe([71 => '外部目录']);
});
