<?php

namespace Notifizz;

/**
 * Thrown when an event name fails the canonical grammar (empty, longer than
 * {@see EventName::MAX_LENGTH}, or containing non-printable / non-ASCII
 * characters). Mirrors the backend `validateEventName`.
 */
class InvalidEventNameException extends \RuntimeException
{
    /** @var string One of "empty", "too_long", "non_ascii". */
    private string $reason;

    public function __construct(string $reason)
    {
        parent::__construct("INVALID_EVENT_NAME: {$reason}");
        $this->reason = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
