# Training-Datasmith Composer index

Static Composer repository for [Training-Datasmith](https://github.com/Training-Datasmith) corpus forks. One `repositories` entry in consumer `composer.json` resolves forked packages before Packagist.

> **Note:** `Training-Datasmith/composer` is the fork of the Composer application itself. This repo is **`composer-index`** — the package registry only.

## Consumer setup

List the index repository before Packagist so forks win. When you still need Packagist releases for packages that also appear in the index, set `"canonical": false` (Composer 2 treats custom repositories as canonical by default).

```json
{
  "repositories": [
    {
      "type": "composer",
      "url": "https://raw.githubusercontent.com/Training-Datasmith/composer-index/remote-test-2026-11-09",
      "canonical": false
    }
  ],
  "require": {
    "fuel/auth": "dev-ai-training-20260418 as 1.8.x-dev",
    "fuel/docs": "dev-remote-test-2026-10-07 as 1.8.x-dev"
  }
}
```

Inline aliases such as `dev-remote-test-2026-10-07 as 1.8.x-dev` belong in the **root** `composer.json` only. They satisfy semver ranges on fixed branches without writing `branch-alias` into `packages.json`.

Or configure once globally:

```bash
composer config --global repositories.training-datasmith composer \
  https://raw.githubusercontent.com/Training-Datasmith/composer-index/remote-test-2026-11-09
```

Packages not in the index still install from Packagist.

## Regenerating `packages.json`

Requires **PHP 8.0+**, `git`, and network access to `https://github.com`. The builder resolves each manifest row from the remote branch tip (`git ls-remote`, `git fetch`, `git show <sha>:composer.json`). It does not read a local checkout’s working tree.

```bash
php build.php
php build.php --manifest manifests/remote-test.json
DATASMITH=/path/to/datasmith php build.php
```

Manifest resolution order:

1. `--manifest PATH`
2. `CORPUS_MANIFEST`
3. `$DATASMITH/configuration/corpus-manifest.json` when present
4. `manifests/remote-test.json` in this repository

By default the builder **merges** new `dev-<branch>` versions into the existing `packages.json`. Use `--fresh` for a single-manifest output with no merge. A missing remote branch or missing `composer.json` at the tip fails the build (non-zero exit) and does not replace `packages.json`.

Offline checks:

```bash
php tests/check-build.php
```

## Layout

| File | Purpose |
|------|---------|
| `packages.json` | Composer repository index (committed) |
| `build.php` | Generator from manifest + remote `composer.json` per branch tip |
| `manifests/remote-test.json` | 133-repo manifest for fixed `remote-test-*` / training branches (`cache` omitted; see below) |

### Why `cache` is not in `manifests/remote-test.json`

Both `Training-Datasmith/cache` and `Training-Datasmith/cache-1` publish as `psr/cache`. The index already tracks `cache-1`. Listing both rows would collide on the same package version.
