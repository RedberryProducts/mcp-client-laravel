<?php
declare(strict_types=1);

namespace Redberry\MCPClient\Core\Transporters;

use Redberry\MCPClient\Core\Transporters\Transporter as ITransporter;

/**
 * Stdio Transporter for MCPClient.
 * This transporter uses standard input/output to communicate with the MCP server.
 */
class StdioTransporter implements ITransporter
{
    /**
     * Send a request for a given action and parameters.
     *
     * @param  string  $action  The tool or resource name to call
     * @param  array  $params  Parameters for the request
     *
     * @return array Decoded response
     */
    public function request(string $action, array $params = []): array
    {
        // Implementation of standard input/output logic goes here.
        // This is a placeholder implementation.
        return [
            'action' => $action,
            'params' => $params,
            'response' => 'This is a mock response from StdioTransporter',
        ];
    }
}
