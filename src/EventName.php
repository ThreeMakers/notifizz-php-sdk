<?php

namespace Notifizz;

/**
 * Event-name contract — mirrors the backend `validateEventName` so a track with
 * an SDK-validated name will never be rejected at the API for grammar reasons.
 *
 * @internal
 */
final class EventName
{
    public const MAX_LENGTH = 120;

    /**
     * Trim + validate an event name. Returns the trimmed name on success.
     *
     * @throws InvalidEventNameException with reason "empty", "too_long" or "non_ascii".
     */
    public static function validate(?string $raw): string
    {
        $trimmed = trim($raw ?? '');
        if ($trimmed === '') {
            throw new InvalidEventNameException('empty');
        }
        if (strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidEventNameException('too_long');
        }
        // Printable ASCII range [0x20, 0x7E] — same as the backend regex /^[\x20-\x7E]+$/.
        if (!preg_match('/^[\x20-\x7E]+$/', $trimmed)) {
            throw new InvalidEventNameException('non_ascii');
        }
        return $trimmed;
    }
}
