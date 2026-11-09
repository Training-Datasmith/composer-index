<?php

declare(strict_types=1);

/**
 * Build packages.json for the Training-Datasmith Composer repository index.
 *
 * Resolves each manifest row from the remote branch tip (git ls-remote + fetch +
 * git show <sha>:composer.json), then merges new dev-<branch> versions into
 * packages.json unless --fresh is set.
 *
 * Usage:
 *   php build.php [--manifest PATH] [--output PATH] [--origin TEMPLATE] [--fresh]
 *   CORPUS_MANIFEST=/path/to.json php build.php
 *   DATASMITH=/path/to/datasmith php build.php
 */

const GITHUB_ORG = 'Training-Datasmith';
const DEFAULT_ORIGIN_TEMPLATE = 'https://github.com/Training-Datasmith/{name}.git';

const COPY_KEYS = [
    'description',
    'license',
    'require',
    'require-dev',
    'conflict',
    'replace',
    'provide',
    'suggest',
    'autoload',
    'autoload-dev',
    'bin',
    'extra',
    'minimum-stability',
    'prefer-stable',
];

$script_dir = __DIR__;
$options = parse_cli_args($argv);
$datasmith = getenv('DATASMITH') ?: dirname($script_dir);

try {
    $manifest_path = resolve_manifest_path($script_dir, $datasmith, $options['manifest']);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

/** @var array{repos: list<array{name: string, branch: string}>} $manifest */
$manifest = json_decode((string) file_get_contents($manifest_path), true, 512, JSON_THROW_ON_ERROR);
if (!isset($manifest['repos']) || !is_array($manifest['repos'])) {
    fwrite(STDERR, "Invalid manifest: expected {\"repos\": [...]}\n");
    exit(1);
}

$origin_template = $options['origin'] ?? DEFAULT_ORIGIN_TEMPLATE;
try {
    validate_origin_template($origin_template);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$output_path = $options['output'] ?? ($script_dir . '/packages.json');
$fresh = $options['fresh'];
$cache_root = $options['cache'] ?? ($script_dir . '/.cache/git');

$packages = [];
if (!$fresh && is_file($output_path)) {
    $existing = json_decode((string) file_get_contents($output_path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($existing) || !isset($existing['packages']) || !is_array($existing['packages'])) {
        fwrite(STDERR, "Invalid existing output: expected {\"packages\": {...}}\n");
        exit(1);
    }
    $packages = $existing['packages'];
}

$seen_repo_names = [];
$errors = [];
$built = [];
$indexed = 0;

foreach ($manifest['repos'] as $entry) {
    if (!is_array($entry)) {
        $errors[] = 'invalid row: not an object';
        continue;
    }
    $repository = isset($entry['name']) ? (string) $entry['name'] : '';
    $branch = isset($entry['branch']) ? (string) $entry['branch'] : '';

    if ($repository === '' || $branch === '') {
        $errors[] = "$repository $branch: missing name or branch";
        continue;
    }

    if (!validate_repo_name($repository)) {
        $errors[] = "$repository $branch: invalid repository name";
        continue;
    }
    if (!validate_branch_name($branch)) {
        $errors[] = "$repository $branch: invalid branch name";
        continue;
    }
    if (isset($seen_repo_names[$repository])) {
        $errors[] = "$repository $branch: duplicate repository name in manifest";
        continue;
    }
    $seen_repo_names[$repository] = true;

    try {
        $repo_url = str_replace('{name}', $repository, $origin_template);
        $built_entry = build_index_entry(
            $repository,
            $branch,
            $repo_url,
            $datasmith,
            $cache_root
        );
    } catch (RuntimeException $e) {
        $errors[] = "$repository $branch: " . $e->getMessage();
        continue;
    }

    $package_name = $built_entry['name'];
    $version = $built_entry['version'];
    $slot = $package_name . "\0" . $version;
    if (isset($built[$slot]) && $built[$slot] !== $repository) {
        $errors[] = "$repository $branch: package $package_name version $version already produced by {$built[$slot]}";
        continue;
    }
    $built[$slot] = $repository;

    if (!isset($packages[$package_name])) {
        $packages[$package_name] = [];
    }
    $packages[$package_name][$version] = $built_entry;
    ++$indexed;
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(1);
}

ksort($packages, SORT_STRING);
foreach ($packages as &$versions) {
    ksort($versions, SORT_STRING);
}
unset($versions);

$index = ['packages' => $packages];
$json = encode_index($index);
$tmp_path = dirname($output_path) . '/' . basename($output_path) . '.tmp';

if (file_put_contents($tmp_path, $json) === false) {
    fwrite(STDERR, "Failed to write temporary output: $tmp_path\n");
    exit(1);
}
if (!rename($tmp_path, $output_path)) {
    fwrite(STDERR, "Failed to replace output: $output_path\n");
    @unlink($tmp_path);
    exit(1);
}

fwrite(STDOUT, "Manifest: $manifest_path\n");
fwrite(STDOUT, "Wrote $output_path\n");
fwrite(STDOUT, "  indexed packages: $indexed\n");

/**
 * @return array{manifest: ?string, output: ?string, origin: ?string, fresh: bool, cache: ?string}
 */
function parse_cli_args(array $argv): array
{
    $options = [
        'manifest' => null,
        'output' => null,
        'origin' => null,
        'fresh' => false,
        'cache' => null,
    ];

    $count = count($argv);
    for ($i = 1; $i < $count; ++$i) {
        $arg = $argv[$i];
        if ($arg === '--fresh') {
            $options['fresh'] = true;
            continue;
        }
        if ($arg === '--manifest' && isset($argv[$i + 1])) {
            $options['manifest'] = $argv[++$i];
            continue;
        }
        if ($arg === '--output' && isset($argv[$i + 1])) {
            $options['output'] = $argv[++$i];
            continue;
        }
        if ($arg === '--origin' && isset($argv[$i + 1])) {
            $options['origin'] = $argv[++$i];
            continue;
        }
        if ($arg === '--cache' && isset($argv[$i + 1])) {
            $options['cache'] = $argv[++$i];
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            fwrite(STDOUT, "Usage: php build.php [--manifest PATH] [--output PATH] [--origin TEMPLATE] [--fresh] [--cache DIR]\n");
            exit(0);
        }
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(1);
    }

    return $options;
}

function resolve_manifest_path(string $script_dir, string $datasmith, ?string $cli_manifest): string
{
    if ($cli_manifest !== null && $cli_manifest !== '') {
        if (!is_file($cli_manifest)) {
            throw new RuntimeException("Manifest not found: $cli_manifest");
        }

        return $cli_manifest;
    }

    $env_manifest = getenv('CORPUS_MANIFEST');
    if (is_string($env_manifest) && $env_manifest !== '') {
        if (!is_file($env_manifest)) {
            throw new RuntimeException("Manifest not found: $env_manifest");
        }

        return $env_manifest;
    }

    $legacy = $datasmith . '/configuration/corpus-manifest.json';
    if (is_file($legacy)) {
        return $legacy;
    }

    $in_repo = $script_dir . '/manifests/remote-test.json';
    if (is_file($in_repo)) {
        return $in_repo;
    }

    throw new RuntimeException(
        "Manifest not found. Use one of:\n"
        . "  --manifest PATH\n"
        . "  CORPUS_MANIFEST environment variable\n"
        . "  \$DATASMITH/configuration/corpus-manifest.json (DATASMITH=$datasmith)\n"
        . "  $in_repo\n"
    );
}

function validate_repo_name(string $name): bool
{
    if ($name === '' || str_contains($name, '..') || str_starts_with($name, '-')) {
        return false;
    }

    return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $name);
}

function validate_branch_name(string $branch): bool
{
    if ($branch === '' || str_contains($branch, '..') || str_starts_with($branch, '-')) {
        return false;
    }

    return (bool) preg_match('/^[A-Za-z0-9._\/-]+$/', $branch);
}

function validate_origin_template(string $template): void
{
    if (!str_contains($template, '{name}')) {
        throw new RuntimeException('Origin template must contain {name}');
    }
    if (str_starts_with($template, 'http://')) {
        throw new RuntimeException('Insecure origin template: http:// is not allowed');
    }
    if (str_starts_with($template, 'git@')) {
        throw new RuntimeException('Origin template must use https:// or a local filesystem path');
    }
    if (preg_match('#^https://#', $template) === 1) {
        $host = parse_url(str_replace('{name}', 'x', $template), PHP_URL_HOST);
        if (!is_string($host) || !preg_match('/(^|\.)github\.com$/i', $host)) {
            throw new RuntimeException('HTTPS origin template must target github.com');
        }
    }
}

/**
 * @return array<string, mixed>
 */
function build_index_entry(
    string $repository,
    string $branch,
    string $repo_url,
    string $datasmith,
    string $cache_root
): array {
    $reference = null;
    $used_remote = false;

    try {
        $reference = resolve_remote_branch_sha($repo_url, $branch);
        $used_remote = true;
    } catch (RuntimeException $e) {
        $local_path = $datasmith . '/' . $repository;
        $local_sha = resolve_local_branch_sha($local_path, $branch);
        if ($local_sha === null) {
            throw new RuntimeException('missing remote branch and no local git ref: ' . $e->getMessage());
        }
        $reference = $local_sha;
    }

    if (!preg_match('/^[0-9a-f]{40}$/', $reference)) {
        throw new RuntimeException('invalid reference SHA');
    }

    $bare_repo = $cache_root . '/' . $repository . '.git';
    ensure_fetched_bare_repo($bare_repo, $repo_url, $branch, $reference, $used_remote);

    $composer_raw = read_path_at_commit($bare_repo, $reference, 'composer.json');
    if ($composer_raw === null) {
        throw new RuntimeException('composer.json missing at reference');
    }

    /** @var array<string, mixed> $composer */
    $composer = json_decode($composer_raw, true, 512, JSON_THROW_ON_ERROR);
    $package_name = $composer['name'] ?? null;
    if (!is_string($package_name) || !str_contains($package_name, '/')) {
        throw new RuntimeException('composer.json name must be vendor/package');
    }

    $version = 'dev-' . $branch;
    // Source/dist URLs always use the manifest repository name on GitHub.
    $github_url = 'https://github.com/' . GITHUB_ORG . '/' . $repository . '.git';
    $dist_url = 'https://api.github.com/repos/' . GITHUB_ORG . '/' . $repository . '/zipball/' . $reference;

    $version_entry = [
        'name' => $package_name,
        'version' => $version,
        'version_normalized' => normalize_dev_version($version),
        'type' => $composer['type'] ?? 'library',
        'source' => [
            'type' => 'git',
            'url' => $github_url,
            'reference' => $reference,
        ],
        'dist' => [
            'type' => 'zip',
            'url' => $dist_url,
            'reference' => $reference,
            'shasum' => '',
        ],
    ];

    foreach (COPY_KEYS as $key) {
        if (array_key_exists($key, $composer)) {
            $version_entry[$key] = $composer[$key];
        }
    }

    return $version_entry;
}

function normalize_dev_version(string $version): string
{
    if (stripos($version, 'dev-') !== 0) {
        return $version;
    }

    return 'dev-' . substr($version, 4);
}

function resolve_remote_branch_sha(string $repo_url, string $branch): string
{
    $ref = 'refs/heads/' . $branch;
    $result = run_git(['ls-remote', '--heads', $repo_url, $ref]);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException(trim($result['stderr']) !== '' ? trim($result['stderr']) : 'git ls-remote failed');
    }

    foreach (preg_split("/\r\n|\n|\r/", trim($result['stdout'])) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        if (!preg_match('/^([0-9a-f]{40})\s+(.+)$/', $line, $matches)) {
            throw new RuntimeException('unparseable git ls-remote output');
        }
        if ($matches[2] === $ref) {
            return $matches[1];
        }
    }

    throw new RuntimeException('remote branch not found');
}

function resolve_local_branch_sha(string $repository_path, string $branch): ?string
{
    $git_dir = $repository_path . '/.git';
    if (!is_dir($git_dir)) {
        return null;
    }

    $result = run_git(['rev-parse', $branch], $repository_path);
    if ($result['exit_code'] !== 0) {
        return null;
    }
    $sha = trim($result['stdout']);
    if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
        return null;
    }

    return $sha;
}

function ensure_fetched_bare_repo(
    string $bare_repo,
    string $repo_url,
    string $branch,
    string $expected_sha,
    bool $verify_tip
): void {
    if (!is_dir($bare_repo)) {
        if (!is_dir(dirname($bare_repo)) && !mkdir(dirname($bare_repo), 0777, true) && !is_dir(dirname($bare_repo))) {
            throw new RuntimeException('cannot create cache directory');
        }
        $init = run_git(['init', '--bare', $bare_repo]);
        if ($init['exit_code'] !== 0) {
            throw new RuntimeException('git init --bare failed: ' . trim($init['stderr']));
        }
        $remote = run_git(['-C', $bare_repo, 'remote', 'add', 'origin', $repo_url]);
        if ($remote['exit_code'] !== 0) {
            throw new RuntimeException('git remote add failed: ' . trim($remote['stderr']));
        }
    }

    $fetch_sha = run_git(['-C', $bare_repo, 'fetch', '--depth', '1', 'origin', $expected_sha]);
    if ($fetch_sha['exit_code'] !== 0) {
        $fetch_branch = run_git(['-C', $bare_repo, 'fetch', '--depth', '1', 'origin', 'refs/heads/' . $branch]);
        if ($fetch_branch['exit_code'] !== 0) {
            throw new RuntimeException('git fetch failed: ' . trim($fetch_branch['stderr']));
        }
    }

    $rev = run_git(['-C', $bare_repo, 'rev-parse', 'FETCH_HEAD']);
    if ($rev['exit_code'] !== 0) {
        throw new RuntimeException('git rev-parse FETCH_HEAD failed');
    }
    $fetched = trim($rev['stdout']);
    if ($fetched !== $expected_sha) {
        if (!$verify_tip) {
            throw new RuntimeException("fetched $fetched, expected $expected_sha");
        }
        $retry_sha = resolve_remote_branch_sha($repo_url, $branch);
        if ($retry_sha !== $expected_sha) {
            throw new RuntimeException("branch tip moved: expected $expected_sha, now $retry_sha");
        }
        $refetch = run_git(['-C', $bare_repo, 'fetch', '--depth', '1', 'origin', $retry_sha]);
        if ($refetch['exit_code'] !== 0) {
            $refetch = run_git(['-C', $bare_repo, 'fetch', '--depth', '1', 'origin', 'refs/heads/' . $branch]);
        }
        if ($refetch['exit_code'] !== 0) {
            throw new RuntimeException('git fetch retry failed');
        }
        $rev2 = run_git(['-C', $bare_repo, 'rev-parse', 'FETCH_HEAD']);
        $fetched2 = trim($rev2['stdout']);
        if ($fetched2 !== $retry_sha) {
            throw new RuntimeException("fetch retry got $fetched2, expected $retry_sha");
        }
    }
}

function read_path_at_commit(string $bare_repo, string $sha, string $path): ?string
{
    $result = run_git(['-C', $bare_repo, 'show', $sha . ':' . $path]);
    if ($result['exit_code'] !== 0) {
        return null;
    }

    return $result['stdout'];
}

/**
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function run_git(array $args, ?string $cwd = null): array
{
    static $git_env = null;
    if ($git_env === null) {
        $git_env = build_git_environment();
    }

    $command = array_merge(['git'], $args);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $cwd ?? getcwd() ?: null, $git_env);
    if (!is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);

    return [
        'exit_code' => $exit_code,
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

/**
 * @return array<string, string>
 */
function build_git_environment(): array
{
    $base = [];
    foreach ($_SERVER as $key => $value) {
        if (is_string($value) && preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) === 1) {
            $base[$key] = $value;
        }
    }

    $empty_config = tempnam(sys_get_temp_dir(), 'gitconfig');
    if ($empty_config === false) {
        throw new RuntimeException('cannot create temporary git config');
    }
    file_put_contents($empty_config, '');

    $base['GIT_TERMINAL_PROMPT'] = '0';
    $base['GIT_CONFIG_NOSYSTEM'] = '1';
    $base['GIT_CONFIG_GLOBAL'] = $empty_config;
    $base['GIT_CONFIG_SYSTEM'] = $empty_config;

    if (!isset($base['PATH']) && isset($_ENV['PATH']) && is_string($_ENV['PATH'])) {
        $base['PATH'] = $_ENV['PATH'];
    }

    return $base;
}

/**
 * @param array{packages: array<string, array<string, mixed>>} $index
 */
function encode_index(array $index): string
{
    return json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
