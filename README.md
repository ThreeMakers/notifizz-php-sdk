# Notifizz PHP SDK

Official Notifizz SDK for PHP, published on [Packagist](https://packagist.org/packages/notifizz/php).

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
    'subjectA' => ['type' => 'AppUserSubject', 'identifier' => 'u_1'],
    'subjectB' => ['type' => 'EmailSubject', 'identifier' => 'a@b.com'],
]);

// A person withdrew their consent to open and click measurement:
$client->setMeasurementConsent([
    'subject' => ['type' => 'EmailSubject', 'identifier' => 'a@b.com'],
    'consented' => false,
]);

// ── Subscriptions ───────────────────────────────────────────────
// Subscriptions are created by the person's own click in the widget — there is no server-side subscribe.
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
| `$client->setMeasurementConsent($params)` | Pass on a person's consent to the measurement of their email opens and clicks, or its withdrawal. A refusal always wins. |
| `$client->notifySubscribers($resourceId, $properties = [])` | Notify all subscribers of a resource. |
| `$client->generateHashedToken($userId)` | Generate a widget auth token. |
| `$client->generateSubscribeToken($subscriberId, $resourceId)` | Generate a Subscribe-widget token. |
| `$client->config(['baseUrl'?])` | Configure options. |
