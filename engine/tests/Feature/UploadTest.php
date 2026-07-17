<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $this->assertStringStartsWith('tmp/images/', $path);
        Storage::disk('public')->assertExists($path);
    }
}
