<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
use Redberry\MCPClient\Core\Transporters\HttpTransporter;

afterEach(function () {
    Mockery::close();
});

describe('HttpTransporter', function () {
    // Helper function to set up the transporter with a mocked client and session
    function createTransporterWithMockedSession($responseForInitialize = null)
    {
        $transporter = new HttpTransporter;
        $mockClient = Mockery::mock(Client::class);

        // Inject the mocked client
        $ref = new ReflectionClass($transporter);
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($transporter, $mockClient);

        // Set the sessionId to avoid uninitialized property errors
        $sessionProp = $ref->getProperty('sessionId');
        $sessionProp->setAccessible(true);
        $sessionProp->setValue($transporter, 'test-session-id');

        // Set initialized flag to true to skip initialization
        $initializedProp = $ref->getProperty('initialized');
        $initializedProp->setAccessible(true);
        $initializedProp->setValue($transporter, true);

        return [$transporter, $mockClient];
    }

    test('preparePayload builds correct payload', function () {
        $transporter = new HttpTransporter;
        $method = new ReflectionMethod(HttpTransporter::class, 'preparePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($transporter, 'testMethod', ['param1', 2]);

        expect($payload['jsonrpc'])->toBe('2.0');
        expect($payload['method'])->toBe('testMethod');
        expect($payload['params'])->toEqual(['param1', 2]);
        expect(is_string($payload['id']) && preg_match('/^\d+$/', $payload['id']))->toBeTrue();
    });

    test('generateId returns numeric string within range', function () {
        $transporter = new HttpTransporter;
        $gen = new ReflectionMethod(HttpTransporter::class, 'generateId');
        $gen->setAccessible(true);

        $id = $gen->invoke($transporter);

        expect(is_string($id))->toBeTrue();
        expect(preg_match('/^\d+$/', $id) === 1 && ((int) $id >= 1 && (int) $id <= 1000000))->toBeTrue();
    });

    test('generateId returns integer when id_type is integer', function () {
        $transporter = new HttpTransporter(['id_type' => 'integer']);
        $gen = new ReflectionMethod(HttpTransporter::class, 'generateId');
        $gen->setAccessible(true);

        $id = $gen->invoke($transporter);

        expect(is_int($id))->toBeTrue();
        expect($id >= 1 && $id <= 1000000)->toBeTrue();
    });

    test('generateId returns string by default', function () {
        $transporter = new HttpTransporter;
        $gen = new ReflectionMethod(HttpTransporter::class, 'generateId');
        $gen->setAccessible(true);

        $id = $gen->invoke($transporter);

        expect(is_string($id))->toBeTrue();
    });

    test('getClientBaseConfig has default values', function () {
        $transporter = new HttpTransporter;
        $method = new ReflectionMethod(HttpTransporter::class, 'getClientBaseConfig');
        $method->setAccessible(true);

        $config = $method->invoke($transporter);

        expect($config['base_uri'])->toBe('http://localhost/api/');
        expect($config['headers'])->toHaveKey('Accept', 'application/json');
        expect($config['headers'])->toHaveKey('Content-Type', 'application/json');
        expect(array_key_exists('Authorization', $config['headers']))->toBeFalse();
    });

    test('getClientBaseConfig respects base_url and token', function () {
        $transporter = new HttpTransporter([
            'base_url' => 'http://example.com/api',
            'token' => 'secret-token',
        ]);
        $method = new ReflectionMethod(HttpTransporter::class, 'getClientBaseConfig');
        $method->setAccessible(true);

        $config = $method->invoke($transporter);

        expect($config['base_uri'])->toBe('http://example.com/api/');
        expect($config['headers'])->toHaveKey('Authorization', 'Bearer secret-token');
    });

    test('getClientBaseConfig merges custom headers from config', function () {
        $transporter = new HttpTransporter([
            'headers' => [
                'X-Custom-Header' => 'custom-value',
                'X-API-Version' => '2.0',
            ],
        ]);
        $method = new ReflectionMethod(HttpTransporter::class, 'getClientBaseConfig');
        $method->setAccessible(true);

        $config = $method->invoke($transporter);

        expect($config['headers'])->toHaveKey('Accept', 'application/json');
        expect($config['headers'])->toHaveKey('Content-Type', 'application/json');
        expect($config['headers'])->toHaveKey('X-Custom-Header', 'custom-value');
        expect($config['headers'])->toHaveKey('X-API-Version', '2.0');
    });

    test('custom headers from config override default headers', function () {
        $transporter = new HttpTransporter([
            'headers' => [
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'text/plain',
            ],
        ]);
        $method = new ReflectionMethod(HttpTransporter::class, 'getClientBaseConfig');
        $method->setAccessible(true);

        $config = $method->invoke($transporter);

        expect($config['headers']['Accept'])->toBe('application/vnd.api+json');
        expect($config['headers']['Content-Type'])->toBe('text/plain');
    });

    test('custom headers can override Authorization header from token', function () {
        $transporter = new HttpTransporter([
            'token' => 'secret-token',
            'headers' => [
                'Authorization' => 'Custom auth-scheme',
            ],
        ]);
        $method = new ReflectionMethod(HttpTransporter::class, 'getClientBaseConfig');
        $method->setAccessible(true);

        $config = $method->invoke($transporter);

        expect($config['headers']['Authorization'])->toBe('Custom auth-scheme');
    });

    test('all headers work together with custom, token, and defaults', function () {
        $transporter = new HttpTransporter([
            'base_url' => 'https://api.example.com',
            'token' => 'secret-token',
            'headers' => [
                'X-Custom-Header' => 'custom-value',
                'Accept' => 'application/vnd.api+json', // Override default
            ],
        ]);
        $method = new ReflectionMethod(HttpTransporter::class, 'getClientBaseConfig');
        $method->setAccessible(true);

        $config = $method->invoke($transporter);

        expect($config['base_uri'])->toBe('https://api.example.com/');
        expect($config['headers'])->toHaveKey('Authorization', 'Bearer secret-token');
        expect($config['headers'])->toHaveKey('X-Custom-Header', 'custom-value');
        expect($config['headers'])->toHaveKey('Accept', 'application/vnd.api+json');
        expect($config['headers'])->toHaveKey('Content-Type', 'application/json');
    });

    test('successful request returns result field', function () {
        [$transporter, $mockClient] = createTransporterWithMockedSession();

        $response = new Response(200, [], json_encode(['result' => ['foo' => 'bar']]));
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(function ($options) {
                return isset($options['headers']['mcp-session-id']) &&
                    $options['headers']['mcp-session-id'] === 'test-session-id' &&
                    isset($options['json']['method']) &&
                    $options['json']['method'] === 'someAction' &&
                    isset($options['timeout']) &&
                    $options['timeout'] === 30;
            }))
            ->andReturn($response);

        expect($transporter->request('someAction', ['a' => 1]))->toEqual(['foo' => 'bar']);
    });

    test('successful request returns full data when no result', function () {
        [$transporter, $mockClient] = createTransporterWithMockedSession();

        $response = new Response(200, [], json_encode(['foo' => 'bar']));
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(function ($options) {
                return isset($options['headers']['mcp-session-id']) &&
                    $options['headers']['mcp-session-id'] === 'test-session-id' &&
                    isset($options['json']['method']) &&
                    $options['json']['method'] === 'otherAction' &&
                    isset($options['timeout']) &&
                    $options['timeout'] === 30;
            }))
            ->andReturn($response);

        expect($transporter->request('otherAction', []))->toEqual(['foo' => 'bar']);
    });

    test('invalid JSON response throws TransporterRequestException', function () {
        [$transporter, $mockClient] = createTransporterWithMockedSession();

        $response = new Response(200, [], 'not-json');
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(function ($options) {
                return isset($options['headers']['mcp-session-id']) &&
                    $options['headers']['mcp-session-id'] === 'test-session-id' &&
                    isset($options['json']['method']) &&
                    $options['json']['method'] === 'bad' &&
                    isset($options['timeout']) &&
                    $options['timeout'] === 30;
            }))
            ->andReturn($response);

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessage('Invalid JSON response:');

        $transporter->request('bad', []);
    });

    test('JSON-RPC error throws TransporterRequestException with code', function () {
        [$transporter, $mockClient] = createTransporterWithMockedSession();

        $error = ['error' => ['message' => 'Something went wrong', 'code' => 400]];
        $response = new Response(200, [], json_encode($error));
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(function ($options) {
                return isset($options['headers']['mcp-session-id']) &&
                    $options['headers']['mcp-session-id'] === 'test-session-id' &&
                    isset($options['json']['method']) &&
                    $options['json']['method'] === 'errorAction' &&
                    isset($options['timeout']) &&
                    $options['timeout'] === 30;
            }))
            ->andReturn($response);

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessage('JSON-RPC error: Something went wrong');
        $this->expectExceptionCode(400);

        $transporter->request('errorAction', []);
    });

    test('Guzzle exception is wrapped in TransporterRequestException', function () {
        [$transporter, $mockClient] = createTransporterWithMockedSession();

        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(function ($options) {
                return isset($options['headers']['mcp-session-id']) &&
                    $options['headers']['mcp-session-id'] === 'test-session-id' &&
                    isset($options['json']['method']) &&
                    $options['json']['method'] === 'networkFailure' &&
                    isset($options['timeout']) &&
                    $options['timeout'] === 30;
            }))
            ->andThrow(new TransferException('Network failure', 502));

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessage('HTTP error for networkFailure: Network failure');
        $this->expectExceptionCode(502);

        $transporter->request('networkFailure', []);
    });
});
