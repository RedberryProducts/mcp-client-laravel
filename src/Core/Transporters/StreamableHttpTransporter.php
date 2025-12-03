<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Core\Transporters;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Random\RandomException;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;

class StreamableHttpTransporter implements Transporter
{
    private GuzzleClient $client;

    private string $sessionId = '1';

    private bool $initialized = false;

    /**
     * @throws GuzzleException
     */
    public function __construct(private readonly array $config = [])
    {
        $this->initializeClient();
    }

    /**
     * Perform the “initialize” handshake and capture the MCP session ID.
     * Call this *once* before you start sending other RPCs.
     *
     * @throws GuzzleException
     * @throws RandomException
     */
    private function initializeSession(): void
    {
        if ($this->initialized) {
            return;
        }

        $payload = $this->preparePayload('initialize');

        $response = $this->client->request('POST', '', [
            'json' => $payload,
            'timeout' => $this->config['timeout'] ?? 30,
            'stream' => false,
        ]);

        $hdr = $response->getHeader('mcp-session-id');
        if (! empty($hdr)) {
            $this->sessionId = $hdr[0];
        }

        $this->initialized = true;
    }

    /**
     * @throws TransporterRequestException
     * @throws GuzzleException
     * @throws JsonException
     * @throws RandomException
     */
    public function request(string $action, ?array $params = null): array
    {
        $this->initializeSession();
        $payload = $this->preparePayload($action, $params);

        try {
            $response = $this->client->request('POST', '', [
                'json' => $payload,
                'timeout' => $this->config['timeout'] ?? 30,
                'headers' => [
                    'mcp-session-id' => $this->sessionId,
                    'Accept' => 'application/json, text/event-stream',
                ],
                'stream' => true,
            ]);

            return $this->parseResponse($response);
        } catch (GuzzleException $e) {
            throw new TransporterRequestException(
                "HTTP error for $action: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * @throws TransporterRequestException
     * @throws JsonException
     */
    private function parseResponse(ResponseInterface $response): array
    {
        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

        if ($contentType === 'text/event-stream') {
            return $this->parseSseStream($response);
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new TransporterRequestException('Invalid JSON response: '.json_last_error_msg());
        }

        if (isset($data['error'])) {
            throw new TransporterRequestException(
                "JSON-RPC error: {$data['error']['message']}",
                $data['error']['code'] ?? 0
            );
        }

        return $data['result'] ?? $data;
    }

    /**
     * @throws TransporterRequestException
     * @throws JsonException
     */
    private function parseSseStream(ResponseInterface $response): array
    {
        $stream = $response->getBody();

        $buffer = '';
        $currentEvent = [
            'event' => null,
            'data' => '',
        ];

        $final = null;

        while (! $stream->eof()) {
            $chunk = $stream->read($this->config['stream_read_bytes'] ?? 8192);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $line = rtrim($line, "\r");

                if ($line === '') {
                    $maybe = $this->finishSseEvent($currentEvent);
                    if ($maybe !== null) {
                        $final = $maybe;
                    }
                    $currentEvent = ['event' => null, 'data' => ''];

                    continue;
                }

                if (str_starts_with($line, ':')) {
                    continue;
                }

                if (str_starts_with($line, 'event:')) {
                    $currentEvent['event'] = trim(substr($line, strlen('event:')));

                    continue;
                }

                if (str_starts_with($line, 'data:')) {
                    $piece = substr($line, strlen('data:'));
                    $piece = ltrim($piece, ' ');

                    $currentEvent['data'] .= ($currentEvent['data'] === '' ? '' : "\n").$piece;
                }
            }
        }

        $maybe = $this->finishSseEvent($currentEvent);
        if ($maybe !== null) {
            $final = $maybe;
        }

        if ($final === null) {
            throw new TransporterRequestException('Stream ended without a JSON-RPC result.');
        }

        return $final;
    }

    /**
     * @throws TransporterRequestException
     * @throws JsonException
     */
    private function finishSseEvent(array $evt): ?array
    {
        $dataStr = trim($evt['data'] ?? '');
        if ($dataStr === '' || $dataStr === '[DONE]') {
            return null;
        }

        $decoded = json_decode($dataStr, true, 512, JSON_THROW_ON_ERROR);

        if (isset($decoded['error'])) {
            throw new TransporterRequestException(
                "JSON-RPC error: {$decoded['error']['message']}",
                $decoded['error']['code'] ?? 0
            );
        }

        if (array_key_exists('result', $decoded)) {
            return $decoded['result'] ?? $decoded;
        }

        return $decoded;
    }

    /**
     * @throws RandomException
     */
    private function generateId(): string|int
    {
        $id = random_int(1, 1000000);
        $idType = $this->config['id_type'] ?? 'int';

        return $idType === 'integer' || $idType === 'int' ? $id : (string) $id;
    }

    /**
     * @throws RandomException
     */
    private function preparePayload(string $action, ?array $params = null): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => $action,
            'params' => $params ?? (object) [],
            'id' => $this->generateId(),
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
            $headers['Authorization'] = 'Bearer '.$token;
        }

        if (isset($this->config['headers']) && is_array($this->config['headers'])) {
            $headers = array_merge($headers, $this->config['headers']);
        }

        return [
            'base_uri' => $baseUri,
            'headers' => $headers,
        ];
    }

    /**
     * @throws GuzzleException
     */
    private function initializeClient(): void
    {
        $clientConfig = $this->getClientBaseConfig();
        $this->client = new Client($clientConfig);
    }
}
