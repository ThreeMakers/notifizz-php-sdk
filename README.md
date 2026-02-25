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

$client = new NotifizzClient('your-auth-secret', 'your-sdk-secret');

// Track an event with workflows (chain workflow() then send())
$client->track([
    'eventName' => 'user_signed_up',
    'sdkSecretKey' => 'your-sdk-secret',
    'properties' => [
        'plan' => 'pro',
        'source' => 'landing_page',
    ],
])->workflow('campaign_123', [
    ['id' => 'user_1', 'email' => 'user1@example.com'],
    ['id' => 'user_2', 'email' => 'user2@example.com'],
])->send();

// Generate a hashed token for backend auth (e.g. Notification Center)
$token = $client->generateHashedToken('user_123');

// Send a notification to the Notification Center
$client->send([
    'notifId' => 'notif_123',
    'properties' => [
        'recipients' => [
            ['id' => 'user_1', 'email' => 'user@example.com'],
        ],
        'message' => 'Hello world',
    ],
]);

// Optional: configure base URL or auto-send delay
$client->config([
    'baseUrl' => 'https://eu.api.notifizz.com/v1',
    'autoSendDelayMs' => 1000,
]);
```

You can chain multiple `->workflow($campaignId, $recipients)` calls before `->send()`.

## API summary

| Method | Description |
|--------|-------------|
| `new NotifizzClient($authSecretKey, $sdkSecretKey)` | Create a client. |
| `$client->track(['eventName', 'sdkSecretKey', 'properties'])` | Start tracking an event; returns a context. |
| `$context->workflow($campaignId, $recipients)` | Attach a workflow and recipients (chainable). |
| `$context->send()` | Send the tracked event (call after workflow()). |
| `$client->generateHashedToken($userId)` | Generate a hashed token for the user. |
| `NotifizzClient::configureEnrich($workflowIds, $fn)` | Register an enrichment function for workflow(s). |
| `$client->send(['notifId', 'properties'])` | Send a notification to the Notification Center. |
| `$client->config(['baseUrl'?, 'autoSendDelayMs'?])` | Configure options. |

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
