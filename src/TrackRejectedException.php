<?php

namespace Notifizz;

/**
 * Thrown by {@see NotifizzClient::track()} when `schemaMode === 'strict'` and a
 * track is refused locally (invalid name, undeclared event, or payload that does
 * not match the declared schema). Mirrors the Node SDK `TrackRejectedError`.
 */
class TrackRejectedException extends \RuntimeException
{
    private string $eventName;
    /** @var array{kind:string, reason?:string, errors?:mixed} */
    private array $reason;

    /**
     * @param array{kind:string, reason?:string, errors?:mixed} $reason
     */
    public function __construct(string $eventName, array $reason)
    {
        parent::__construct("[notifizz] track('{$eventName}') rejected by strict mode: {$reason['kind']}");
        $this->eventName = $eventName;
        $this->reason = $reason;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    /** @return array{kind:string, reason?:string, errors?:mixed} */
    public function getReason(): array
    {
        return $this->reason;
    }
}
