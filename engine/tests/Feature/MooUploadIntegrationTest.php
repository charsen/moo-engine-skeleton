<?php declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mooeen\System\Contracts\PersonnelAvatarManager;
use Mooeen\System\Models\Personnel;
use Mooeen\System\Support\MooUploadPersonnelAvatarManager;
use Mooeen\Upload\Models\UploadIntent;
use Mooeen\Upload\Support\UploadPurposeRegistry;
use Tests\TestCase;

class MooUploadIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_upload_route_uses_its_own_authenticated_middleware_group(): void
    {
        $expected = [
            'jwt.assign.guard:admin',
            'jwt.guard.auth:admin',
            'jwt.auth.refresh',
            'throttle:admin',
            'set.locale',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\OperationLog::class,
        ];

        $this->assertSame('moo-upload', config('moo-upload.admin.middleware'));
        $this->assertSame($expected, app('router')->getMiddlewareGroups()['moo-upload']);
        $this->assertContains('81a7090a4e00c848', config('actions.admin.actions.module-f7d6ad66457d4adb.controller-29ad9b2ec5c315dc'));
        $this->postJson('/api/admin/uploads?purpose=moo-system.personnel.avatar')->assertUnauthorized();
    }

    public function test_personnel_avatar_consumes_a_moo_upload_reference(): void
    {
        Storage::fake('public');
        $token     = $this->adminLogin();
        $personnel = Personnel::query()->where('mobile', '13800000000')->firstOrFail();

        $reference = $this->post('api/admin/uploads?purpose=moo-system.personnel.avatar', [
            'file' => UploadedFile::fake()->image('avatar.jpg', 320, 320),
        ], [
            'Accept'        => 'application/json',
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->json('data.value');

        $this->putJson('api/admin/me/avatar', ['avatar' => $reference], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $path = $personnel->refresh()->avatar;
        $this->assertInstanceOf(MooUploadPersonnelAvatarManager::class, app(PersonnelAvatarManager::class));
        $this->assertSame('personnels/avatars', app(UploadPurposeRegistry::class)
            ->get('moo-system.personnel.avatar')->directory);
        $this->assertStringStartsWith('personnels/avatars/' . $personnel->id . '_', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame(1, UploadIntent::query()->whereNotNull('upload_intent_consumed_at')->count());
    }
}
