<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Mooeen\Feedback\Models\Feedback;
use Tests\TestCase;

class FeedbackExampleTest extends TestCase
{
    use RefreshDatabase;

    // 测试套件的其它 Feature 用例依赖同一份教学 seed；本类若先迁移但不 seed，
    // RefreshDatabase 的进程级迁移缓存会让后续类看到空库。
    protected $seed = true;

    public function test_feedback_admin_routes_use_their_own_complete_authentication_group(): void
    {
        $groups = $this->app->make('router')->getMiddlewareGroups();

        self::assertSame('moo-feedback', config('moo-feedback.admin.middleware'));
        self::assertSame([
            'jwt.assign.guard:admin',
            'jwt.guard.auth:admin',
            'jwt.auth.refresh',
            'throttle:admin',
            'set.locale',
            SubstituteBindings::class,
            \App\Http\Middleware\OperationLog::class,
        ], $groups['moo-feedback'] ?? null);

        $this->getJson('/api/admin/feedbacks')->assertUnauthorized();
    }

    public function test_public_feedback_example_exposes_host_categories_and_accepts_a_submission(): void
    {
        $this->getJson('/api/feedbacks/meta')
            ->assertOk()
            ->assertJsonPath('types.0.key', 'BUG')
            ->assertJsonPath('types.1.key', 'SUGGESTION');

        $this->postJson('/api/feedbacks', [
            'feedback_type'         => 'SUGGESTION',
            'feedback_content'      => '希望后续增加批量导出功能。',
            'feedback_contact_name' => '示例用户',
            'feedback_email'        => 'demo@example.test',
        ])->assertCreated()->assertExactJson(['submitted' => true]);

        self::assertSame(1, Feedback::query()->roots()->count());
        self::assertFalse((bool) Feedback::query()->firstOrFail()->feedback_ip);
    }

    public function test_feedback_admin_actions_are_present_in_the_generated_acl_tree(): void
    {
        $keys = collect(config('actions.admin.actions'))
            ->flatMap(static fn (array $controllers): array => $controllers)
            ->flatMap(static fn (array $actions): array => $actions)
            ->values()
            ->all();

        foreach ([
            '652c84b03adbd74c', // show
            '5ca28ffefd824a06', // index
            '87245788fdc15b7c', // trashed / restore
            '345e34b8c381fddf', // destroyBatch
            '1d2e70b9a4e70aee', // forceDestroy
            'dfa736a713cb5912', // reply
            '1eadb7dfdc8911ec', // transition
        ] as $key) {
            self::assertContains($key, $keys);
        }
    }
}
