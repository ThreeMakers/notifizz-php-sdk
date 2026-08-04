# Changelog

All notable changes to `notifizz/php` are documented in this file. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] — 2026-08-04

### Added

- **`track(..., occurredAt: ...)`** — when the BUSINESS event actually happened, as opposed to when Notifizz receives it. Accepts an ISO 8601 string or a `DateTimeInterface`; omitted from the request when you don't pass it. Named arguments keep the call readable:

  ```php
  $client->track('order.shipped', ['orderId' => $id], occurredAt: $shippedAt);
  ```

  Use it only when the two genuinely differ — replaying, batching offline, backfilling history. A date in the future is refused by the API (`event/invalid-occurred-at`, small clock skew tolerated).

## [2.0.0] — 2026-06-28 (Breaking)

The next release collapses the SDK around the flat `track()` shape and removes the channel-bypass surface. Both removals cascade from the event-driven model — emit an event, let a campaign decide channel and recipients server-side.

### Added — full parity with the Node SDK

The PHP SDK now covers the same backend surface as `@notifizz/nodejs`. No new Composer dependencies (the JSON Schema validation is built-in; HTTP keeps using Guzzle).

- **Enrichers** — `enricher($name, $options)`, `enrichProfileData($handler)`, `enrichers()`, and `dispatch($body)`: the one-line, HMAC-verified webhook entry point the Notifizz backend calls for discovery and enricher execution. Input is validated against the declared JSON Schema (`input-invalid`), anti-replay is enforced (`stale-timestamp`, ±5 min). `dispatch()` is wire-compatible with the backend's body-only `{ payload, signature }` envelope.
- **Event catalog** — `declareEvent($name, $options)` + `declaredEvents()`. When a schema is declared, `track()` validates the payload locally per `schemaMode` (`'soft'`/`'strict'`, overridable via `NOTIFIZZ_SCHEMA_MODE`); strict rejections throw `TrackRejectedException` and invoke the optional `onError` callback.
- **Idempotency** — `track()` derives a deterministic key from the declared `idempotencyFields` when no key is supplied.
- **Audience identity** — `identify($params)` and `detach($params)`.
- **Widget auth** — `generateSubscribeToken($subscriberId, $resourceId)` for the Subscribe widget.
- **Boot heartbeat** — best-effort `POST /sdk/ping`, sent once per process on the first `track()` (PHP has no deferred execution).
- **Helpers** — `JsonSchema` fluent builder and `NotifizzClient::signDispatchPayload($secret, $payload)`. The constructor accepts `$webhookSigningSecret` (3rd arg) and `$clientOptions` (4th arg: `schemaMode`, `onError`); the 2-arg form is unchanged.

### Fixed

- `track()` no longer retries on `4xx` responses (only `5xx` / network failures). A client-side rejection (auth, validation) is now surfaced instead of being masked by the server's idempotency replay returning a misleading `2xx` on retry.

### Breaking

- **Removed `$client->send(...)`** that posted to `POST /v1/notification/channel/notificationcenter/config/:configId/track` — the underlying route was removed from the backend in [ADR 2026-04-27](../../../architecture/adr/2026-04-27-remove-direct-notificationcenter-track.md). Migrate to `$client->track($eventName, $properties)` and configure the matching campaign on the dashboard:

  ```php
  // before
  $client->send([
      'notifId' => 'ncCfg_abc',
      'properties' => [
          'recipients' => [['id' => 'u1', 'email' => 'a@b.com']],
          'title' => 'Hello',
      ],
  ]);

  // after
  $client->track('user.welcomed', [
      'userId' => 'u1',
      'title' => 'Hello',
  ], 'u1:welcomed');
  ```

  The campaign listening on `user.welcomed` targets the same NC config previously addressed as `notifId`, and the orchestrator resolves recipients from `properties.userId`.

- **Removed the chained `$client->track($props)->workflow($slug, $recipients)` shape** ([ADR 2026-04-26](../../../architecture/adr/2026-04-26-remove-workflow-from-sdk.md), commit `245ad45e`). The flat shape is now the only API:

  ```php
  $client->track($eventName, $properties = [], ?string $idempotencyKey = null);
  ```

  The method retries transient failures twice (`1s`, then `2s`) before rethrowing the last error.

- **Removed the dead `configureEnrich(...)` static stub.** It stored callbacks in a static array that nothing ever invoked (no dispatch/handler consumed them). Real enricher serving now lives in `enricher(...)` + `dispatch(...)`.

### Migration

A typical migration is one method call per site. The hardest case is a `send()` call that targeted multiple distinct audiences in one go — model that as one event per audience and one campaign per audience.

## [1.3.x] and earlier

The Packagist history (versions `1.3.0` and earlier) is the authoritative source for that range. The flat `track()` shape with the optional `$idempotencyKey` argument, the retry behaviour (`[1000ms, 2000ms]`), and `generateHashedToken($userId)` landed across that line; the breaking change in the next major is the removal of `send()` and the chained shape.
