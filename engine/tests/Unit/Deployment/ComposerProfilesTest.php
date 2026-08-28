<?php

declare(strict_types=1);

function deploymentComposerProfile(string $file): array
{
    return json_decode(
        file_get_contents(dirname(__DIR__, 3) . '/' . $file),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

function withoutManifestPackages(array $requires, array $names): array
{
    return array_diff_key($requires, array_fill_keys($names, true));
}

test('测试服 Composer 配置只在 manifest 私包版本约束上分流', function () {
    $production = deploymentComposerProfile('composer.production.json');
    $test       = deploymentComposerProfile('composer.test.json');
    $manifest   = $production['extra']['moo-private-packages'];
    $names      = array_column($manifest, 'name');

    $productionCommon = $production;
    $testCommon       = $test;
    unset($productionCommon['require'], $testCommon['require']);

    expect($testCommon)->toBe($productionCommon)
        ->and($test['extra']['moo-private-packages'])->toBe($manifest)
        ->and(withoutManifestPackages($test['require'], $names))
        ->toBe(withoutManifestPackages($production['require'], $names));

    foreach ($manifest as $package) {
        $name    = $package['name'];
        $repoKey = $package['repo-key'];

        expect($production['require'])->toHaveKey($name)
            ->and($test['require'][$name])->toMatch('/^dev-dev as \\d+\\.\\d+\\.99$/')
            ->and($test['repositories'][$repoKey]['type'])->toBe('vcs')
            ->and($test['repositories'][$repoKey]['url'])
            ->toBe($production['repositories'][$repoKey]['url']);
    }
});

test('测试部署入口只选择 profile 并复用 pull 主体', function () {
    $repository = dirname(__DIR__, 4);
    $script     = file_get_contents($repository . '/test-pull.sh');
    $pull       = file_get_contents($repository . '/pull.sh');

    expect($script)
        ->toContain('DEPLOY_COMPOSER_PROFILE=test')
        ->toContain('DEPLOY_BRANCH=dev')
        ->toContain('exec sh "$SCRIPT_DIR/pull.sh" --latest "$@"')
        ->not->toContain('composer update')
        ->not->toContain('php artisan migrate')
        ->and($pull)
        ->toContain('DEPLOY_COMPOSER_JSON="$ENGINE_DIR/composer.test.json"')
        ->toContain('DEPLOY_COMPOSER_LOCK="composer.test.lock"')
        ->toContain('pkg_ref="refs/heads/dev"')
        ->toContain('export COMPOSER="composer.test.json"')
        ->toContain('unset COMPOSER')
        ->toContain('env COMPOSER=composer.test.json composer')
        ->toContain('if [ "$DEPLOY_COMPOSER_PROFILE" = "test" ] && [ ! -f "$DEPLOY_COMPOSER_LOCK" ]; then');
});

test('骨架初始化与发布门禁同时维护测试 profile', function () {
    $repository   = dirname(__DIR__, 4);
    $initializer  = file_get_contents($repository . '/tools/init-project.php');
    $releaseCheck = file_get_contents($repository . '/release-check.sh');

    expect($initializer)
        ->toContain("replaceComposerIdentity(\$engine . '/composer.test.json'")
        ->toContain('COMPOSER=composer.test.json')
        ->and($releaseCheck)
        ->toContain('test-pull.sh')
        ->toContain('COMPOSER=composer.test.json composer validate');
});
