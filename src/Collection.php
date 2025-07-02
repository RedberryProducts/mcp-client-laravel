<?php

namespace Redberry\MCPClient;

class Collection implements \Countable, \IteratorAggregate
{
    private array $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function all(): array
    {
        return array_values($this->items);
    }

    public function toArray(): array
    {
        return $this->all();
    }

    public function only(...$keys): static
    {
        // Handle null or empty keys by returning an empty collection
        $keys = is_array($keys[0] ?? null) ? $keys[0] : $keys;
        if (empty($keys) || $keys === [null]) {
            return new static([]);
        }

        $filtered = array_filter(
            $this->items,
            fn($item) => in_array($item['name'] ?? null, $keys, true)
        );
        return new static($filtered);
    }

    public function except(...$keys): static
    {
        // Handle null or empty keys by returning all items
        $keys = is_array($keys[0] ?? null) ? $keys[0] : $keys;
        if (empty($keys) || $keys === [null]) {
            return new static($this->items);
        }

        $filtered = array_filter(
            $this->items,
            fn($item) => !in_array($item['name'] ?? null, $keys, true)
        );
        return new static($filtered);
    }

    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }
}
