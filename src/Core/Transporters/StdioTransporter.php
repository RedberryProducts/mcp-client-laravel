<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Core\Transporters;

use Redberry\MCPClient\Core\Exceptions\ServerConfigurationException;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class StdioTransporter implements Transporter
{
    private Process $process;

    private InputStream $inputStream;

    private int $requestId = 0;

    private const PROTOCOL_VERSION = '2024-11-05';

    private const DEFAULT_TIMEOUT = 30;

    /** @var list<string> */
    private array $command;

    /** @var array<string, string> */
    private array $env;

    private ?string $cwd;

    public function __construct(array $config)
    {
        $this->command = $config['command'] ?? [];
        $this->env = $config['env'] ?? [];
        $this->cwd = $config['cwd'] ?? null;

        $this->validateConfig();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function start(): void
    {
        if (isset($this->process) && $this->process->isRunning()) {
            return;
        }

        $this->initializeProcess();

        try {
            $this->process->start();
            usleep(200_000);
        } catch (\Throwable $e) {
            $this->cleanup();
            throw new TransporterRequestException(
                'Unable to start process: '.$e->getMessage(),
                0,
                $e
            );
        }

        if (!$this->process->isRunning()) {
            $this->handleStartupFailure();
        }

        $this->sendInitializeRequests();
    }

    public function request(string $action, array $params = []): array
    {
        $this->start();

        $id = (string) ++$this->requestId;
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $action,
            'params' => $params ?: (object) [],
        ];

        $json = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )."\n";

        $this->process->clearOutput();
        $this->process->clearErrorOutput();
        $this->inputStream->write($json);

        return $this->waitForResponse($id);
    }

    public function close(): void
    {
        if (isset($this->inputStream)) {
            $this->inputStream->close();
        }

        if (isset($this->process) && $this->process->isRunning()) {
            $this->process->stop();
        }

        unset($this->process, $this->inputStream);
    }

    /**
     * Validates the configuration for the StdioTransporter.
     *
     * @throws ServerConfigurationException
     */
    private function validateConfig(): void
    {
        if ($this->command === []) {
            throw new ServerConfigurationException(
                'Configuration "command" must be a non-empty array.'
            );
        }
    }

    private function initializeProcess(): void
    {
        $this->inputStream = new InputStream;

        $process = Process::fromShellCommandline(
            $this->buildCommandLine(),
            $this->cwd,
            $this->env,
            $this->inputStream,
            $this->env['timeout'] ?? self::DEFAULT_TIMEOUT
        );

        $process->setTty(false);
        $process->setPty(false);

        $this->process = $process;
    }

    private function buildCommandLine(): string
    {
        return implode(' ', array_map('escapeshellarg', $this->command));
    }

    private function sendInitializeRequests(): void
    {
        $initPayloads = [
            [
                'jsonrpc' => '2.0',
                'id' => 'init',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => (object) [],
                ],
            ],
            [
                'jsonrpc' => '2.0',
                'method' => 'initialized',
                'params' => (object) [],
            ],
        ];

        foreach ($initPayloads as $payload) {
            $this->inputStream->write(
                json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )."\n"
            );
        }
    }

    /**
     *  Handles the failure of the process to start.
     *
     * @throws TransporterRequestException
     */
    private function handleStartupFailure(): void
    {
        $exitCode = $this->process->getExitCode();
        $errorOutput = $this->process->getErrorOutput();
        $output = $this->process->getOutput();

        $this->cleanup();

        throw new TransporterRequestException(
            sprintf(
                'Process failed to start (exit code: %s). Error: %s; Output: %s',
                $exitCode,
                $errorOutput,
                $output
            )
        );
    }

    /**
     * @throws TransporterRequestException
     */
    private function waitForResponse(string $id): array
    {
        $start = microtime(true);
        $timeout = $this->env['timeout'] ?? self::DEFAULT_TIMEOUT;
        $buffer = '';

        while ((microtime(true) - $start) < $timeout) {
            $buffer .= $this->process->getIncrementalOutput();

            if (str_contains($buffer, $id)) {
                $lines = array_filter(explode("\n", trim($buffer)));

                foreach ($lines as $line) {
                    try {
                        $data = json_decode(
                            $line,
                            true,
                            512,
                            JSON_THROW_ON_ERROR
                        );
                    } catch (\JsonException) {
                        continue;
                    }

                    if (($data['id'] ?? null) === $id) {
                        if (isset($data['error'])) {
                            $message = $data['error']['message'] ?? 'Unknown JSON-RPC error';
                            throw new TransporterRequestException('JSON-RPC error: '.$message);
                        }

                        return $data['result'] ?? [];
                    }
                }
            }

            usleep(50_000);
        }

        throw new TransporterRequestException(
            sprintf(
                'Timeout after %d seconds waiting for response with id "%s".',
                $timeout,
                $id
            )
        );
    }

    private function cleanup(): void
    {
        if (isset($this->process) && $this->process->isRunning()) {
            $this->process->stop();
        }

        unset($this->process, $this->inputStream);
    }
}
