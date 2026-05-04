<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Core\Transporters;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
use Redberry\MCPClient\Core\Http\SseStreamParser;
use Redberry\MCPClient\Core\Mcp;

class HttpTransporter implements Transporter
{
    private GuzzleClient $client;

    private ?string $sessionId = null;

    private bool $initialized = false;

    public function __construct(
        private array $config = [],
        ?GuzzleClient $client = null,
    ) {
        $this->client = $client ?? new Client($this->getClientBaseConfig());
    }

    /**
     * Perform the "initialize" handshake and capture the MCP session id.
     * Called once before the first user-issued request. Per the MCP spec
     * (2025-03-26) we follow the initialize request with a
     * `notifications/initialized` notification before any user request.
     *
     * @throws TransporterRequestException
     */
    private function initializeSession(): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            $response = $this->client->request('POST', '', [
                'json' => $this->prepareInitializePayload(),
                'timeout' => $this->config['timeout'] ?? 30,
                'stream' => true,
                'headers' => $this->buildRequestHeaders(),
            ]);
        } catch (GuzzleException $e) {
            throw new TransporterRequestException(
                "HTTP error during initialize handshake: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }

        $hdr = $response->getHeader('mcp-session-id');
        if (! empty($hdr)) {
            $this->sessionId = $hdr[0];
        }

        try {
            $this->client->request('POST', '', [
                'json' => $this->prepareInitializedNotification(),
                'timeout' => $this->config['timeout'] ?? 30,
                'stream' => true,
                'headers' => $this->buildRequestHeaders(),
            ]);
        } catch (GuzzleException $e) {
            // Leave $initialized = false so the next request retries the handshake.
            $this->sessionId = null;
            throw new TransporterRequestException(
                "HTTP error sending notifications/initialized: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }

        $this->initialized = true;
    }

    /**
     * @throws TransporterRequestException
     * @throws GuzzleException
     * @throws JsonException
     */
    public function request(string $action, array $params = [], ?callable $onEvent = null): array
    {
        $this->initializeSession();
        $payload = $this->preparePayload($action, $params);

        try {
            $response = $this->client->request('POST', '', [
                'json' => $payload,
                'timeout' => $this->config['timeout'] ?? 30,
                'stream' => true,
                'headers' => $this->buildRequestHeaders(),
            ]);

            return $this->parseResponse($response, $onEvent);
        } catch (GuzzleException $e) {
            throw new TransporterRequestException(
                "HTTP error for {$action}: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * @throws TransporterRequestException
     * @throws JsonException
     */
    private function parseResponse(ResponseInterface $response, ?callable $onEvent): array
    {
        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

        if ($contentType === 'text/event-stream') {
            return SseStreamParser::parse($response->getBody(), $onEvent);
        }

        $body = (string) $response->getBody();

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TransporterRequestException('Invalid JSON response: '.$e->getMessage(), 0, $e);
        }

        if (isset($data['error'])) {
            throw new TransporterRequestException(
                "JSON-RPC error: {$data['error']['message']}",
                (int) ($data['error']['code'] ?? 0),
            );
        }

        // JSON-RPC `result` may be any JSON value (null, scalar, array, object). When it isn't an
        // array we hand back the full envelope so the typed `: array` return contract holds.
        if (\array_key_exists('result', $data) && \is_array($data['result'])) {
            return $data['result'];
        }

        return $data;
    }

    /**
     * Per-request headers. Always advertises both content types so the server
     * can content-negotiate between a single JSON response and an SSE stream.
     */
    private function buildRequestHeaders(): array
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];

        if ($this->sessionId !== null) {
            $headers['mcp-session-id'] = $this->sessionId;
        }

        return $headers;
    }

    private function generateId(): string|int
    {
        $id = random_int(1, 1000000);

        $idType = $this->config['id_type'] ?? 'int';

        return $idType === 'integer' || $idType === 'int' ? $id : (string) $id;
    }

    private function preparePayload(string $action, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => $action,
            // Empty params must JSON-encode as {} (object), not [] (array), so spec-strict servers accept them.
            'params' => $params === [] ? (object) [] : $params,
            'id' => $this->generateId(),
        ];
    }

    private function prepareInitializePayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $this->generateId(),
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => Mcp::PROTOCOL_VERSION,
                'capabilities' => (object) [],
                'clientInfo' => Mcp::clientInfo(),
            ],
        ];
    }

    private function prepareInitializedNotification(): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => (object) [],
        ];
    }

    private function getClientBaseConfig(): array
    {
        $baseUri = $this->config['base_url'] ?? 'http://localhost/api';
        $token = $this->config['token'] ?? null;

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($token) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        if (isset($this->config['headers']) && \is_array($this->config['headers'])) {
            $headers = array_merge($headers, $this->config['headers']);
        }

        return [
            'base_uri' => $baseUri,
            'headers' => $headers,
        ];
    }
}
