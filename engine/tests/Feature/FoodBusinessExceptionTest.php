<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Admin\Controllers\Food\FoodController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FoodBusinessExceptionTest extends TestCase
{
    public function test_batch_actions_return_business_errors_without_form_fields(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['scaffold.authorization.check' => false]);
        $this->withoutMiddleware();
        Schema::create((new \App\Models\Food\Food)->getTable(), static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->softDeletes();
        });
        foreach (['destroyBatch', 'restore'] as $action) {
            $route = collect(app('router')->getRoutes()->getRoutes())->first(
                static fn ($route): bool => $route->getActionName() === FoodController::class . '@' . $action,
            );
            $this->assertNotNull($route);
            foreach ([[], ['ids' => ['999']]] as $payload) {
                $response = $this->json($route->methods()[0], '/' . $route->uri(), $payload)
                    ->assertStatus(522)->assertJsonPath('ok', false);
                $this->assertNotEmpty($response->json('error.msg'));
                $this->assertArrayNotHasKey('errors', $response->json());
            }
        }
    }
}
