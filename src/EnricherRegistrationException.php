<?php

namespace Notifizz;

/**
 * Thrown at registration time by {@see NotifizzClient::enricher()} when the name
 * is empty, already registered on this client instance, or the options are
 * incomplete (missing handler / input / output). Mirrors the Node SDK
 * `EnricherRegistrationError`.
 */
class EnricherRegistrationException extends \RuntimeException
{
}
