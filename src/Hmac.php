<?php

namespace Notifizz;

/**
 * Body-only HMAC scheme used by {@see NotifizzClient::dispatch()}.
 *
 * Notifizz signs `HMAC-SHA256(signingSecret, payload)` where `payload` is the
 * JSON-encoded inner request, included verbatim in the envelope. No timestamp
 * prefix, no header — the timestamp lives inside the signed payload.
 *
 * @internal
 */
final class Hmac
{
    /** Hex HMAC-SHA256(secret, payload). */
    public static function signHex(string $secret, string $payload): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /** Constant-time hex signature comparison (`hash_equals`). */
    public static function safeEqualHex(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
