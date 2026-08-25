<?php

declare(strict_types=1);

/**
 * Build packages.json for the Training-Datasmith Composer repository index.
 *
 * Reads configuration/corpus-manifest.json and local fork composer.json files
 * under DATASMITH, then writes packages.json beside this script.
 *
 * Usage:
 *   php build.php
 *   DATASMITH=/path/to/datasmith php build.php
 */

const GITHUB_ORG = 'Training-Datasmith';

$datasmith = getenv('DATASMITH') ?: dirname(__DIR__);
$manifest_path = $datasmith . '/configuration/corpus-manifest.json';
$output_path = __DIR__ . '/packages.json';

if (!is_file($manifest_path)) {
    fwrite(STDERR, "Manifest not found: $manifest_path\n");
    exit(1);
}

/** @var array{repos: list<array{name: string, branch: string, sha: string}>} $manifest */
$manifest = json_decode((string) file_get_contents($manifest_path), true, 512, JSON_THROW_ON_ERROR);

$packages = [];
$indexed = 0;
$skipped = 0;

foreach ($manifest['repos'] as $entry) {
    $repository = $entry['name'];
    $branch = $entry['branch'];
    $repository_path = $datasmith . '/' . $repository;
    $composer_path = $repository_path . '/composer.json';

    if (!is_file($composer_path)) {
        ++$skipped;
        continue;
    }

    /** @var array<string, mixed> $composer */
    $composer = json_decode((string) file_get_contents($composer_path), true, 512, JSON_THROW_ON_ERROR);
    $package_name = $composer['name'] ?? null;

    if (!is_string($package_name) || !str_contains($package_name, '/')) {
        ++$skipped;
        continue;
    }

    $reference = resolve_reference($repository_path, $branch, $entry['sha'] ?? '');
    if ($reference === '') {
        fwrite(STDERR, "  skip $repository: cannot resolve git reference for $branch\n");
        ++$skipped;
        continue;
    }

    $version = 'dev-' . $branch;
    $version_entry = [
        'name' => $package_name,
        'version' => $version,
        'version_normalized' => normalize_dev_version($version),
        'type' => $composer['type'] ?? 'library',
        'source' => [
            'type' => 'git',
            'url' => 'https://github.com/' . GITHUB_ORG . '/' . $repository . '.git',
            'reference' => $reference,
        ],
    ];

    foreach (['description', 'license', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'suggest', 'autoload', 'autoload-dev', 'extra', 'minimum-stability', 'prefer-stable'] as $key) {
        if (array_key_exists($key, $composer)) {
            $version_entry[$key] = $composer[$key];
        }
    }

    $packages[$package_name][$version] = $version_entry;
    ++$indexed;
}

ksort($packages);
foreach ($packages as &$versions) {
    ksort($versions);
}
unset($versions);

$index = [
    'packages' => $packages,
];

$json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
file_put_contents($output_path, $json);

fwrite(STDOUT, "Wrote $output_path\n");
fwrite(STDOUT, "  indexed packages: $indexed\n");
fwrite(STDOUT, "  skipped repositories: $skipped\n");

function resolve_reference(string $repository_path, string $branch, string $fallback_sha): string
{
    if (is_dir($repository_path . '/.git')) {
        $sha = trim((string) shell_exec(
            'git -C ' . escapeshellarg(str_replace('/', DIRECTORY_SEPARATOR, $repository_path))
            . ' rev-parse ' . escapeshellarg($branch) . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null')
        ));

        if ($sha !== '' && preg_match('/^[0-9a-f]{40}$/', $sha)) {
            return $sha;
        }
    }

    if ($fallback_sha !== '' && preg_match('/^[0-9a-f]{40}$/', $fallback_sha)) {
        return $fallback_sha;
    }

    return '';
}

function normalize_dev_version(string $version): string
{
    if (!str_starts_with($version, 'dev-')) {
        return $version;
    }

    $branch = substr($version, 4);

    return 'dev-' . str_replace('/', '.', $branch);
}
