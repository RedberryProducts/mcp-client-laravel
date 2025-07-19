<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Core\Transporters;

use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Transporter which communicates with an MCP server over STDIO.
 * The configured command is executed and the JSON-RPC payload is
 * provided via STDIN. The output from STDOUT is returned as the
 * decoded response.
 */
class StdioTransporter implements Transporter
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function request(string $action, array $params = []): array
    {
        $payload = $this->preparePayload($action, $params);
        $process = $this->createProcess(json_encode($payload));

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new TransporterRequestException(
                "STDIO process failed for {$action}: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }

        $output = trim($process->getOutput());
        $data = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new TransporterRequestException('Invalid JSON response: '.json_last_error_msg());
        }

        if (isset($data['error'])) {
            throw new TransporterRequestException(
                'JSON-RPC error: '.$data['error']['message'],
                $data['error']['code'] ?? 0
            );
        }

        return $data['result'] ?? $data;
    }

    private function createProcess(string $input): Process
    {
        $command = $this->config['command'] ?? null;
        if (! $command) {
            throw new \InvalidArgumentException('STDIO command is not defined.');
        }

        $cwd = $this->config['root_path'] ?? null;
        $timeout = $this->config['timeout'] ?? 60;

        return new Process($command, $cwd, null, $input, $timeout);
    }

    private function generateId(): string
    {
        return (string) random_int(1, 1000000);
    }

    private function preparePayload(string $action, ?array $params = null): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => $action,
            'params' => $params,
            'id' => $this->generateId(),
        ];
    }
}
