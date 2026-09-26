<?php declare(strict_types=1);

/** Host 真实容器绑定与组织控件：多岗位在职候选、历史回显、表单参数。 */

use App\Admin\Controllers\Traits\BaseActionTrait;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mooeen\System\Contracts\OrgOptions;
use Mooeen\System\Models\Department;
use Mooeen\System\Models\Enums\DepartmentType;
use Mooeen\System\Models\Enums\StaffStatus;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 暴露 BaseActionTrait 私有方法的测试宿主（模拟真实 controller 的调用点）。
 */
function orgTraitHarness(): object
{
    return new class
    {
        use BaseActionTrait;

        public function callPersonnelCascader(...$args): array
        {
            return $this->getPersonnelCascader(...$args);
        }

        public function callPositionCascader(...$args): array
        {
            return $this->getPositionCascader(...$args);
        }
    };
}

beforeEach(function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');

    DB::purge('sqlite');
    DB::reconnect('sqlite');

    Schema::dropIfExists('system_departments');
    Schema::dropIfExists('system_personnels');
    Schema::dropIfExists('system_positions');
    Schema::dropIfExists('system_personnel_position');

    Schema::create('system_departments', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('parent_id')->nullable();
        $table->integer('_lft')->default(0);
        $table->integer('_rgt')->default(0);
        $table->string('department_code')->nullable();
        $table->integer('department_type')->default(DepartmentType::TEAM->value);
        $table->string('department_name');
        $table->string('department_abbreviation')->nullable();
        $table->string('header_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    Schema::create('system_personnels', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('department_id')->nullable();
        $table->string('position_id')->nullable();
        $table->string('real_name');
        $table->string('avatar')->nullable();
        $table->integer('staff_status')->default(StaffStatus::ON_JOB->value);
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    Schema::create('system_positions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->text('department_ids')->nullable();
        $table->string('position_code')->nullable();
        $table->string('position_name');
        $table->integer('position_status')->default(1);
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });

    Schema::create('system_personnel_position', function (Blueprint $table) {
        $table->string('personnel_id');
        $table->string('department_id');
        $table->string('position_id')->nullable();
    });

    // 部门树（嵌套集）：
    //   10 root(1,8)
    //     ├ 20 A(2,5)
    //     │   └ 30 grandchild(3,4)
    //     └ 40 B(6,7)
    DB::table('system_departments')->insert([
        ['id' => '10', 'parent_id' => null, '_lft' => 1, '_rgt' => 8, 'department_type' => DepartmentType::HEAD_OFFICE->value, 'department_name' => '总公司'],
        ['id' => '20', 'parent_id' => '10', '_lft' => 2, '_rgt' => 5, 'department_type' => DepartmentType::DEPARTMENT_OFFICE->value, 'department_name' => '甲部门'],
        ['id' => '30', 'parent_id' => '20', '_lft' => 3, '_rgt' => 4, 'department_type' => DepartmentType::TEAM->value, 'department_name' => '甲小组'],
        ['id' => '40', 'parent_id' => '10', '_lft' => 6, '_rgt' => 7, 'department_type' => DepartmentType::DEPARTMENT_OFFICE->value, 'department_name' => '乙部门'],
    ]);

    // 人员：李四 STORED（在册，本仓 ON_JOB 过滤下应缺席）、王五 LEAVE_OFFICE（离职应缺席）
    DB::table('system_personnels')->insert([
        ['id' => '1', 'department_id' => '20', 'real_name' => '超管', 'staff_status' => StaffStatus::ON_JOB->value],
        ['id' => '100', 'department_id' => '20', 'real_name' => '张三', 'staff_status' => StaffStatus::ON_JOB->value],
        ['id' => '101', 'department_id' => '30', 'real_name' => '李四', 'staff_status' => StaffStatus::STORED->value],
        ['id' => '102', 'department_id' => '40', 'real_name' => '王五', 'staff_status' => StaffStatus::LEAVE_OFFICE->value],
        ['id' => '103', 'department_id' => '40', 'real_name' => '赵六', 'staff_status' => StaffStatus::ON_JOB->value],
    ]);

    DB::table('system_personnel_position')->insert([
        ['personnel_id' => '1', 'department_id' => '20', 'position_id' => '200'],
        ['personnel_id' => '100', 'department_id' => '20', 'position_id' => '200'],
        ['personnel_id' => '100', 'department_id' => '40', 'position_id' => '201'],
        ['personnel_id' => '100', 'department_id' => '40', 'position_id' => '202'],
        ['personnel_id' => '101', 'department_id' => '30', 'position_id' => '201'],
        ['personnel_id' => '102', 'department_id' => '40', 'position_id' => '201'],
        ['personnel_id' => '103', 'department_id' => '40', 'position_id' => '201'],
    ]);

    DB::table('system_positions')->insert([
        ['id' => '200', 'department_ids' => json_encode(['20']), 'position_name' => '岗位甲', 'position_status' => 1],
        ['id' => '201', 'department_ids' => json_encode(['30', '40']), 'position_name' => '岗位乙', 'position_status' => 1],
    ]);
});

afterEach(function () {
    Schema::dropIfExists('system_departments');
    Schema::dropIfExists('system_personnels');
    Schema::dropIfExists('system_positions');
    Schema::dropIfExists('system_personnel_position');
});

test('personnelCascader 输出与 trait 委托逐字节一致，且仅挂在职人员', function () {
    $service = app(OrgOptions::class)->personnelCascader();
    $trait   = orgTraitHarness()->callPersonnelCascader();

    // 等价性：Service === trait 委托
    expect($service)->toBe($trait);

    // cascader 键形态
    expect($service)->toHaveKeys(['type', 'multiple', 'strictly', 'options', 'array', 'filter'])
        ->and($service['type'])->toBe('cascader')
        ->and($service['options'])->toBeArray()
        ->and($service['filter'])->toBe(['label-value', 'id', 'department_name', '', ['personnels', 'id', 'real_name', '@']]);

    // staff_status 过滤面：本仓仅 ON_JOB（张三/赵六在场；在册李四、离职王五缺席）
    $json = json_encode($service, JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('超管')
        ->and($json)->toContain('张三')
        ->and($json)->toContain('赵六')
        ->and($json)->not->toContain('李四')
        ->and($json)->not->toContain('王五');
});

test('personnelCascader 透传布尔参数与 trait 委托一致', function () {
    $service = app(OrgOptions::class)->personnelCascader(null, true, false, true);
    $trait   = orgTraitHarness()->callPersonnelCascader(null, true, false, true);

    expect($service)->toBe($trait)
        ->and($service['multiple'])->toBeTrue()
        ->and($service['array'])->toBeFalse()
        ->and($service['strictly'])->toBeTrue();
});

test('positionCascader 输出与 trait 委托逐字节一致，且键形态正确', function () {
    $service = app(OrgOptions::class)->positionCascader(false, false);
    $trait   = orgTraitHarness()->callPositionCascader();

    // options 内嵌活 Position 模型对象（经 $item->positions 属性赋值），toArray 不深展开 →
    // 用真正上线的 JSON 序列化形态做等价断言（assertSame 会因对象实例不同而误判）。
    expect(json_encode($service, JSON_UNESCAPED_UNICODE))->toBe(json_encode($trait, JSON_UNESCAPED_UNICODE));

    expect($service)->toHaveKeys(['type', 'multiple', 'strictly', 'options', 'array', 'filter'])
        ->and($service['type'])->toBe('cascader')
        ->and($service['filter'])->toBe(['label-value', 'id', 'display_name', '', ['positions', 'id', 'position_name']]);

    $json = json_encode($service, JSON_UNESCAPED_UNICODE);
    expect($json)->toContain('岗位甲')
        ->and($json)->toContain('岗位乙');
});

test('positionCascader 透传布尔参数与 trait 委托一致', function () {
    $service = app(OrgOptions::class)->positionCascader(true, true, true);
    $trait   = orgTraitHarness()->callPositionCascader(true, true, true);

    expect(json_encode($service, JSON_UNESCAPED_UNICODE))->toBe(json_encode($trait, JSON_UNESCAPED_UNICODE))
        ->and($service['multiple'])->toBeTrue()
        ->and($service['array'])->toBeTrue()
        ->and($service['strictly'])->toBeTrue();
});

test('同人跨部门保留叶子，同部门多岗位去重，空部门保留', function () {
    $options  = orgTraitHarness()->callPersonnelCascader()['options'];
    $children = collect($options[0]['children'])->keyBy('id');

    expect(array_column($children['20']['personnels'], 'id'))->toBe(['100'])
        ->and(array_column($children['40']['personnels'], 'id'))->toBe(['100', '103'])
        ->and($children['20']['children'][0]['personnels'])->toBe([]);
});

test('显式部门集合保留历史回显和裁剪森林', function () {
    $departments = Department::whereKey('30')->with('personnels')->get();
    $widget      = orgTraitHarness()->callPersonnelCascader($departments);

    expect((string) $widget['options'][0]['id'])->toBe('30')
        ->and($widget['options'][0]['personnels'][0]['real_name'])->toBe('李四');
});

test('Host 控件尊重公共 OrgOptions 绑定覆盖', function () {
    $options = Mockery::mock(OrgOptions::class);
    $options->shouldReceive('personnelCascader')->once()->with(null, true, false, true)->andReturn(['options' => ['custom']]);
    app()->instance(OrgOptions::class, $options);

    expect(orgTraitHarness()->callPersonnelCascader(null, true, false, true))->toBe(['options' => ['custom']]);
});
