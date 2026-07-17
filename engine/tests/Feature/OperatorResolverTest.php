<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Mooeen\Scaffold\Contracts\OperatorResolver;
use Tests\TestCase;

class OperatorResolverTest extends TestCase
{
    public function test_it_resolves_the_current_operator_from_the_active_host_guard(): void
    {
        $resolver = app(OperatorResolver::class);

        $this->assertNull($resolver->id());

        Auth::shouldReceive('id')->once()->andReturn(17);

        $this->assertSame(17, $resolver->id());
    }
}
