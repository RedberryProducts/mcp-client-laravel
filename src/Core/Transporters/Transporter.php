<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Core\Transporters;

/**
 * Interface Transport
 *
 * This interface defines the contract for transport mechanisms used to communicate with the MCP (Multi-Channel
 * Platform). Implementations of this interface should handle the specifics of sending requests and receiving
 * responses.
 */
interface Transporter
{
    /**
     * Send a JSON-RPC request and return the response.
     *
     * @param  string  $action  The JSON-RPC method (e.g., 'read_pr').
     * @param  array  $params  Parameters for the request.
     * @return array Decoded JSON-RPC response.
     *
     * @throws \Exception On transport failure.
     */
    public function request(string $action, array $params = []): array;
}
