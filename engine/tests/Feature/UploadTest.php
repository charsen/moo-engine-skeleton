<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\TemporaryUploadPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mooeen\System\Models\Personnel;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_upload_requires_admin_token(): void
    {
        Storage::fake('public');

        $this->post('api/admin/upload/image?field=avatar', [
            'file' => UploadedFile::fake()->image('avatar.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_admin_can_upload_temporary_avatar_image(): void
    {
        Storage::fake('public');
        $token = $this->adminLogin();

        $response = $this->post('api/admin/upload/image?field=avatar', [
            'file' => UploadedFile::fake()->image('avatar.jpg', 64, 64),
        ], [
            'Accept'        => 'application/json',
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $path = $response->json('data.path');

        $this->assertSame('avatar', $response->json('data.field'));
        $this->assertIsString($path);
        $this->assertStringStartsWith('temp/images/', $path);
        Storage::disk('public')->assertExists($path);

        $personnel = Personnel::query()->where('mobile', '13800000000')->firstOrFail();
        $this->putJson('api/admin/me/avatar', ['avatar' => $response->json('data.value')], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $stored = $personnel->refresh()->avatar;
        $this->assertStringStartsWith('personnels/' . $personnel->id . '/', $stored);
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($stored);
    }

    public function test_upload_uses_server_detected_extension_instead_of_client_filename(): void
    {
        Storage::fake('public');
        $token = $this->adminLogin();

        $image    = UploadedFile::fake()->image('avatar.jpg', 64, 64);
        $response = $this->post('api/admin/upload/image?field=avatar', [
            'file' => new UploadedFile(
                $image->getPathname(),
                'avatar.php',
                'application/x-httpd-php',
                null,
                true,
            ),
        ], [
            'Accept'        => 'application/json',
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $this->assertStringEndsWith('.jpg', $response->json('data.path'));
    }

    public function test_upload_rejects_non_image_content(): void
    {
        Storage::fake('public');
        $token = $this->adminLogin();

        $this->post('api/admin/upload/image?field=avatar', [
            'file' => UploadedFile::fake()->createWithContent('avatar.jpg', 'not an image'),
        ], [
            'Accept'        => 'application/json',
            'Authorization' => "Bearer {$token}",
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_avatar_upload_route_has_a_dedicated_rate_limit(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route) => $route->uri() === 'api/admin/upload/image'
        );

        $this->assertNotNull($route);
        $this->assertContains('throttle:20,1', $route->gatherMiddleware());
    }

    public function test_pruner_only_deletes_expired_temporary_avatar_images(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('temp/images/expired.jpg', 'expired');
        Storage::disk('public')->put('temp/images/also-expired.jpg', 'also expired');
        Storage::disk('public')->put('temp/images/recent.jpg', 'recent');
        Storage::disk('public')->put('personnels/1/avatar.jpg', 'published');
        touch(Storage::disk('public')->path('temp/images/expired.jpg'), now()->subDays(2)->getTimestamp());
        touch(Storage::disk('public')->path('temp/images/also-expired.jpg'), now()->subDays(2)->getTimestamp());

        $deleted = app(TemporaryUploadPruner::class)->prune(
            'public',
            'temp/images',
            now()->subDay(),
            1,
        );

        $this->assertSame(1, $deleted);
        $this->assertCount(2, Storage::disk('public')->files('temp/images'));
        Storage::disk('public')->assertExists('temp/images/recent.jpg');
        Storage::disk('public')->assertExists('personnels/1/avatar.jpg');
    }
}
