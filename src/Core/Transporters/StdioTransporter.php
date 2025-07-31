<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Core\Transporters;

use Redberry\MCPClient\Core\Exceptions\ServerConfigurationException;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class StdioTransporter implements Transporter
{
    private Process $process;

    private InputStream $inputStream;

    private int $requestId = 0;

    private const PROTOCOL_VERSION = '2024-11-05';

    private const DEFAULT_TIMEOUT = 3;

    /** @var list<string> */
    private array $command;

    private ?string $cwd;

    private array $config;

    /**
     * @throws ServerConfigurationException
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->command = $config['command'] ?? [];
        $this->cwd = $config['cwd'] ?? null;

        $this->validateConfig();
    }

    public function __destruct()
    {
        $this->close();
    }

    protected function start(): void
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
            'params' => (object) $params ?: (object) [],
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

    protected function close(): void
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

    protected function initializeProcess(): void
    {
        $env = $this->getEnv();

        $this->inputStream = new InputStream;
        $this->process = new Process(
            $this->command,
            $this->cwd,
            $env,
            $this->inputStream,
            $env['timeout'] ?? self::DEFAULT_TIMEOUT
        );

        $this->process->setTty(false);
        $this->process->setPty(false);
    }

    private function buildCommandLine(): string
    {
        return implode(' ', array_map('escapeshellarg', $this->command));
    }

    protected function sendInitializeRequests(): void
    {
        $clientInfo = [
            'name' => 'laravel-mcp-client',
            'version' => '0.1.0',
        ];
        $initPayloads = [
            [
                'jsonrpc' => '2.0',
                'id' => 'init',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => (object) [],
                    'clientInfo' => $clientInfo,
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
        $cmd = $this->buildCommandLine();
        $exit = $this->process->getExitCode();
        $err = $this->process->getErrorOutput();
        $out = $this->process->getOutput();

        error_log("Failed to launch: $cmd (exit $exit). stderr: $err ; stdout: $out");

        throw new TransporterRequestException(
            sprintf(
                'Process failed to start (exit code: %s). Error: %s; Output: %s. Command was: %s',
                $exit, $err, $out, $cmd
            )
        );
    }

    /**
     * @throws TransporterRequestException
     */
    protected function waitForResponse(string $id): array
    {
        $env = $this->getEnv();
        $start = microtime(true);
        $timeout = $env['timeout'] ?? self::DEFAULT_TIMEOUT;
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

    protected function cleanup(): void
    {
        if (isset($this->process) && $this->process->isRunning()) {
            $this->process->stop();
        }

        unset($this->process, $this->inputStream);
    }

    /**
     * @return array|mixed
     */
    public function getEnv(): mixed
    {
        $env = $this->config['env'] ?? [];
        $env['PATH'] = getenv('PATH');

        return $env;
    }
}
