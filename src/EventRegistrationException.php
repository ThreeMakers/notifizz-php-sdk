<?php

namespace Notifizz;

/**
 * Thrown at registration time by {@see NotifizzClient::declareEvent()} on a
 * duplicate name, a missing description, or malformed `idempotencyFields`.
 * Mirrors the Node SDK `EventRegistrationError`.
 */
class EventRegistrationException extends \RuntimeException
{
}
