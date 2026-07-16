<?php

namespace Notifizz;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;

/**
 * Official Notifizz SDK for PHP.
 *
 * Covers the full backend surface:
 *  - Tracking — {@see track()} with local schema validation (soft/strict),
 *    idempotency-key derivation and transient-failure retries.
 *  - Enrichers — {@see enricher()} / {@see enrichProfileData()} register handlers;
 *    {@see dispatch()} is the one-line webhook entry point the Notifizz backend
 *    calls for discovery and execution.
 *  - Event catalog — {@see declareEvent()} declares events + schemas, surfaced
 *    through the same discovery dispatch.
 *  - Audience — {@see identify()} / {@see detach()} link and unlink Subjects.
 *  - Subscriptions — {@see subscribe()} / {@see unsubscribe()} / {@see notifySubscribers()}.
 *  - Widget auth — {@see generateHashedToken()} / {@see generateSubscribeToken()}.
 */
class NotifizzClient
{
    private const DEFAULT_BASE_URL = 'https://api.notifizz.com/v1';
    private const RETRY_DELAYS_MS = [1000, 2000];
    /** Reserved name of the system enricher backing {@see enrichProfileData()}. */
    private const PROFILE_RESOLVER_ENRICHER = 'notifizz:profileResolver';

    private string $authSecretKey;
    private string $sdkSecretKey;
    private ?string $webhookSigningSecret;
    private string $algorithm = 'sha256';
    private string $schemaMode;
    /** @var callable|null */
    private $onError;

    private array $options = [];
    private EnricherRegistry $enricherRegistry;
    private EventRegistry $eventRegistry;
    private ?Dispatcher $dispatcher;

    /** @var array<string, bool> per-process guard so the boot heartbeat fires once. */
    private static array $heartbeatSent = [];

    /**
     * @param string      $authSecretKey        used by generateHashedToken/generateSubscribeToken for widget auth.
     * @param string      $sdkSecretKey         used by the tracking API + identity/subscription calls.
     * @param string|null $webhookSigningSecret required to expose enrichers — verifies the HMAC in {@see dispatch()}.
     * @param array       $clientOptions        optional: ['schemaMode' => 'soft'|'strict', 'onError' => callable].
     *                                          schemaMode is overridden by env NOTIFIZZ_SCHEMA_MODE at boot.
     */
    public function __construct(
        string $authSecretKey,
        string $sdkSecretKey,
        ?string $webhookSigningSecret = null,
        array $clientOptions = []
    ) {
        $this->authSecretKey = $authSecretKey;
        $this->sdkSecretKey = $sdkSecretKey;
        $this->webhookSigningSecret = $webhookSigningSecret;
        $this->schemaMode = self::resolveSchemaMode($clientOptions);
        $this->onError = (isset($clientOptions['onError']) && is_callable($clientOptions['onError']))
            ? $clientOptions['onError']
            : null;
        $this->enricherRegistry = new EnricherRegistry();
        $this->eventRegistry = new EventRegistry();
        $this->dispatcher = $webhookSigningSecret !== null
            ? new Dispatcher($this->enricherRegistry, $this->eventRegistry, $webhookSigningSecret)
            : null;
    }

    // ── Frontend widget auth ──────────────────────────────────────────────

    private function sha256(string $textToHash): string
    {
        return hash($this->algorithm, $textToHash);
    }

    public function generateHashedToken(string $userId): string
    {
        return $this->sha256($userId . $this->authSecretKey);
    }

    /**
     * Generate a subscribe token for the Subscribe widget (secure mode).
     * HMAC-SHA256 binding subscriber + resource. Must be generated server-side.
     */
    public function generateSubscribeToken(string $subscriberId, string $resourceId): string
    {
        return hash_hmac($this->algorithm, $subscriberId . $resourceId, $this->authSecretKey);
    }

    // ── Enrichers ─────────────────────────────────────────────────────────

    /**
     * Register an enricher on this client. Returns $this for chaining.
     *
     * @param array{description?:string,input:array,output:array,cache?:mixed,handler:callable} $options
     */
    public function enricher(string $name, array $options): self
    {
        $this->enricherRegistry->register($name, $options);
        return $this;
    }

    /**
     * Register the system enricher for audience profile data under the reserved
     * name `notifizz:profileResolver`. The input is forced to `{ id?, email? }`;
     * the output is free-form.
     *
     * @param callable          $handler     fn(array $params): array
     * @param string|null       $description optional description.
     * @param false|int|null    $cache       false (never cache), int TTL seconds, or null (Notifizz default).
     */
    public function enrichProfileData(callable $handler, ?string $description = null, $cache = null): self
    {
        $input = JsonSchema::object()
            ->prop('id', JsonSchema::string(), false)
            ->prop('email', JsonSchema::string(), false)
            ->build();

        $options = [
            'description' => $description ?? 'Notifizz Audience — profile resolver',
            'input' => $input,
            'output' => ['type' => 'object', 'additionalProperties' => true],
            'handler' => $handler,
        ];
        if ($cache === false) {
            $options['cache'] = false;
        } elseif (is_int($cache)) {
            $options['cache'] = ['ttl' => $cache];
        }
        $this->enricherRegistry->register(self::PROFILE_RESOLVER_ENRICHER, $options);
        return $this;
    }

    /** Discovery view of the registered enrichers. */
    public function enrichers(): array
    {
        return $this->enricherRegistry->buildDiscovery();
    }

    // ── Event catalog ─────────────────────────────────────────────────────

    /**
     * Declare an event (and optional schema) in the catalog. When a schema is
     * present, {@see track()} validates payloads against it per the configured
     * schema mode.
     *
     * @param array{description:string,schema?:array,idempotencyFields?:string[]} $options
     * @return string the canonical (trimmed) event name.
     */
    public function declareEvent(string $name, array $options): string
    {
        return $this->eventRegistry->register($name, $options);
    }

    /** Discovery view of the declared events. */
    public function declaredEvents(): array
    {
        return $this->eventRegistry->buildDiscovery();
    }

    // ── Webhook entry point ───────────────────────────────────────────────

    /**
     * Pure body-in / body-out webhook entry point. Wire it in a one-line controller:
     *
     *     return $notifizz->dispatch($request->getParsedBody());
     *
     * Accepts the parsed body (array) or a raw JSON string. Verifies the HMAC over
     * the inner payload, rejects stale timestamps (±5 min), and routes discovery
     * vs enricher execution. Errors are returned in the body
     * (['ok' => false, 'error' => [...]]); the framework always writes 200.
     *
     * @param array|string $body
     * @throws \RuntimeException if $webhookSigningSecret was not provided.
     */
    public function dispatch($body): array
    {
        if ($this->dispatcher === null) {
            throw new \RuntimeException(
                'NotifizzClient::dispatch() requires webhookSigningSecret to be set in the constructor. '
                . 'Pass it as the third argument: new NotifizzClient($authKey, $sdkKey, $webhookSigningSecret)'
            );
        }
        return $this->dispatcher->dispatch($body);
    }

    /** Hex HMAC-SHA256(signingSecret, payload) — the body-only dispatch signing scheme. */
    public static function signDispatchPayload(string $signingSecret, string $payload): string
    {
        return Hmac::signHex($signingSecret, $payload);
    }

    // ── Tracking ──────────────────────────────────────────────────────────

    /**
     * Track an event. The Notifizz backend resolves campaigns by `eventName` and
     * runs each campaign's orchestrator server-side — no client-side targeting.
     *
     * When omitted, the idempotency key is derived from the declared
     * `idempotencyFields` (if any), else a random one is generated. Retries
     * transient failures (5xx / network); never retries 4xx (client errors) —
     * that would mask the rejection behind the server's idempotency replay.
     *
     * @throws TrackRejectedException when schemaMode === 'strict' and the event is rejected locally.
     */
    public function track(string $eventName, array $properties = [], ?string $idempotencyKey = null): void
    {
        $this->maybeHeartbeat();

        $validatedName = $this->validateForTrack($eventName, $properties);
        $reg = $this->eventRegistry->get($validatedName);
        $resolvedKey = $idempotencyKey
            ?? $this->deriveIdempotencyKey($validatedName, $reg, $properties)
            ?? bin2hex(random_bytes(16));

        $baseUrl = $this->options['baseUrl'] ?? self::DEFAULT_BASE_URL;
        $payload = [
            'eventName' => $validatedName,
            // Cast to object so empty properties serialise as `{}` rather than `[]`.
            'properties' => (object) $properties,
            'sdkSecretKey' => $this->sdkSecretKey,
            'idempotencyKey' => $resolvedKey,
        ];

        $client = new Client();
        $lastError = null;
        $maxRetries = count(self::RETRY_DELAYS_MS);

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $client->post("{$baseUrl}/events/track", [
                    'headers' => [
                        'Authorization' => "Bearer {$this->sdkSecretKey}",
                        'Content-Type' => 'application/json',
                        'X-Idempotency-Key' => $resolvedKey,
                    ],
                    'json' => $payload,
                    'http_errors' => false,
                ]);
                $code = $response->getStatusCode();
                if ($code >= 200 && $code < 300) {
                    return;
                }
                if ($code >= 400 && $code < 500) {
                    // Client error (auth, validation): retrying just makes the server's
                    // idempotency layer return a misleading 2xx on the retry, masking
                    // the original rejection. Fail fast — propagates past the retry catch.
                    throw new \RuntimeException("Notifizz track rejected (status {$code})");
                }
                $lastError = new \RuntimeException("Notifizz track failed (status {$code})");
            } catch (ConnectException $e) {
                $lastError = $e; // network failure — retryable
            }
            if ($attempt < $maxRetries) {
                usleep(self::RETRY_DELAYS_MS[$attempt] * 1000);
            }
        }

        throw $lastError;
    }

    // ── Identity (Audience) ───────────────────────────────────────────────

    /**
     * Link two Subjects to the same Audience. Expected keys: environmentId,
     * subjectA {type, identifier}, subjectB {type, identifier}, consentBasis,
     * optional expiresAt.
     *
     * @return array response body ({ action, audienceId? }).
     */
    public function identify(array $params): array
    {
        $baseUrl = $this->options['baseUrl'] ?? self::DEFAULT_BASE_URL;
        return $this->postJson("{$baseUrl}/identity/identify", $params);
    }

    /**
     * Remove a Subject from its Audience back into a solo Audience. Expected keys:
     * environmentId, subject {type, identifier}.
     *
     * @return array response body ({ action, subjectId? }).
     */
    public function detach(array $params): array
    {
        $baseUrl = $this->options['baseUrl'] ?? self::DEFAULT_BASE_URL;
        return $this->postJson("{$baseUrl}/identity/detach", $params);
    }

    // ── Subscriptions ─────────────────────────────────────────────────────

    /**
     * Subscribe a user to a resource. Idempotent.
     *
     * @return array response body
     */
    public function subscribe(string $resourceId, string $subscriberId, array $meta = []): array
    {
        $baseUrl = $this->options['baseUrl'] ?? self::DEFAULT_BASE_URL;
        $url = "{$baseUrl}/subscriptions/" . urlencode($resourceId) . "/subscribe";

        $client = new Client();
        $response = $client->post($url, [
            'headers' => [
                'Authorization' => "Bearer {$this->sdkSecretKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => array_merge(['subscriberId' => $subscriberId], $meta),
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Unsubscribe a user from a resource. No-op if not subscribed.
     *
     * @return array response body
     */
    public function unsubscribe(string $resourceId, string $subscriberId): array
    {
        $baseUrl = $this->options['baseUrl'] ?? self::DEFAULT_BASE_URL;
        $url = "{$baseUrl}/subscriptions/" . urlencode($resourceId) . "/unsubscribe";

        $client = new Client();
        $response = $client->delete($url, [
            'headers' => [
                'Authorization' => "Bearer {$this->sdkSecretKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => ['subscriberId' => $subscriberId],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Notify all subscribers of a resource. Wrapper around track('subscription-entered', ...).
     */
    public function notifySubscribers(
        string $resourceId,
        array $properties = [],
        ?string $idempotencyKey = null
    ): void {
        $this->track(
            'subscription-entered',
            array_merge($properties, ['resourceId' => $resourceId]),
            $idempotencyKey
        );
    }

    public function config(array $opts): void
    {
        $this->options = array_merge($this->options, $opts);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** Validate name + payload per the configured schema mode; returns the canonical name. */
    private function validateForTrack(string $eventName, array $properties): string
    {
        try {
            $validated = EventName::validate($eventName);
        } catch (InvalidEventNameException $e) {
            $reason = ['kind' => 'name-invalid', 'reason' => $e->getReason()];
            if ($this->schemaMode === 'strict') {
                $this->handleStrictRejection($eventName, $reason, $properties);
                throw new TrackRejectedException($eventName, $reason);
            }
            error_log("[notifizz] Event name '{$eventName}' is invalid ({$e->getReason()}); "
                . "track will hit the server which will reject.");
            return $eventName;
        }

        $reg = $this->eventRegistry->get($validated);
        if ($reg === null) {
            if ($this->schemaMode === 'strict') {
                $reason = ['kind' => 'event-not-declared'];
                $this->handleStrictRejection($validated, $reason, $properties);
                throw new TrackRejectedException($validated, $reason);
            }
            error_log("[notifizz] Event '{$validated}' tracked but not declared. Add "
                . "\$notifizz->declareEvent('{$validated}', [...]) at boot to register it in the catalog.");
        } elseif ($reg['schema'] !== null) {
            $errors = JsonSchemaValidator::validate($reg['schema'], $properties);
            if (!empty($errors)) {
                $reason = ['kind' => 'payload-invalid', 'errors' => $errors];
                if ($this->schemaMode === 'strict') {
                    $this->handleStrictRejection($validated, $reason, $properties);
                    throw new TrackRejectedException($validated, $reason);
                }
                error_log("[notifizz] Event '{$validated}' payload does not match declared schema; "
                    . "pushing as-is (soft mode).");
            }
        }
        return $validated;
    }

    /** Derive sha256(eventName . '|' . sortedFields=values) sliced to 32 chars when idempotencyFields is declared. */
    private function deriveIdempotencyKey(string $eventName, ?array $reg, array $properties): ?string
    {
        if ($reg === null || empty($reg['idempotencyFields'])) {
            return null;
        }
        $ordered = $reg['idempotencyFields'];
        sort($ordered);
        $parts = [];
        foreach ($ordered as $field) {
            if (!array_key_exists($field, $properties) || $properties[$field] === null) {
                return null; // a hash with a missing field would collide across different events
            }
            $value = $properties[$field];
            $repr = is_string($value) ? $value : json_encode($value);
            $parts[] = "{$field}={$repr}";
        }
        return substr(hash('sha256', $eventName . '|' . implode('&', $parts)), 0, 32);
    }

    /** @param array{kind:string, reason?:string, errors?:mixed} $reason */
    private function handleStrictRejection(string $eventName, array $reason, array $payload): void
    {
        if ($this->onError === null) {
            return;
        }
        try {
            ($this->onError)($eventName, $reason, $payload);
        } catch (\Throwable $e) {
            error_log("[notifizz] onError callback threw: " . $e->getMessage());
        }
    }

    private static function resolveSchemaMode(array $clientOptions): string
    {
        $env = getenv('NOTIFIZZ_SCHEMA_MODE');
        if ($env !== false) {
            $normalised = strtolower(trim($env));
            if ($normalised === 'soft' || $normalised === 'strict') {
                return $normalised;
            }
        }
        $configured = $clientOptions['schemaMode'] ?? null;
        if ($configured === 'soft' || $configured === 'strict') {
            return $configured;
        }
        return 'soft';
    }

    /**
     * Best-effort boot heartbeat, sent once per process on the first {@see track()}.
     * PHP has no deferred execution, so it runs inline with a short timeout; the
     * backend dedups, and the track path also records it server-side.
     */
    private function maybeHeartbeat(): void
    {
        if (isset(self::$heartbeatSent[$this->sdkSecretKey])) {
            return;
        }
        self::$heartbeatSent[$this->sdkSecretKey] = true;
        $baseUrl = $this->options['baseUrl'] ?? self::DEFAULT_BASE_URL;
        try {
            $client = new Client(['timeout' => 2.0, 'connect_timeout' => 1.0, 'http_errors' => false]);
            $client->post("{$baseUrl}/sdk/ping", ['json' => ['sdkSecretKey' => $this->sdkSecretKey]]);
        } catch (\Throwable $e) {
            // fire-and-forget
        }
    }

    private function postJson(string $url, array $body): array
    {
        $client = new Client();
        $response = $client->post($url, [
            'headers' => [
                'Authorization' => "Bearer {$this->sdkSecretKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ]);
        return json_decode($response->getBody()->getContents(), true);
    }
}
