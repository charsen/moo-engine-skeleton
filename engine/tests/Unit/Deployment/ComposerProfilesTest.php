<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

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

function privateDevelopmentVersion(string $name): string
{
    return match ($name) {
        'charsen/moo-scaffold'                           => '2.x-dev',
        'charsen/moo-system'                             => '1.6.x-dev',
        'charsen/moo-attachment', 'charsen/moo-richtext' => '0.2.x-dev',
        default                                          => '0.1.x-dev',
    };
}

function localPrivateConstraint(string $name): string
{
    return match ($name) {
        'charsen/moo-scaffold'                           => '^2.1@dev',
        'charsen/moo-system'                             => '^1.6@dev',
        'charsen/moo-attachment', 'charsen/moo-richtext' => '^0.2@dev',
        default                                          => '^0.1@dev',
    };
}

function classifyComposerFailure(string $output): string
{
    $repository = dirname(__DIR__, 4);
    $process    = new Process([
        'sh',
        '-c',
        '. "$1"; composer_failure_kind "$2"',
        'composer-classifier',
        $repository . '/tools/_common.sh',
        $output,
    ]);

    $process->mustRun();

    return $process->getOutput();
}

test('三套 Composer profile 对 manifest 私包使用互斥且完整的来源策略', function () {
    $local      = deploymentComposerProfile('composer.json');
    $production = deploymentComposerProfile('composer.production.json');
    $test       = deploymentComposerProfile('composer.test.json');
    $manifest   = $production['extra']['moo-private-packages'];
    $names      = array_column($manifest, 'name');

    $productionCommon = $production;
    $testCommon       = $test;
    unset($productionCommon['require'], $testCommon['require']);

    expect($testCommon)->toBe($productionCommon)
        ->and($local['extra']['moo-private-packages'])->toBe($manifest)
        ->and($test['extra']['moo-private-packages'])->toBe($manifest)
        ->and(withoutManifestPackages($test['require'], $names))
        ->toBe(withoutManifestPackages($production['require'], $names));

    foreach ($manifest as $package) {
        $name             = $package['name'];
        $repoKey          = $package['repo-key'];
        $packageDirectory = substr($name, strlen('charsen/'));

        expect($local['require'][$name])->toBe(localPrivateConstraint($name))
            ->and($local['repositories'][$repoKey]['type'])->toBe('path')
            ->and($local['repositories'][$repoKey]['url'])->toBe('../../' . $packageDirectory)
            ->and($local['repositories'][$repoKey]['options']['symlink'])->toBeTrue()
            ->and($local['repositories'][$repoKey]['options']['versions'][$name])
            ->toBe(privateDevelopmentVersion($name))
            ->and($test['require'][$name])->toBe('dev-dev')
            ->and($test['repositories'][$repoKey]['type'])->toBe('vcs')
            ->and($production['repositories'][$repoKey]['type'])->toBe('vcs')
            ->and($test['repositories'][$repoKey]['url'])
            ->toBe($production['repositories'][$repoKey]['url'])
            ->and($production['require'][$name])->not->toContain('dev')
            ->and($production['require'][$name])->not->toContain('@')
            ->and($production['require'][$name])->not->toContain(' as ');
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

test('Composer 失败分类不会把通用命令帮助误判成依赖冲突', function () {
    $repository = dirname(__DIR__, 4);
    $pull       = file_get_contents($repository . '/pull.sh');

    expect(classifyComposerFailure("Could not delete /srv/app/vendor/composer/abc/Monolog\nupdate [--with-all-dependencies]"))
        ->toBe('vendor-filesystem')
        ->and(classifyComposerFailure('DirectoryNotFoundException: /srv/app/vendor/composer/abc does not exist'))
        ->toBe('vendor-filesystem')
        ->and(classifyComposerFailure('Use the option --with-all-dependencies (-W) to allow upgrades'))
        ->toBe('dependency-lock')
        ->and(classifyComposerFailure('installation was aborted by another package operation'))
        ->toBe('other')
        ->and(classifyComposerFailure("network failed\nupdate [--with-all-dependencies]"))
        ->toBe('other')
        ->and(classifyComposerFailure('fatal: no merge base'))
        ->toBe('no-merge-base')
        ->and($pull)
        ->toContain('case "$(composer_failure_kind "$rescue_install_out")" in')
        ->not->toContain('*"with-all-dependencies"*')
        ->toContain('COMPOSER_MAX_PARALLEL_PROCESSES=1 COMPOSER_MAX_PARALLEL_HTTP=1');
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

test('三份 manifest 的非 Moo 运行时依赖基线一致', function () {
    $local      = deploymentComposerProfile('composer.json');
    $test       = deploymentComposerProfile('composer.test.json');
    $production = deploymentComposerProfile('composer.production.json');

    // 只比非 Moo 键：公开包在本地合法使用 @dev 约束、生产用稳定约束；require-dev 允许本地多开发工具。
    $nonMoo = function (array $profile): array {
        $names = array_values(array_filter(
            array_keys($profile['require']),
            static fn (string $name): bool => ! str_starts_with($name, 'charsen/')
        ));
        sort($names);

        return $names;
    };

    expect($nonMoo($local))->toBe($nonMoo($test))
        ->and($nonMoo($local))->toBe($nonMoo($production));
});
