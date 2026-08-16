<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MooPackageHostContractTest extends TestCase
{
    public function test_family_packages_are_wired_across_host_surfaces_without_richtext(): void
    {
        $engine      = dirname(__DIR__, 2);
        $development = $this->manifest($engine . '/composer.json');
        $production  = $this->manifest($engine . '/composer.production.json');

        foreach ([$development, $production] as $manifest) {
            self::assertArrayHasKey('charsen/moo-system', $manifest['require']);
            self::assertArrayHasKey('charsen/moo-upload', $manifest['require']);
            self::assertArrayHasKey('charsen/moo-feedback', $manifest['require']);
            self::assertArrayNotHasKey('charsen/moo-richtext', $manifest['require']);
        }

        foreach ([$development, $production] as $manifest) {
            $privatePackages = collect($manifest['extra']['moo-private-packages'])
                ->keyBy('name');

            self::assertSame('system', $privatePackages['charsen/moo-system']['repo-key']);
            self::assertSame('upload', $privatePackages['charsen/moo-upload']['repo-key']);
            self::assertArrayHasKey('system', $manifest['repositories']);
            self::assertArrayHasKey('upload', $manifest['repositories']);
            self::assertArrayNotHasKey('charsen/moo-feedback', $privatePackages);
        }

        $providers = require $engine . '/bootstrap/providers.php';
        self::assertContains(\App\Moo\Scaffold\ScaffoldServiceProvider::class, $providers);
        self::assertContains(\App\Moo\Feedback\FeedbackServiceProvider::class, $providers);

        $scaffold = require $engine . '/config/scaffold.php';
        self::assertSame([
            'System'   => 'Mooeen\\System\\Http\\Controllers\\Admin',
            'Upload'   => 'Mooeen\\Upload\\Http\\Controllers\\Admin',
            'Feedback' => 'Mooeen\\Feedback\\Http\\Controllers\\Admin',
        ], $scaffold['controller']['admin']['extra_modules']);
    }

    /** @return array<string, mixed> */
    private function manifest(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
