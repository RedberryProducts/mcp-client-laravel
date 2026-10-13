<?php

declare(strict_types=1);

namespace Redberry\MCPClient;

use Redberry\MCPClient\Contracts\MCPClient as IMCPClient;
use Redberry\MCPClient\Core\TransporterFactory;
use Redberry\MCPClient\Core\Transporters\Transporter;

class MCPClient implements IMCPClient
{
    private array $config;

    private ?Transporter $transporter = null;

    /**
     * Shared cache of transporters keyed by server name.
     *
     * Held in an ArrayObject so that `clone $this` (shallow copy) leaves every
     * clone pointing at the *same* cache instance. Caching `connect('a')`
     * from a clone is therefore visible to the root and all sibling clones.
     *
     * @var \ArrayObject<string, Transporter>
     */
    private \ArrayObject $transporterCache;

    /**
     * Server name this instance is connected to (null on the root client).
     */
    private ?string $serverName = null;

    /**
     * Shared cache of `tools/list` and `resources/list` results, keyed by
     * server name and list method.
     *
     * Shared across clones the same way as `$transporterCache`, so
     * `MCPClient::connect('github')->tools()` is answered from the cache on
     * the second call even though each `connect()` returns a fresh clone.
     *
     * @var \ArrayObject<string, array>
     */
    private \ArrayObject $listCache;

    private bool $cacheEnabled = true;

    public function __construct(
        array $config,
        private readonly TransporterFactory $factory = new TransporterFactory
    ) {
        $this->config = $config;
        /** @var \ArrayObject<string, Transporter> $cache */
        $cache = new \ArrayObject;
        $this->transporterCache = $cache;
        /** @var \ArrayObject<string, array> $listCache */
        $listCache = new \ArrayObject;
        $this->listCache = $listCache;
    }

    /**
     * Connect to a configured MCP server.
     *
     * Returns a clone configured for the requested server; the root client is
     * not mutated. Repeated `connect($name)` calls reuse a cached Transporter
     * keyed on the server name, so the `initialize` handshake is paid once per
     * server per root instance.
     */
    public function connect(string $serverName): IMCPClient
    {
        if (! array_key_exists($serverName, $this->config)) {
            $available = empty($this->config) ? '(none)' : implode(', ', array_keys($this->config));

            throw new \RuntimeException(
                "Unknown MCP server '{$serverName}'. Configured servers: {$available}."
            );
        }

        if (! $this->transporterCache->offsetExists($serverName)) {
            $this->transporterCache[$serverName] = $this->factory->make($this->config[$serverName]);
        }

        $clone = clone $this;
        $clone->transporter = $this->transporterCache[$serverName];
        $clone->serverName = $serverName;

        return $clone;
    }

    /**
     * Fetches tools from the connected MCP server.
     *
     * Results are cached per server; pass `fresh: true` to bypass the cache
     * and refresh it from the server.
     */
    public function tools(bool $fresh = false): Collection
    {
        $this->ensureConnected('tools');

        $tools = $this->cachedList('tools/list', $fresh, function (): array {
            $tools = $this->transporter->request('tools/list');

            return $tools['tools'] ?? $tools;
        });

        return new Collection($tools, 'name');
    }

    public function callTool(string $toolName, mixed $params = [], ?callable $onEvent = null): array
    {
        $this->ensureConnected('callTool');

        $requestParams = [
            'name' => $toolName,
            'arguments' => (object) $params,
        ];

        return $this->transporter->request('tools/call', $requestParams, $onEvent);
    }

    public function readResource(string $uri, ?callable $onEvent = null): array
    {
        $this->ensureConnected('readResource');

        $requestParams = [
            'uri' => $uri,
        ];

        return $this->transporter->request('resources/read', $requestParams, $onEvent);
    }

    /**
     * Fetches resources from the connected MCP server.
     *
     * Results are cached per server; pass `fresh: true` to bypass the cache
     * and refresh it from the server.
     */
    public function resources(bool $fresh = false): Collection
    {
        $this->ensureConnected('resources');

        $resources = $this->cachedList('resources/list', $fresh, function (): array {
            $resources = $this->transporter->request('resources/list');

            return $resources['resources'] ?? $resources;
        });

        return new Collection($resources, 'uri');
    }

    /**
     * Re-enable caching of `tools()` / `resources()` results on this instance
     * (and on clones subsequently returned by its `connect()`).
     */
    public function enableCache(): static
    {
        $this->cacheEnabled = true;

        return $this;
    }

    /**
     * Disable caching on this instance (and on clones subsequently returned by
     * its `connect()`): every `tools()` / `resources()` call hits the server
     * and nothing is written to the cache.
     */
    public function disableCache(): static
    {
        $this->cacheEnabled = false;

        return $this;
    }

    /**
     * Drop every cached `tools()` / `resources()` result, for all servers.
     */
    public function clearCache(): static
    {
        $this->listCache->exchangeArray([]);

        return $this;
    }

    /**
     * @param  callable(): array  $fetch
     */
    private function cachedList(string $method, bool $fresh, callable $fetch): array
    {
        if (! $this->cacheEnabled) {
            return $fetch();
        }

        $key = $this->getCacheKey($method);

        if (! $fresh && $this->listCache->offsetExists($key)) {
            return $this->listCache[$key];
        }

        $items = $fetch();
        $this->listCache[$key] = $items;

        return $items;
    }

    private function getCacheKey(string $method): string
    {
        return $this->serverName.'|'.$method;
    }

    private function ensureConnected(string $method): void
    {
        if ($this->transporter === null) {
            throw new \RuntimeException("Call connect(\$serverName) before {$method}().");
        }
    }
}
