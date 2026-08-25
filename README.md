# Training-Datasmith Composer index

Static Composer repository for [Training-Datasmith](https://github.com/Training-Datasmith) corpus forks. One `repositories` entry in consumer `composer.json` resolves forked packages before Packagist.

> **Note:** `Training-Datasmith/composer` is the fork of the Composer application itself. This repo is **`composer-index`** — the package registry only.

## Consumer setup

Add a single repository (listed before Packagist so forks win):

```json
{
  "repositories": [
    {
      "type": "composer",
      "url": "https://raw.githubusercontent.com/Training-Datasmith/composer-index/main"
    }
  ],
  "require": {
    "aimeos/ai-controller-frontend": "dev-ai-training-20260418"
  }
}
```

Or configure once globally:

```bash
composer config --global repositories.training-datasmith composer \
  https://raw.githubusercontent.com/Training-Datasmith/composer-index/main
```

Packages not in the index still install from Packagist.

## Regenerating `packages.json`

From the datasmith workspace (needs `configuration/corpus-manifest.json` and local fork clones):

```bash
php composer-index/build.php
```

Uses each manifest entry's training branch and the local git SHA when the repo is present; otherwise falls back to the manifest SHA.

## Layout

| File | Purpose |
|------|---------|
| `packages.json` | Composer repository index (committed) |
| `build.php` | Generator from corpus manifest + local `composer.json` files |
