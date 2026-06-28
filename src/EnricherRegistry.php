<?php

namespace Notifizz;

/**
 * In-memory registry of enricher definitions for one {@see NotifizzClient}
 * instance. Stores registrations, enforces unique names at registration time,
 * and builds the `enrichers` array for the discovery response.
 *
 * @internal
 */
final class EnricherRegistry
{
    /** @var array<string, array{name:string,description:?string,input:array,output:array,cache:mixed,handler:callable}> */
    private array $registrations = [];

    public function register(string $name, array $options): void
    {
        if (trim($name) === '') {
            throw new EnricherRegistrationException('Enricher name must be a non-empty string');
        }
        if (isset($this->registrations[$name])) {
            throw new EnricherRegistrationException(
                "Enricher \"{$name}\" is already registered on this NotifizzClient instance"
            );
        }
        if (!isset($options['handler']) || !is_callable($options['handler'])) {
            throw new EnricherRegistrationException("Enricher \"{$name}\" handler must be a callable");
        }
        if (!isset($options['input']) || !is_array($options['input'])) {
            throw new EnricherRegistrationException("Enricher \"{$name}\" input schema must be provided");
        }
        if (!isset($options['output']) || !is_array($options['output'])) {
            throw new EnricherRegistrationException("Enricher \"{$name}\" output schema must be provided");
        }

        $this->registrations[$name] = [
            'name' => $name,
            'description' => $options['description'] ?? null,
            'input' => $options['input'],
            'output' => $options['output'],
            // cache may legitimately be `false`; only `null` means "not set".
            'cache' => array_key_exists('cache', $options) ? $options['cache'] : null,
            'handler' => $options['handler'],
        ];
    }

    public function get(string $name): ?array
    {
        return $this->registrations[$name] ?? null;
    }

    public function isEmpty(): bool
    {
        return count($this->registrations) === 0;
    }

    /** Discovery entries: [{ name, description?, input, output, cache? }]. */
    public function buildDiscovery(): array
    {
        $out = [];
        foreach ($this->registrations as $r) {
            $entry = ['name' => $r['name']];
            if ($r['description'] !== null) {
                $entry['description'] = $r['description'];
            }
            $entry['input'] = $r['input'];
            $entry['output'] = $r['output'];
            if ($r['cache'] !== null) {
                $entry['cache'] = $r['cache'];
            }
            $out[] = $entry;
        }
        return $out;
    }
}
