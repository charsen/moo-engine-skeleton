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
        // 已发布 tag 被上游强推重指：composer 缓存镜像跟着新 tag 走，vendor checkout 里的旧 tag 对象
        // 让 `git fetch --tags composer` 整段失败（2026-09-19 moo-upload 0.1.10 被重指的实况输出）
        ->and(classifyComposerFailure(<<<'OUTPUT'
        In Git.php line 602:

          Failed to execute git fetch --tags composer

          From /root/.cache/composer/vcs/git-gitee.com-charsen-moo-upload
           ! [rejected]        0.1.10     -> 0.1.10  (would clobber existing tag)

        update [--with WITH] [--prefer-source] [--prefer-dist]
        OUTPUT))
        ->toBe('tag-clobber')
        ->and($pull)
        ->toContain('case "$(composer_failure_kind "$rescue_install_out")" in')
        ->toContain('explain_private_vendor_purge()')
        ->not->toContain('*"with-all-dependencies"*')
        ->toContain('COMPOSER_MAX_PARALLEL_PROCESSES=1 COMPOSER_MAX_PARALLEL_HTTP=1');

    // 两处 vendor 救援 + 首轮 profile + 主 update，四个分派点都必须认这个分类（漏一处就退回「失败即停」）；
    // 同时不允许残留只认 no-merge-base 的旧分派行。
    expect(substr_count($pull, 'no-merge-base|tag-clobber)'))->toBe(4)
        ->and(substr_count($pull, 'no-merge-base)'))->toBe(0);
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
