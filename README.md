# Notifizz PHP SDK

Official Notifizz SDK for PHP. The package is published on [Packagist](https://packagist.org/packages/notifizz/notifizz-php) from the public repo [ThreeMakers/notifizz-php-sdk](https://github.com/ThreeMakers/notifizz-php-sdk).

## Installing the SDK

No custom repository or credentials are required; Packagist is used by default.

```bash
composer require notifizz/notifizz-php
```

## Usage

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Notifizz\NotifizzClient;
use Notifizz\TrackContext;

$client = new NotifizzClient('your-auth-secret', 'your-sdk-secret');

$context = $client->track('user-123', 'event-name');
$context->setProperty('key', 'value');
$context->send();
```

## Publishing new versions (maintainers)

The SDK is developed in this private repo under `sdk/back-end/php-sdk/`. When you push to `main` with changes in that folder, a GitHub Action syncs the code to the **public** repo [ThreeMakers/notifizz-php-sdk](https://github.com/ThreeMakers/notifizz-php-sdk), creates a version tag there, and triggers Packagist to update. Packagist indexes the public repo only (so the repo can stay private).

### One-time setup

1. **Create the package on Packagist**
   - Sign in at [packagist.org](https://packagist.org).
   - Click "Submit" and enter the **public** repository URL: `https://github.com/ThreeMakers/notifizz-php-sdk`.
   - Leave **Subdirectory** empty (the public repo root is the package).

2. **GitHub secret: push to public repo**
   - Create a [GitHub Personal Access Token](https://github.com/settings/tokens) with `repo` scope (or fine-grained with write access to `ThreeMakers/notifizz-php-sdk`).
   - Add a repository secret: `PHP_SDK_PUBLIC_REPO_TOKEN` = that token. The workflow uses it to clone and push to the public repo.

3. **Packagist API token**
   - On your [Packagist profile](https://packagist.org/profile/), copy your API token.
   - Add a repository secret: `PACKAGIST_TOKEN` = `USERNAME:API_TOKEN` (Packagist username, colon, token). The update API accepts the "SAFE" token.

### Release steps

1. Bump the `version` in `sdk/back-end/php-sdk/composer.json` (e.g. `1.0.1`).
2. Commit, push, and merge to `main` (e.g. via a PR).
3. The workflow runs: syncs `sdk/back-end/php-sdk/` to the public repo, commits and pushes there, creates tag `v<version>` on the public repo, then triggers Packagist. Users get the new version with `composer update notifizz/notifizz-php`.
