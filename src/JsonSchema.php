<?php

namespace Notifizz;

/**
 * Small fluent builder for the common JSON Schema shapes used to describe
 * enricher input/output and declared-event payloads. Produces a plain array —
 * the canonical schema representation the SDK ships on the wire. Power users can
 * build any schema array by hand and skip this helper.
 *
 * <code>
 * $schema = JsonSchema::object()
 *     ->prop('userId', JsonSchema::string()->minLength(1), true)
 *     ->prop('limit', JsonSchema::integer()->minimum(1), false)
 *     ->build();
 * </code>
 */
final class JsonSchema
{
    private array $node;
    private bool $isObject = false;

    private function __construct(string $type)
    {
        $this->node = ['type' => $type];
    }

    public static function object(): self
    {
        $s = new self('object');
        $s->isObject = true;
        return $s;
    }

    public static function string(): self
    {
        return new self('string');
    }

    public static function number(): self
    {
        return new self('number');
    }

    public static function integer(): self
    {
        return new self('integer');
    }

    public static function boolean(): self
    {
        return new self('boolean');
    }

    public static function arrayOf(self $items): self
    {
        $s = new self('array');
        $s->node['items'] = $items->toArray();
        return $s;
    }

    /** Add a property to an object schema; $required marks it mandatory. */
    public function prop(string $name, self $schema, bool $required = false): self
    {
        if (!$this->isObject) {
            throw new \LogicException('prop() is only valid on JsonSchema::object()');
        }
        // Created lazily so an empty object schema serialises as {type:"object"}
        // rather than {"properties":[]} (an empty PHP array encodes to []).
        if (!isset($this->node['properties'])) {
            $this->node['properties'] = [];
        }
        $this->node['properties'][$name] = $schema->toArray();
        if ($required) {
            $this->node['required'][] = $name;
        }
        return $this;
    }

    public function minLength(int $n): self
    {
        $this->node['minLength'] = $n;
        return $this;
    }

    public function maxLength(int $n): self
    {
        $this->node['maxLength'] = $n;
        return $this;
    }

    /** @param int|float $n */
    public function minimum($n): self
    {
        $this->node['minimum'] = $n;
        return $this;
    }

    /** @param int|float $n */
    public function maximum($n): self
    {
        $this->node['maximum'] = $n;
        return $this;
    }

    /** @param int|float $n */
    public function exclusiveMinimum($n): self
    {
        $this->node['exclusiveMinimum'] = $n;
        return $this;
    }

    public function minItems(int $n): self
    {
        $this->node['minItems'] = $n;
        return $this;
    }

    public function maxItems(int $n): self
    {
        $this->node['maxItems'] = $n;
        return $this;
    }

    public function pattern(string $regex): self
    {
        $this->node['pattern'] = $regex;
        return $this;
    }

    public function enumValues(array $values): self
    {
        $this->node['enum'] = $values;
        return $this;
    }

    /** Allow null in addition to the base type (emits type: [base, "null"]). */
    public function nullable(): self
    {
        if (is_string($this->node['type'])) {
            $this->node['type'] = [$this->node['type'], 'null'];
        }
        return $this;
    }

    /** On object schemas, reject properties not listed in `properties`. */
    public function additionalProperties(bool $allowed): self
    {
        $this->node['additionalProperties'] = $allowed;
        return $this;
    }

    public function toArray(): array
    {
        return $this->node;
    }

    /** Alias of {@see toArray()} for builder-call readability. */
    public function build(): array
    {
        return $this->node;
    }
}
