<?php

namespace Notifizz;

/**
 * Pure body-in / body-out dispatcher exposed by {@see NotifizzClient::dispatch()}.
 *
 * Takes whatever the customer's framework parsed off the webhook request (an
 * array or a raw JSON string), decides what it is (discovery vs enricher
 * execution), authenticates it (HMAC over the `payload` string), validates input,
 * runs the registered handler when applicable, and returns a JSON-serialisable
 * response array. Errors are encoded in the body
 * (`['ok' => false, 'error' => ['code' => ..., 'message' => ...]]`), never thrown —
 * the customer's controller returns the response and the framework writes HTTP 200.
 *
 * @internal
 */
final class Dispatcher
{
    /** Anti-replay tolerance for the payload timestamp (±5 min, in ms). */
    private const TIMESTAMP_TOLERANCE_MS = 5 * 60 * 1000;

    private EnricherRegistry $enricherRegistry;
    private EventRegistry $eventRegistry;
    private string $signingSecret;

    public function __construct(EnricherRegistry $enricherRegistry, EventRegistry $eventRegistry, string $signingSecret)
    {
        $this->enricherRegistry = $enricherRegistry;
        $this->eventRegistry = $eventRegistry;
        $this->signingSecret = $signingSecret;
    }

    /** @param array|string $body */
    public function dispatch($body): array
    {
        if (is_string($body)) {
            $envelope = json_decode($body, true);
            if (!is_array($envelope)) {
                return self::error('envelope-invalid', 'Request body is not valid JSON');
            }
        } elseif (is_array($body)) {
            $envelope = $body;
        } else {
            return self::error('envelope-invalid', 'Request body must be a JSON object');
        }

        $payload = $envelope['payload'] ?? null;
        $signature = $envelope['signature'] ?? null;
        if (!is_string($payload) || !is_string($signature)) {
            return self::error('envelope-invalid', 'Envelope must have string `payload` and `signature` fields');
        }

        $expected = Hmac::signHex($this->signingSecret, $payload);
        if (!Hmac::safeEqualHex($signature, $expected)) {
            return self::error('hmac-invalid', 'Signature does not match the expected HMAC');
        }

        $inner = json_decode($payload, true);
        if (!is_array($inner)) {
            return self::error('envelope-invalid', 'Envelope `payload` is not valid JSON');
        }
        $ts = $inner['timestamp'] ?? null;
        if (!is_int($ts) && !is_float($ts)) {
            return self::error('envelope-invalid', 'Decoded payload missing numeric `timestamp`');
        }
        $nowMs = (int) round(microtime(true) * 1000);
        $drift = abs($nowMs - (int) $ts);
        if ($drift > self::TIMESTAMP_TOLERANCE_MS) {
            return self::error('stale-timestamp', "Payload timestamp is outside the tolerance window (drift={$drift}ms)");
        }

        $kind = $inner['kind'] ?? null;
        if ($kind === 'discovery') {
            return self::ok($this->buildDiscovery());
        }
        if ($kind === 'execute') {
            $name = $inner['name'] ?? null;
            if (!is_string($name) || $name === '') {
                return self::error('envelope-invalid', 'Execute payload missing string `name`');
            }
            return $this->executeEnricher($name, $inner['params'] ?? null);
        }
        return self::error('kind-unknown', 'Payload `kind` is not recognised: ' . var_export($kind, true));
    }

    private function buildDiscovery(): array
    {
        $result = ['enrichers' => $this->enricherRegistry->buildDiscovery()];
        if (!$this->eventRegistry->isEmpty()) {
            $result['events'] = $this->eventRegistry->buildDiscovery();
        }
        return $result;
    }

    private function executeEnricher(string $name, $params): array
    {
        $reg = $this->enricherRegistry->get($name);
        if ($reg === null) {
            return self::error(
                'enricher-not-found',
                "Enricher \"{$name}\" is not registered on this NotifizzClient instance"
            );
        }

        $errors = JsonSchemaValidator::validate($reg['input'], $params);
        if (!empty($errors)) {
            return self::errorWithDetails('input-invalid', "Enricher \"{$name}\" received invalid input", $errors);
        }

        try {
            $result = ($reg['handler'])(is_array($params) ? $params : []);
            return self::ok($result);
        } catch (\Throwable $e) {
            return self::error('handler-error', "Enricher \"{$name}\" handler threw: " . $e->getMessage());
        }
    }

    private static function ok($result): array
    {
        return ['ok' => true, 'result' => $result];
    }

    private static function error(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function errorWithDetails(string $code, string $message, $details): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message, 'details' => $details]];
    }
}
