<?php

namespace Redberry\MCPClient;

use Redberry\MCPClient\Contracts\MCPClient as IMCPClient;
use Redberry\MCPClient\Core\TransporterFactory;
use Redberry\MCPClient\Core\Transporters\Transporter;

class MCPClient implements IMCPClient
{
    private array $config;

    private array $serverConfig;

    private Transporter $transporter;

    /**
     * Connects to a specified MCP server.
     */
    public function __construct(
        array $config,
        private readonly TransporterFactory $factory = new TransporterFactory
    ) {
        $this->config = $config;
    }

    public function connect(string $serverName): IMCPClient
    {
        $this->serverConfig = $this->config[$serverName] ?? null;

        $this->ensureConfigurationValidity();

        $this->transporter = $this->getTransporter($this->serverConfig);

        return $this;
    }

    /**
     * Fetches tools from the connected MCP server.
     *
     * @throws \Exception
     */
    public function tools(): Collection
    {
        $this->ensureConfigurationValidity();

        $tools = $this->transporter->request('tools/list');
        $tools = $tools['tools'] ?? $tools;

        return new Collection($tools);
    }


    public function callTool(string $toolName, mixed $params = []): mixed
    {
        $requestParams = [
            'name' => $toolName,
            'arguments' => (object) $params,
        ];

        return $this->transporter->request('tools/call', $requestParams);
    }

    /**
     * Fetches resources from the connected MCP server.
     *
     * @throws \Exception
     */
    public function resources(): Collection
    {
        $this->ensureConfigurationValidity();

        $resources = $this->transporter->request('resources/list');
        $resources = $resources['resources'] ?? $resources;

        return new Collection($resources);
    }

    private function getTransporter(array $config): Transporter
    {
        return $this->factory->make($config);
    }

    private function ensureConfigurationValidity(): void
    {
        if (empty($this->serverConfig)) {
            throw new \RuntimeException('Server configuration is not set. Please connect to a server first.');
        }
    }
}
