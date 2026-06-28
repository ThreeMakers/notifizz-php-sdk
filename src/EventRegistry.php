<?php

namespace Notifizz;

/**
 * In-memory registry of SDK-declared events for one {@see NotifizzClient}
 * instance. Mirrors {@see EnricherRegistry} semantics and exposes an `events`
 * discovery payload pulled by the Notifizz backend alongside enrichers.
 *
 * @internal
 */
final class EventRegistry
{
    /** @var array<string, array{name:string,description:string,schema:?array,idempotencyFields:?array}> */
    private array $registrations = [];

    /** @return string the canonical (trimmed) event name. */
    public function register(string $name, array $options): string
    {
        $validatedName = EventName::validate($name);
        if (isset($this->registrations[$validatedName])) {
            throw new EventRegistrationException(
                "Event \"{$validatedName}\" is already declared on this NotifizzClient instance"
            );
        }
        if (!isset($options['description']) || !is_string($options['description']) || $options['description'] === '') {
            throw new EventRegistrationException("Event \"{$validatedName}\" must have a non-empty description");
        }

        $fields = null;
        if (isset($options['idempotencyFields'])) {
            if (!is_array($options['idempotencyFields'])) {
                throw new EventRegistrationException(
                    "Event \"{$validatedName}\" idempotencyFields must be an array of strings"
                );
            }
            foreach ($options['idempotencyFields'] as $f) {
                if (!is_string($f) || $f === '') {
                    throw new EventRegistrationException(
                        "Event \"{$validatedName}\" idempotencyFields must be non-empty strings"
                    );
                }
            }
            $fields = array_values($options['idempotencyFields']);
        }

        $this->registrations[$validatedName] = [
            'name' => $validatedName,
            'description' => $options['description'],
            'schema' => $options['schema'] ?? null,
            'idempotencyFields' => $fields,
        ];
        return $validatedName;
    }

    public function get(string $name): ?array
    {
        return $this->registrations[$name] ?? null;
    }

    public function isEmpty(): bool
    {
        return count($this->registrations) === 0;
    }

    /** Discovery entries: [{ name, description, schema?, idempotencyFields? }]. */
    public function buildDiscovery(): array
    {
        $out = [];
        foreach ($this->registrations as $r) {
            $entry = ['name' => $r['name'], 'description' => $r['description']];
            if ($r['schema'] !== null) {
                $entry['schema'] = $r['schema'];
            }
            if ($r['idempotencyFields'] !== null) {
                $entry['idempotencyFields'] = $r['idempotencyFields'];
            }
            $out[] = $entry;
        }
        return $out;
    }
}
