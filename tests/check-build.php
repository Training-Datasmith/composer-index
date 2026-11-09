<?php

declare(strict_types=1);

/**
 * Offline regression checks for build.php (no network).
 */

$repo_root = dirname(__DIR__);
$build_script = $repo_root . '/build.php';
$failures = [];

if (!is_file($build_script)) {
    fwrite(STDERR, "build.php not found\n");
    exit(1);
}

$tmp_root = sys_get_temp_dir() . '/composer-index-check-' . bin2hex(random_bytes(4));
if (!mkdir($tmp_root, 0777, true) && !is_dir($tmp_root)) {
    fwrite(STDERR, "Cannot create temp directory\n");
    exit(1);
}

register_shutdown_function(static function () use ($tmp_root): void {
    remove_tree($tmp_root);
});

try {
    $fixture_repo = $tmp_root . '/fixture.git';
    init_fixture_repo($fixture_repo);

    $manifest_dir = $tmp_root . '/manifests';
    mkdir($manifest_dir);
    $manifest_path = $manifest_dir . '/fixture.json';
    write_json($manifest_path, [
        'repos' => [
            ['name' => 'alpha', 'branch' => 'remote-test-2026-10-07'],
        ],
    ]);

    $cache_dir = $tmp_root . '/cache';
    $output = $tmp_root . '/packages-out.json';
    $remotes_dir = $tmp_root . '/remotes';
    mkdir($remotes_dir);
    symlink($fixture_repo, $remotes_dir . '/alpha.git');
    $origin = $remotes_dir . '/{name}.git';

    // 1) Build branch B while worktree is dirty on branch A.
    run_build_ok([
        'build' => $build_script,
        'manifest' => $manifest_path,
        'output' => $output,
        'origin' => $origin,
        'cache' => $cache_dir,
        'fresh' => true,
        'branch_override' => null,
        'dirty' => false,
        'build_branch' => 'remote-test-2026-11-08',
        'manifest_branch' => 'remote-test-2026-11-08',
        'fixture_repo' => $fixture_repo,
        'manifest_path' => $manifest_path,
    ], $failures, static function (array $data): void {
        $entry = $data['packages']['fixture/alpha']['dev-remote-test-2026-11-08'];
        assert_true($entry['version_normalized'] === 'dev-remote-test-2026-11-08', 'normalized branch version');
        assert_true(($entry['require']['php'] ?? '') === '>=8.4', 'require from branch tip not worktree');
        assert_true(($entry['autoload']['psr-4']['Fixture\\V2\\'] ?? '') === 'src/', 'autoload V2');
        assert_true(isset($entry['bin']) && $entry['bin'] === ['alpha'], 'bin copied');
        assert_true(!isset($entry['config']), 'config not copied');
        assert_true($entry['source']['reference'] === git_rev_parse($GLOBALS['fixture_repo'], 'remote-test-2026-11-08'), 'source ref');
    });

    // 2) Dirty worktree on branch being built.
    run_build_ok([
        'build' => $build_script,
        'manifest' => $manifest_path,
        'output' => $output,
        'origin' => $origin,
        'cache' => $cache_dir,
        'fresh' => true,
        'dirty' => true,
        'build_branch' => 'remote-test-2026-11-08',
        'manifest_branch' => 'remote-test-2026-11-08',
        'fixture_repo' => $fixture_repo,
        'manifest_path' => $manifest_path,
    ], $failures, static function (array $data): void {
        $entry = $data['packages']['fixture/alpha']['dev-remote-test-2026-11-08'];
        assert_true(($entry['require']['php'] ?? '') === '>=8.4', 'dirty worktree ignored');
    });

    // 3) Missing branch.
    write_json($manifest_path, [
        'repos' => [
            ['name' => 'alpha', 'branch' => 'does-not-exist'],
        ],
    ]);
    $output_before_missing = is_file($output) ? file_get_contents($output) : null;
    $code = run_build([
        'build' => $build_script,
        'manifest' => $manifest_path,
        'output' => $output,
        'origin' => $origin,
        'cache' => $cache_dir,
        'fresh' => true,
    ]);
    assert_true($code !== 0, 'missing branch exits non-zero');
    $output_after_missing = is_file($output) ? file_get_contents($output) : null;
    assert_true($output_before_missing === $output_after_missing, 'missing branch output unchanged');

    // 4) Branch without composer.json.
    $no_composer_repo = $tmp_root . '/no-composer.git';
    init_repo_without_composer($no_composer_repo);
    symlink($no_composer_repo, $remotes_dir . '/bare.git');
    write_json($manifest_path, [
        'repos' => [
            ['name' => 'bare', 'branch' => 'main'],
        ],
    ]);
    $code = run_build([
        'build' => $build_script,
        'manifest' => $manifest_path,
        'output' => $output,
        'origin' => $origin,
        'cache' => $cache_dir,
        'fresh' => true,
    ]);
    assert_true($code !== 0, 'missing composer.json exits non-zero');

    // 5) Package composer.json has source + config keys.
    write_json($manifest_path, [
        'repos' => [
            ['name' => 'alpha', 'branch' => 'remote-test-2026-11-08'],
        ],
    ]);
    run_build_ok([
        'build' => $build_script,
        'manifest' => $manifest_path,
        'output' => $output,
        'origin' => $origin,
        'cache' => $cache_dir,
        'fresh' => true,
        'fixture_repo' => $fixture_repo,
        'manifest_path' => $manifest_path,
        'build_branch' => 'remote-test-2026-11-08',
        'manifest_branch' => 'remote-test-2026-11-08',
    ], $failures, static function (array $data): void {
        $entry = $data['packages']['fixture/alpha']['dev-remote-test-2026-11-08'];
        assert_true($entry['source']['type'] === 'git', 'builder source not package source');
        assert_true(!isset($entry['config']), 'config not copied');
    });

    // 6) Slash branch normalization.
    $slash_repo = $tmp_root . '/slash.git';
    init_slash_branch_repo($slash_repo);
    symlink($slash_repo, $remotes_dir . '/slash.git');
    write_json($manifest_path, [
        'repos' => [
            ['name' => 'slash', 'branch' => 'feature/x'],
        ],
    ]);
    run_build_ok([
        'build' => $build_script,
        'manifest' => $manifest_path,
        'output' => $output,
        'origin' => $origin,
        'cache' => $cache_dir,
        'fresh' => true,
        'fixture_repo' => $slash_repo,
        'manifest_path' => $manifest_path,
        'build_branch' => 'feature/x',
        'manifest_branch' => 'feature/x',
        'manifest_repo' => 'slash',
    ], $failures, static function (array $data): void {
        $entry = $data['packages']['fixture/slash']['dev-feature/x'];
        assert_true($entry['version_normalized'] === 'dev-feature/x', 'slash preserved in normalized version');
    });

    // 7) Merge keeps prior versions.
    $seed = [
        'packages' => [
            'kept/pkg' => [
                'dev-ai-training-20260418' => [
                    'name' => 'kept/pkg',
                    'version' => 'dev-ai-training-20260418',
                    'version_normalized' => 'dev-ai-training-20260418',
                    'type' => 'library',
                    'source' => [
                        'type' => 'git',
                        'url' => 'https://github.com/example/kept.git',
                        'reference' => str_repeat('a', 40),
                    ],
                ],
            ],
            'fixture/alpha' => [
                'dev-ai-training-20260418' => [
                    'name' => 'fixture/alpha',
                    'version' => 'dev-ai-training-20260418',
                    'version_normalized' => 'dev-ai-training-20260418',
                    'type' => 'library',
                    'source' => [
                        'type' => 'git',
                        'url' => 'https://github.com/example/alpha.git',
                        'reference' => str_repeat('b', 40),
                    ],
                ],
            ],
        ],
    ];
    write_json($output, $seed);
    write_json($manifest_path, [
        'repos' => [
            ['name' => 'alpha', 'branch' => 'remote-test-2026-11-08'],
        ],
    ]);
    run_build_ok([
        'build' => $build_script,
        'manifest' => $manifest_path,
        'output' => $output,
        'origin' => $origin,
        'cache' => $cache_dir,
        'fresh' => false,
        'fixture_repo' => $fixture_repo,
        'manifest_path' => $manifest_path,
        'build_branch' => 'remote-test-2026-11-08',
        'manifest_branch' => 'remote-test-2026-11-08',
    ], $failures, static function (array $data): void {
        assert_true(isset($data['packages']['kept/pkg']['dev-ai-training-20260418']), 'kept package preserved');
        assert_true(isset($data['packages']['fixture/alpha']['dev-ai-training-20260418']), 'old alpha version preserved');
        assert_true(isset($data['packages']['fixture/alpha']['dev-remote-test-2026-11-08']), 'new alpha version added');
        $names = array_keys($data['packages']);
        sort($names, SORT_STRING);
        assert_true($names === ['fixture/alpha', 'kept/pkg'], 'packages sorted');
        $vers = array_keys($data['packages']['fixture/alpha']);
        sort($vers, SORT_STRING);
        assert_true($vers === ['dev-ai-training-20260418', 'dev-remote-test-2026-11-08'], 'versions sorted');
    });
    $first = file_get_contents($output);
    run_build_ok([
        'build' => $build_script,
        'manifest' => $manifest_path,
        'output' => $output,
        'origin' => $origin,
        'cache' => $cache_dir,
        'fresh' => false,
        'fixture_repo' => $fixture_repo,
        'manifest_path' => $manifest_path,
        'build_branch' => 'remote-test-2026-11-08',
        'manifest_branch' => 'remote-test-2026-11-08',
    ], $failures, static function () use ($output, $first): void {
        assert_true(file_get_contents($output) === $first, 'second merge run is byte-identical');
    });

    // 8) Collision: two repos, same package + version.
    $collision_a = $tmp_root . '/collision-a.git';
    $collision_b = $tmp_root . '/collision-b.git';
    init_collision_repos($collision_a, $collision_b);
    write_json($manifest_path, [
        'repos' => [
            ['name' => 'collision-a', 'branch' => 'remote-test-2026-10-07'],
            ['name' => 'collision-b', 'branch' => 'remote-test-2026-10-07'],
        ],
    ]);
    $merge_output = $tmp_root . '/merge-seed.json';
    write_json($merge_output, ['packages' => []]);
    $collision_manifest = [
        'repos' => [
            ['name' => 'collision-a', 'branch' => 'remote-test-2026-10-07'],
            ['name' => 'collision-b', 'branch' => 'remote-test-2026-10-07'],
        ],
    ];
    write_json($manifest_path, $collision_manifest);
    // Use symlink names under one parent so {name} resolves - actually origin is single template.
    // collision repos must be reached as collision-a and collision-b paths in template.
    $collision_parent = $tmp_root . '/collision-parent';
    mkdir($collision_parent);
    symlink($collision_a, $collision_parent . '/collision-a.git');
    symlink($collision_b, $collision_parent . '/collision-b.git');
    $code = run_build([
        'build' => $build_script,
        'manifest' => $manifest_path,
        'output' => $merge_output,
        'origin' => $collision_parent . '/{name}.git',
        'cache' => $cache_dir,
        'fresh' => true,
    ]);
    assert_true($code !== 0, 'package/version collision exits non-zero');

    // 9) Legacy manifest resolution via DATASMITH.
    $legacy_root = $tmp_root . '/datasmith';
    mkdir($legacy_root . '/configuration', 0777, true);
    write_json($legacy_root . '/configuration/corpus-manifest.json', [
        'repos' => [
            ['name' => 'alpha', 'branch' => 'remote-test-2026-10-07'],
        ],
    ]);
    $legacy_output = $tmp_root . '/legacy-out.json';
    $env = build_proc_env();
    $env['DATASMITH'] = $legacy_root;
    unset($env['CORPUS_MANIFEST']);
    $code = run_build([
        'build' => $build_script,
        'manifest' => null,
        'output' => $legacy_output,
        'origin' => $origin,
        'cache' => $cache_dir,
        'fresh' => true,
        'env' => $env,
    ]);
    assert_true($code === 0, 'legacy DATASMITH manifest builds');
    $legacy_data = json_decode((string) file_get_contents($legacy_output), true, 512, JSON_THROW_ON_ERROR);
    assert_true(isset($legacy_data['packages']['fixture/alpha']['dev-remote-test-2026-10-07']), 'legacy manifest row built');

    verify_chronos_index_entry($repo_root);
    verify_core_index_entry($repo_root);
    verify_fuel_index_entry($repo_root);
    verify_cakephp_index_entry($repo_root);
    verify_php_parser_index_entry($repo_root);
    verify_manifest_index_references($repo_root);
} catch (Throwable $e) {
    $failures[] = 'fatal: ' . $e->getMessage();
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: $failure\n");
    }
    exit(1);
}

fwrite(STDOUT, "All build checks passed.\n");
exit(0);

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function verify_chronos_index_entry(string $repo_root): void
{
    $path = $repo_root . '/packages.json';
    if (!is_file($path)) {
        throw new RuntimeException('packages.json missing for chronos index check');
    }

    /** @var array{packages: array<string, array<string, array<string, mixed>>>} $data */
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $expected_sha = 'd9bca928b0e323f0230d8caa9749decc62f14f48';
    $version = 'dev-remote-test-2026-11-09';

    if (!isset($data['packages']['cakephp/chronos'][$version])) {
        throw new RuntimeException('cakephp/chronos missing dev-remote-test-2026-11-09 in packages.json');
    }

    $entry = $data['packages']['cakephp/chronos'][$version];
    assert_true(
        is_array($entry['source'] ?? null) && ($entry['source']['reference'] ?? '') === $expected_sha,
        'chronos source.reference is d9bca928'
    );
    assert_true(
        is_array($entry['dist'] ?? null) && ($entry['dist']['reference'] ?? '') === $expected_sha,
        'chronos dist.reference is d9bca928'
    );
    $alias = $entry['extra']['branch-alias']['dev-remote-test-2026-11-09'] ?? null;
    assert_true($alias === '3.5.x-dev', 'chronos extra.branch-alias dev-remote-test-2026-11-09 is 3.5.x-dev');
}

function verify_core_index_entry(string $repo_root): void
{
    $path = $repo_root . '/packages.json';
    if (!is_file($path)) {
        throw new RuntimeException('packages.json missing for core index check');
    }

    /** @var array{packages: array<string, array<string, array<string, mixed>>>} $data */
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $expected_sha = 'c618e63e8dcba7ff641ae830843963b0fbaf8395';
    $version = 'dev-remote-test-2026-11-09';

    if (!isset($data['packages']['fuel/core'][$version])) {
        throw new RuntimeException('fuel/core missing dev-remote-test-2026-11-09 in packages.json');
    }

    $entry = $data['packages']['fuel/core'][$version];
    assert_true(
        is_array($entry['source'] ?? null) && ($entry['source']['reference'] ?? '') === $expected_sha,
        'core source.reference is c618e63e'
    );
    assert_true(
        is_array($entry['dist'] ?? null) && ($entry['dist']['reference'] ?? '') === $expected_sha,
        'core dist.reference is c618e63e'
    );
}

function verify_fuel_index_entry(string $repo_root): void
{
    $path = $repo_root . '/packages.json';
    if (!is_file($path)) {
        throw new RuntimeException('packages.json missing for fuel index check');
    }

    /** @var array{packages: array<string, array<string, array<string, mixed>>>} $data */
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $expected_sha = 'a29c96877e55d58dfc73fddd047c4dbdfcedcc7b';
    $version = 'dev-remote-test-2026-10-07';

    if (!isset($data['packages']['fuel/fuel'][$version])) {
        throw new RuntimeException('fuel/fuel missing dev-remote-test-2026-10-07 in packages.json');
    }

    $entry = $data['packages']['fuel/fuel'][$version];
    assert_true(
        is_array($entry['source'] ?? null) && ($entry['source']['reference'] ?? '') === $expected_sha,
        'fuel/fuel source.reference is a29c9687'
    );
    assert_true(
        is_array($entry['dist'] ?? null) && ($entry['dist']['reference'] ?? '') === $expected_sha,
        'fuel/fuel dist.reference is a29c9687'
    );
}

function verify_php_parser_index_entry(string $repo_root): void
{
    $path = $repo_root . '/packages.json';
    if (!is_file($path)) {
        throw new RuntimeException('packages.json missing for php-parser index check');
    }

    /** @var array{packages: array<string, array<string, array<string, mixed>>>} $data */
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $expected_sha = 'f7e11c33f9fbd4aab93d4b718d21e78289913587';
    $version = 'dev-remote-test-2026-11-09';

    if (!isset($data['packages']['nikic/php-parser'][$version])) {
        throw new RuntimeException('nikic/php-parser missing dev-remote-test-2026-11-09 in packages.json');
    }

    $entry = $data['packages']['nikic/php-parser'][$version];
    assert_true(
        is_array($entry['source'] ?? null) && ($entry['source']['reference'] ?? '') === $expected_sha,
        'nikic/php-parser source.reference is f7e11c33'
    );
    assert_true(
        is_array($entry['dist'] ?? null) && ($entry['dist']['reference'] ?? '') === $expected_sha,
        'nikic/php-parser dist.reference is f7e11c33'
    );
    $alias = $entry['extra']['branch-alias']['dev-master'] ?? null;
    assert_true($alias === '5.x-dev', 'nikic/php-parser branch-alias dev-master is 5.x-dev');
}

function verify_manifest_index_references(string $repo_root): void
{
    $manifest_path = $repo_root . '/manifests/remote-test.json';
    $packages_path = $repo_root . '/packages.json';
    if (!is_file($manifest_path) || !is_file($packages_path)) {
        throw new RuntimeException('manifest or packages.json missing for manifest reference check');
    }

    /** @var array{repos: list<array{name: string, branch: string}>} $manifest */
    $manifest = json_decode((string) file_get_contents($manifest_path), true, 512, JSON_THROW_ON_ERROR);
    /** @var array{packages: array<string, array<string, array<string, mixed>>>} $index */
    $index = json_decode((string) file_get_contents($packages_path), true, 512, JSON_THROW_ON_ERROR);

    $by_repo = [];
    foreach ($index['packages'] as $versions) {
        foreach ($versions as $version => $entry) {
            $url = $entry['source']['url'] ?? '';
            if (!is_string($url) || !preg_match('#/([^/]+)\\.git$#', $url, $matches)) {
                continue;
            }
            $by_repo[$matches[1]][$version] = $entry;
        }
    }

    $mismatches = [];
    foreach ($manifest['repos'] as $row) {
        $name = $row['name'];
        $branch = $row['branch'];
        $version = 'dev-' . $branch;
        $entry = $by_repo[$name][$version] ?? null;
        if (!is_array($entry)) {
            $mismatches[] = "$name $branch: missing packages.json entry for $version";
            continue;
        }
        $tip = git_ls_remote_branch_tip($name, $branch);
        $ref = $entry['source']['reference'] ?? '';
        if ($ref !== $tip) {
            $mismatches[] = "$name $branch: index $ref !== remote $tip";
        }
    }

    if ($mismatches !== []) {
        throw new RuntimeException("manifest reference mismatches:\n" . implode("\n", array_slice($mismatches, 0, 20))
            . (count($mismatches) > 20 ? "\n... and " . (count($mismatches) - 20) . " more" : ''));
    }
}

function git_ls_remote_branch_tip(string $repository, string $branch): string
{
    $url = 'https://github.com/Training-Datasmith/' . $repository . '.git';
    $ref = 'refs/heads/' . $branch;
    $result = run_cmd_capture(['git', 'ls-remote', '--heads', $url, $ref]);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException("git ls-remote failed for $repository $branch: " . trim($result['stderr']));
    }
    foreach (preg_split("/\r\n|\n|\r/", trim($result['stdout'])) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        if (preg_match('/^([0-9a-f]{40})\s+' . preg_quote($ref, '/') . '$/', $line, $matches)) {
            return $matches[1];
        }
    }

    throw new RuntimeException("remote branch not found for $repository $branch");
}

function verify_cakephp_index_entry(string $repo_root): void
{
    $path = $repo_root . '/packages.json';
    if (!is_file($path)) {
        throw new RuntimeException('packages.json missing for cakephp index check');
    }

    /** @var array{packages: array<string, array<string, array<string, mixed>>>} $data */
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $expected_sha = '15261676cbfdc3d68d349e5011fb977e0f3df3bf';
    $version = 'dev-remote-test-2026-11-09';

    if (!isset($data['packages']['cakephp/cakephp'][$version])) {
        throw new RuntimeException('cakephp/cakephp missing dev-remote-test-2026-11-09 in packages.json');
    }

    $entry = $data['packages']['cakephp/cakephp'][$version];
    assert_true(
        is_array($entry['source'] ?? null) && ($entry['source']['reference'] ?? '') === $expected_sha,
        'cakephp/cakephp source.reference is 15261676'
    );
    assert_true(
        is_array($entry['dist'] ?? null) && ($entry['dist']['reference'] ?? '') === $expected_sha,
        'cakephp/cakephp dist.reference is 15261676'
    );
}

/**
 * @param list<string> $failures
 * @param callable(array):void $verify
 * @param array<string, mixed> $opts
 */
function run_build_ok(array $opts, array &$failures, callable $verify): void
{
    try {
        if (isset($opts['manifest_branch'])) {
            $repo_name = isset($opts['manifest_repo']) ? (string) $opts['manifest_repo'] : 'alpha';
            write_json($opts['manifest_path'], [
                'repos' => [
                    ['name' => $repo_name, 'branch' => $opts['manifest_branch']],
                ],
            ]);
        }
        if (isset($opts['build_branch'], $opts['fixture_repo'])) {
            checkout_branch($opts['fixture_repo'], $opts['build_branch']);
        }
        if (!empty($opts['dirty'])) {
            dirty_composer($opts['fixture_repo'], '>=5.4');
        }
        $code = run_build($opts);
        assert_true($code === 0, 'build exit code zero' . (isset($GLOBALS['last_build_failure']) ? ': ' . $GLOBALS['last_build_failure'] : ''));
        $data = json_decode((string) file_get_contents($opts['output']), true, 512, JSON_THROW_ON_ERROR);
        $verify($data);
    } catch (Throwable $e) {
        $failures[] = $e->getMessage();
    }
}

/**
 * @param array<string, mixed> $opts
 */
function build_proc_env(): array
{
    $env = [];
    foreach ($_SERVER as $key => $value) {
        if (is_string($value)) {
            $env[$key] = $value;
        }
    }
    foreach ($_ENV as $key => $value) {
        if (is_string($value)) {
            $env[$key] = $value;
        }
    }

    return $env;
}

/**
 * @param array<string, mixed> $opts
 */
function run_build(array $opts): int
{
    $cmd = [
        PHP_BINARY,
        $opts['build'],
    ];
    if (!empty($opts['manifest'])) {
        $cmd[] = '--manifest';
        $cmd[] = $opts['manifest'];
    }
    $cmd[] = '--output';
    $cmd[] = $opts['output'];
    $cmd[] = '--origin';
    $cmd[] = $opts['origin'];
    $cmd[] = '--cache';
    $cmd[] = $opts['cache'];
    if (!empty($opts['fresh'])) {
        $cmd[] = '--fresh';
    }

    $env = $opts['env'] ?? build_proc_env();
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, null, $env);
    if (!is_resource($process)) {
        return 1;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $GLOBALS['last_build_failure'] = trim($stderr . "\n" . $stdout);
    }

    return $exit;
}

function init_fixture_repo(string $bare_repo): void
{
    $work = dirname($bare_repo) . '/fixture-work';
    run_cmd(['git', 'init', $work]);
    run_cmd(['git', '-C', $work, 'config', 'user.email', 'test@example.com']);
    run_cmd(['git', '-C', $work, 'config', 'user.name', 'Test']);

    write_json($work . '/composer.json', [
        'name' => 'fixture/alpha',
        'require' => ['php' => '>=8.2'],
        'autoload' => ['psr-4' => ['Fixture\\V1\\' => 'src/']],
        'source' => ['type' => '', 'url' => '', 'reference' => ''],
        'config' => ['platform' => ['php' => '7.0']],
    ]);
    mkdir($work . '/src', 0777, true);
    file_put_contents($work . '/src/.gitkeep', '');
    run_cmd(['git', '-C', $work, 'add', '.']);
    run_cmd(['git', '-C', $work, 'commit', '-m', 'v1']);
    run_cmd(['git', '-C', $work, 'branch', '-M', 'remote-test-2026-10-07']);

    write_json($work . '/composer.json', [
        'name' => 'fixture/alpha',
        'require' => ['php' => '>=8.4'],
        'autoload' => ['psr-4' => ['Fixture\\V2\\' => 'src/']],
        'bin' => ['alpha'],
        'source' => ['type' => '', 'url' => '', 'reference' => ''],
        'config' => ['platform' => ['php' => '7.0']],
    ]);
    run_cmd(['git', '-C', $work, 'add', 'composer.json']);
    run_cmd(['git', '-C', $work, 'commit', '-m', 'v2']);
    run_cmd(['git', '-C', $work, 'branch', 'remote-test-2026-11-08']);

    run_cmd(['git', 'clone', '--bare', $work, $bare_repo]);
    remove_tree($work);
    $GLOBALS['fixture_repo'] = $bare_repo;
}

function init_repo_without_composer(string $bare_repo): void
{
    $work = dirname($bare_repo) . '/bare-work';
    run_cmd(['git', 'init', $work]);
    run_cmd(['git', '-C', $work, 'config', 'user.email', 'test@example.com']);
    run_cmd(['git', '-C', $work, 'config', 'user.name', 'Test']);
    file_put_contents($work . '/README', 'no composer');
    run_cmd(['git', '-C', $work, 'add', 'README']);
    run_cmd(['git', '-C', $work, 'commit', '-m', 'init']);
    run_cmd(['git', 'clone', '--bare', $work, $bare_repo]);
    remove_tree($work);
}

function init_slash_branch_repo(string $bare_repo): void
{
    $work = dirname($bare_repo) . '/slash-work';
    run_cmd(['git', 'init', $work]);
    run_cmd(['git', '-C', $work, 'config', 'user.email', 'test@example.com']);
    run_cmd(['git', '-C', $work, 'config', 'user.name', 'Test']);
    write_json($work . '/composer.json', [
        'name' => 'fixture/slash',
        'require' => ['php' => '>=8.0'],
    ]);
    run_cmd(['git', '-C', $work, 'add', '.']);
    run_cmd(['git', '-C', $work, 'commit', '-m', 'slash']);
    run_cmd(['git', '-C', $work, 'branch', 'feature/x']);
    run_cmd(['git', 'clone', '--bare', $work, $bare_repo]);
    remove_tree($work);
}

/**
 * @return array{0: string, 1: string}
 */
function init_collision_repos(string $repo_a, string $repo_b): void
{
    foreach (['collision-a' => $repo_a, 'collision-b' => $repo_b] as $label => $path) {
        $work = dirname($path) . '/' . $label . '-work';
        run_cmd(['git', 'init', $work]);
        run_cmd(['git', '-C', $work, 'config', 'user.email', 'test@example.com']);
        run_cmd(['git', '-C', $work, 'config', 'user.name', 'Test']);
        write_json($work . '/composer.json', [
            'name' => 'fixture/collision',
            'require' => ['php' => '>=8.0'],
        ]);
        run_cmd(['git', '-C', $work, 'add', '.']);
        run_cmd(['git', '-C', $work, 'commit', '-m', 'init']);
        run_cmd(['git', '-C', $work, 'branch', 'remote-test-2026-10-07']);
        run_cmd(['git', 'clone', '--bare', $work, $path]);
        remove_tree($work);
    }
}

function checkout_branch(string $bare_repo, string $branch): void
{
    // Bare repos only: ensure branch exists via ls-remote style rev-parse.
    $sha = git_rev_parse($bare_repo, $branch);
    if ($sha === '') {
        throw new RuntimeException("branch $branch missing in fixture");
    }
}

function dirty_composer(string $bare_repo, string $php_constraint): void
{
    $work = dirname($bare_repo) . '/dirty-work';
    if (is_dir($work)) {
        remove_tree($work);
    }
    run_cmd(['git', 'clone', $bare_repo, $work]);
    run_cmd(['git', '-C', $work, 'checkout', 'remote-test-2026-11-08']);
    $composer = json_decode((string) file_get_contents($work . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $composer['require']['php'] = $php_constraint;
    write_json($work . '/composer.json', $composer);
}

function git_rev_parse(string $bare_repo, string $branch): string
{
    $result = run_cmd_capture(['git', '--git-dir', $bare_repo, 'rev-parse', $branch]);

    return trim($result['stdout']);
}

/**
 * @param array<string, mixed> $data
 */
function write_json(string $path, array $data): void
{
    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
}

/**
 * @param list<string> $cmd
 */
function run_cmd(array $cmd): void
{
    $result = run_cmd_capture($cmd);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('command failed: ' . implode(' ', $cmd) . ' :: ' . $result['stderr']);
    }
}

/**
 * @param list<string> $cmd
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function run_cmd_capture(array $cmd): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [
        'exit_code' => $exit,
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            remove_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
