<?php

namespace Redberry\MCPClient;

class Collection implements \IteratorAggregate, \Countable
{
    private array $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function only(...$keys): static
    {
        $keys = is_array($keys[0]) ? $keys[0] : $keys;

        $this->items = array_filter($this->items, fn($item) => in_array($item['name'] ?? null, $keys));

        return $this;
    }

    public function except(...$keys): static
    {
        $keys = is_array($keys[0]) ? $keys[0] : $keys;

        $this->items = array_filter($this->items, fn($item) => !in_array($item['name'] ?? null, $keys));

        return $this;
    }

    public function all(): array
    {
        return array_values($this->items); // reindex
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function toArray(): array
    {
        return $this->all();
    }

    public function map(callable $callback): static
    {
        $this->items = array_map($callback, $this->items);
        return $this;
    }
}
