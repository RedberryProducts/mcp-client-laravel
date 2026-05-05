<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Core\Transporters;

use Redberry\MCPClient\Core\Exceptions\ServerConfigurationException;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
use Redberry\MCPClient\Core\Mcp;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class StdioTransporter implements Transporter
{
    private Process $process;

    private InputStream $inputStream;

    private int $requestId = 0;

    private const DEFAULT_REQUEST_TIMEOUT = 30; // seconds

    private const DEFAULT_STARTUP_DELAY = 100; // milliseconds

    private const DEFAULT_POLL_INTERVAL = 20; // milliseconds

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
            $startupDelay = $this->config['startup_delay'] ?? self::DEFAULT_STARTUP_DELAY;
            usleep($startupDelay * 1000); // convert milliseconds to microseconds
        } catch (\Throwable $e) {
            $this->cleanup();
            throw new TransporterRequestException(
                'Unable to start process: '.$e->getMessage(),
                0,
                $e
            );
        }

        if (! $this->process->isRunning()) {
            $this->handleStartupFailure();
        }

        $this->sendInitializeRequests();
    }

    public function request(string $action, array $params = [], ?callable $onEvent = null): array
    {
        $this->start();

        $id = (string) ++$this->requestId;
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $action,
            'params' => (object) $params,
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
        $env = $this->resolveEnv();

        $this->inputStream = new InputStream;
        $this->process = new Process(
            $this->command,
            $this->cwd,
            $env,
            $this->inputStream,
            $this->resolveProcessTimeout()
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
        $initPayloads = [
            [
                'jsonrpc' => '2.0',
                'id' => 'init',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => Mcp::PROTOCOL_VERSION,
                    'capabilities' => (object) [],
                    'clientInfo' => Mcp::clientInfo(),
                ],
            ],
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
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
     * Handles the failure of the process to start. Surfaces stderr/stdout in the
     * exception message so users can diagnose missing binaries, npm cache issues,
     * etc., without re-running with logging enabled.
     *
     * @throws TransporterRequestException
     */
    private function handleStartupFailure(): void
    {
        $cmd = $this->buildCommandLine();
        $exit = $this->process->getExitCode();
        $err = trim($this->process->getErrorOutput());
        $out = trim($this->process->getOutput());

        throw new TransporterRequestException(
            sprintf(
                'Process failed to start (exit code: %s). stderr: %s; stdout: %s. Command was: %s',
                $exit ?? 'unknown',
                $err === '' ? '<empty>' : $err,
                $out === '' ? '<empty>' : $out,
                $cmd
            )
        );
    }

    /**
     * @throws TransporterRequestException
     */
    protected function waitForResponse(string $id): array
    {
        $start = microtime(true);
        $timeout = $this->resolveRequestTimeout();
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

            $pollInterval = $this->config['poll_interval'] ?? self::DEFAULT_POLL_INTERVAL;
            usleep($pollInterval * 1000); // convert milliseconds to microseconds
        }

        throw new TransporterRequestException(
            sprintf(
                'Timeout after %s seconds waiting for response with id "%s".',
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
     * Resolves the per-request wait timeout in seconds.
     *
     * Order: explicit `request_timeout` → legacy `timeout` → default (30s).
     * A present-but-`null` value is treated as unset (falls through to the next
     * source) rather than `0` seconds, which would be an instant timeout.
     * Returned as a float so `request_timeout: 1.5` keeps working.
     */
    private function resolveRequestTimeout(): float
    {
        if (array_key_exists('request_timeout', $this->config) && $this->config['request_timeout'] !== null) {
            return (float) $this->config['request_timeout'];
        }

        if (array_key_exists('timeout', $this->config) && $this->config['timeout'] !== null) {
            return (float) $this->config['timeout'];
        }

        return (float) self::DEFAULT_REQUEST_TIMEOUT;
    }

    /**
     * Resolves the process kill-timer (Symfony Process arg #5).
     *
     * Defaults to `null` (kill timer disabled). Only honoured when explicitly set
     * via `process_timeout`; the legacy `timeout` key no longer doubles as a
     * total-runtime limit so long-lived sessions aren't killed mid-flight.
     */
    private function resolveProcessTimeout(): ?float
    {
        if (! array_key_exists('process_timeout', $this->config)) {
            return null;
        }

        $value = $this->config['process_timeout'];

        return $value === null ? null : (float) $value;
    }

    /**
     * Resolves the environment passed to the child process.
     *
     * - No `env` (or empty) and `inherit_env !== false` → `null`, letting Symfony
     *   `Process` inherit the parent env cleanly. Most servers want this.
     * - User `env` supplied with inheritance on → user keys merged on top of the
     *   parent env (`getenv()`). Avoids breaking `npx`/`node`, which need `HOME`,
     *   `PATH`, etc., to find npm caches and resolve modules.
     * - `inherit_env: false` → only the explicit user keys are forwarded (sealed
     *   env). Use for hermetic / locked-down execution.
     *
     * @return array<string, string>|null
     */
    private function resolveEnv(): ?array
    {
        $userEnv = $this->config['env'] ?? null;
        $inherit = $this->config['inherit_env'] ?? true;

        if ($inherit === false) {
            return is_array($userEnv) ? $userEnv : [];
        }

        if (! is_array($userEnv) || $userEnv === []) {
            return null;
        }

        return array_merge(getenv() ?: [], $userEnv);
    }
}
