<?php

namespace Redberry\MCPClient;

class Collection implements \Countable, \IteratorAggregate
{
    private array $items;

    private string $matchKey;

    /**
     * @param  string  $matchKey  Field on each item that `only()` / `except()` filter on. MCP tools
     *                            are keyed by `name`; resources by `uri`. The key is propagated
     *                            through `only` / `except` / `map`.
     */
    public function __construct(array $items, string $matchKey = 'name')
    {
        $this->items = $items;
        $this->matchKey = $matchKey;
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

    public function only(...$keys): Collection
    {
        // Handle null or empty keys by returning an empty collection
        $keys = is_array($keys[0] ?? null) ? $keys[0] : $keys;
        if (empty($keys) || $keys === [null]) {
            return new Collection([], $this->matchKey);
        }

        $filtered = array_filter(
            $this->items,
            fn ($item) => in_array($item[$this->matchKey] ?? null, $keys, true)
        );

        return new Collection($filtered, $this->matchKey);
    }

    public function except(...$keys): Collection
    {
        // Handle null or empty keys by returning all items
        $keys = is_array($keys[0] ?? null) ? $keys[0] : $keys;
        if (empty($keys) || $keys === [null]) {
            return new Collection($this->items, $this->matchKey);
        }

        $filtered = array_filter(
            $this->items,
            fn ($item) => ! in_array($item[$this->matchKey] ?? null, $keys, true)
        );

        return new Collection($filtered, $this->matchKey);
    }

    public function map(callable $callback): Collection
    {
        return new Collection(array_map($callback, $this->items), $this->matchKey);
    }
}
