# Notifizz PHP SDK

Official Notifizz SDK for PHP. The package is published on [Packagist](https://packagist.org/packages/notifizz/php) from the public repo [ThreeMakers/notifizz-php-sdk](https://github.com/ThreeMakers/notifizz-php-sdk).

## Installing the SDK

No custom repository or credentials are required; Packagist is used by default.

```bash
composer require notifizz/php
```

## Usage

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Notifizz\NotifizzClient;
use Notifizz\JsonSchema;

// The 3rd arg (webhook signing secret) is required to expose enrichers via dispatch().
$client = new NotifizzClient('your-auth-secret', 'your-sdk-secret', 'your-webhook-signing-secret');

// ── Track events ────────────────────────────────────────────────
// Campaign resolution + recipient building happen server-side off the
// orchestrator. No client-side workflow targeting.
$client->track('user_signed_up', ['plan' => 'pro', 'source' => 'landing_page']);
$client->track('user_signed_up', ['plan' => 'pro'], 'job-42'); // explicit idempotency key

// Declare an event + schema so the orchestrator knows its shape (and track()
// can validate payloads locally — see schema modes below).
$client->declareEvent('order.shipped', [
    'description' => 'Triggered when an order ships',
    'schema' => JsonSchema::object()
        ->prop('orderId', JsonSchema::string(), true)
        ->prop('userId', JsonSchema::string(), true)
        ->build(),
    'idempotencyFields' => ['orderId'],
]);

// ── Enrichers — fetch live business data at notification time ────
$client->enricher('enrichOrder', [
    'description' => 'Fetches an order by id',
    'input' => JsonSchema::object()->prop('orderId', JsonSchema::string()->minLength(1), true)->build(),
    'output' => JsonSchema::object()->prop('order', JsonSchema::object(), false)->build(),
    'cache' => ['ttl' => 600],
    'handler' => fn(array $params) => ['order' => $orders->find($params['orderId'])],
]);

// One-line webhook controller — Notifizz calls it for discovery + execution:
//   return $client->dispatch($request->getParsedBody());

// Audience profile resolver (the reserved notifizz:profileResolver enricher):
$client->enrichProfileData(fn(array $params) => ['name' => 'Alice', 'plan' => 'VIP']);

// ── Audience identity ───────────────────────────────────────────
$client->identify([
    'environmentId' => 'env_abc',
    'subjectA' => ['type' => 'AppUserSubject', 'identifier' => 'u_1'],
    'subjectB' => ['type' => 'EmailSubject', 'identifier' => 'a@b.com'],
    'consentBasis' => 'transactional',
]);

// ── Subscriptions ───────────────────────────────────────────────
$client->subscribe('proj_123', 'user_42');
$client->notifySubscribers('proj_123', ['title' => 'New comment']);

// ── Widget auth (generate tokens server-side) ───────────────────
$token = $client->generateHashedToken('user_123');
$subscribeToken = $client->generateSubscribeToken('user_42', 'proj_123');

// Optional: configure base URL
$client->config(['baseUrl' => 'https://api.notifizz.com/v1']);
```

### Schema modes (soft / strict)

When an event is declared with a schema, `track()` validates payloads locally.
In `soft` mode (default) a mismatch logs a warning and still pushes; in `strict`
mode it throws `TrackRejectedException` and does not push. Overridable at boot via
the `NOTIFIZZ_SCHEMA_MODE` env var.

```php
$client = new NotifizzClient('auth', 'sdk', 'signing', [
    'schemaMode' => 'strict',
    'onError' => fn($event, $reason, $payload) => error_log("rejected: {$event}"),
]);
```

## API summary

| Method | Description |
|--------|-------------|
| `new NotifizzClient($authKey, $sdkKey, $webhookSigningSecret = null, array $clientOptions = [])` | Create a client. |
| `$client->track($eventName, $properties = [], $idempotencyKey = null)` | Track an event (local schema validation, idempotency, retries). |
| `$client->declareEvent($name, $options)` | Declare an event + optional schema in the catalog. |
| `$client->declaredEvents()` | Discovery view of declared events. |
| `$client->enricher($name, $options)` | Register an enricher (input/output schema + handler + cache). |
| `$client->enrichProfileData($handler)` | Register the system profile-resolver enricher. |
| `$client->enrichers()` | Discovery view of registered enrichers. |
| `$client->dispatch($body)` | Webhook entry point — discovery + enricher execution (HMAC-verified). |
| `NotifizzClient::signDispatchPayload($secret, $payload)` | Compute the dispatch HMAC (testing helper). |
| `$client->identify($params)` / `$client->detach($params)` | Link / unlink Audience Subjects. |
| `$client->subscribe($resourceId, $subscriberId, $meta = [])` | Subscribe a user to a resource. |
| `$client->unsubscribe($resourceId, $subscriberId)` | Unsubscribe a user. |
| `$client->notifySubscribers($resourceId, $properties = [])` | Notify all subscribers of a resource. |
| `$client->generateHashedToken($userId)` | Generate a widget auth token. |
| `$client->generateSubscribeToken($subscriberId, $resourceId)` | Generate a Subscribe-widget token. |
| `$client->config(['baseUrl'?])` | Configure options. |

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
3. The workflow runs: syncs `sdk/back-end/php-sdk/` to the public repo, commits and pushes there, creates tag `v<version>` on the public repo, then triggers Packagist. Users get the new version with `composer update notifizz/php`.
