<?php

namespace Redberry\MCPClient;

use Redberry\MCPClient\Contracts\MCPClient as IMCPClient;
use Redberry\MCPClient\Core\TransporterFactory;
use Redberry\MCPClient\Core\Transporters\Transporter;

class MCPClient implements IMCPClient
{
    private string $serverName;
    private array $serverConfig;

    private Transporter $transporter;


    /**
     * Connects to a specified MCP server.
     *
     * @param  TransporterFactory  $factory
     */
    public function __construct(
        private readonly TransporterFactory $factory = new TransporterFactory()
    ) {
    }

    public function connect(string $serverName): IMCPClient
    {
        $this->serverName = $serverName;
        $this->serverConfig = config('mcp-client.servers.'.$serverName);

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
