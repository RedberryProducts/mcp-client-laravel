<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Core;

use Redberry\MCPClient\Core\Transporters\Transporter;

/**
 * Pool to manage and reuse transporter instances.
 * 
 * This class maintains a registry of active transporters to avoid creating
 * new instances for every connection to the same server, significantly
 * reducing latency for subsequent requests.
 */
class TransporterPool
{
    /**
     * @var array<string, Transporter>
     */
    private array $transporters = [];

    /**
     * Get or create a transporter for the given server.
     *
     * @param string $serverName The name of the server
     * @param array $config The server configuration
     * @return Transporter
     */
    public function get(string $serverName, array $config): Transporter
    {
        if (!isset($this->transporters[$serverName])) {
            $this->transporters[$serverName] = TransporterFactory::make($config);
        }

        return $this->transporters[$serverName];
    }

    /**
     * Remove a specific transporter from the pool.
     *
     * @param string $serverName The name of the server
     * @return void
     */
    public function forget(string $serverName): void
    {
        unset($this->transporters[$serverName]);
    }

    /**
     * Clear all transporters from the pool.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->transporters = [];
    }

    /**
     * Get list of active server names.
     *
     * @return array<int, string>
     */
    public function getActiveServers(): array
    {
        return array_keys($this->transporters);
    }
}
