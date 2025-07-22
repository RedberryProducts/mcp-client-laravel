<?php

namespace Redberry\MCPClient\Core\Transporters;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;

class HttpTransporter implements Transporter
{
    private GuzzleClient $client;

    private string $sessionId;

    /**
     * @throws GuzzleException
     */
    public function __construct(private array $config = [])
    {
        $this->initializeClient();
    }

    /**
     * Perform the “initialize” handshake and capture the MCP session ID.
     * Call this *once* before you start sending other RPCs.
     *
     * @throws GuzzleException
     */
    private function initializeSession(): void
    {
        $payload = $this->preparePayload('initialize');
        $response = $this->client->request('POST', '', [
            'json' => $payload,
            'timeout' => $this->config['timeout'] ?? 30,
        ]);

        // Guzzle returns headers as arrays
        $hdr = $response->getHeader('mcp-session-id');
        if (! empty($hdr)) {
            $this->sessionId = $hdr[0];
        }
    }

    /**
     * @throws TransporterRequestException
     * @throws GuzzleException
     */
    public function request(string $action, ?array $params = null): array
    {
        $this->initializeSession();
        $payload = $this->preparePayload($action, $params);

        try {
            $response = $this->client->request('POST', $action, [
                'json' => $payload,
                'timeout' => $this->config['timeout'] ?? 30,
                'headers' => [
                    'mcp-session-id' => $this->sessionId,
                ],
            ]);
            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new TransporterRequestException('Invalid JSON response: '.json_last_error_msg());
            }

            if (isset($data['error'])) {
                throw new TransporterRequestException("JSON-RPC error: {$data['error']['message']}",
                    $data['error']['code']);
            }

            return $data['result'] ?? $data;
        } catch (GuzzleException $e) {
            throw new TransporterRequestException(
                "HTTP error for {$action}: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    private function generateId(): string
    {
        return (string) random_int(1, 1000000);
    }

    private function preparePayload(string $action, ?array $params = null)
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $action,
            'params' => $params,
            'id' => $this->generateId(),
        ];

        return $payload;
    }

    private function getClientBaseConfig(): array
    {
        $baseUri = $this->config['base_url'] ?? 'http://localhost/api';
        $token = $this->config['token'] ?? null;

        $clientConfig = [
            'base_uri' => rtrim($baseUri, '/').'/',    // ensure trailing slash
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];

        if ($token) {
            $clientConfig['headers']['Authorization'] = "Bearer {$token}";
        }

        return $clientConfig;
    }

    /**
     * @throws GuzzleException
     */
    private function initializeClient()
    {
        $clientConfig = $this->getClientBaseConfig();

        // finally, instantiate the client
        $this->client = new Client($clientConfig);
    }
}
