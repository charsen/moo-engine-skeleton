<?php

declare(strict_types=1);

use Mooeen\Scaffold\Testing\ComposerProfiles;
use Symfony\Component\Process\Process;

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

test('三份 Composer manifest 私包来源策略与依赖基线一致', function () {
    expect(ComposerProfiles::problems(dirname(__DIR__, 4)))->toBe([]);
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
