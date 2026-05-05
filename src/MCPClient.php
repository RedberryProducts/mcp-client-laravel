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

    /** @var array<string, Transporter> */
    private array $transporterCache = [];

    public function __construct(
        array $config,
        private readonly TransporterFactory $factory = new TransporterFactory
    ) {
        $this->config = $config;
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

        $this->transporterCache[$serverName] ??= $this->factory->make($this->config[$serverName]);

        $clone = clone $this;
        $clone->transporter = $this->transporterCache[$serverName];

        return $clone;
    }

    /**
     * Fetches tools from the connected MCP server.
     */
    public function tools(): Collection
    {
        $this->ensureConnected('tools');

        $tools = $this->transporter->request('tools/list');
        $tools = $tools['tools'] ?? $tools;

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
     */
    public function resources(): Collection
    {
        $this->ensureConnected('resources');

        $resources = $this->transporter->request('resources/list');
        $resources = $resources['resources'] ?? $resources;

        return new Collection($resources, 'uri');
    }

    private function ensureConnected(string $method): void
    {
        if ($this->transporter === null) {
            throw new \RuntimeException("Call connect(\$serverName) before {$method}().");
        }
    }
}
