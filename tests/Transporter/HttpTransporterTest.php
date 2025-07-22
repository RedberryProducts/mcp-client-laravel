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

        // Mock the initializeSession request
        $responseForInitialize = $responseForInitialize ?? new Response(200, ['mcp-session-id' => 'test-session-id'], '{}');
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(function ($options) {
                return isset($options['json']['method']) && $options['json']['method'] === 'initialize';
            }))
            ->andReturn($responseForInitialize);

        // Inject the mocked client
        $ref = new ReflectionClass($transporter);
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($transporter, $mockClient);

        // Set the sessionId to avoid uninitialized property errors
        $sessionProp = $ref->getProperty('sessionId');
        $sessionProp->setAccessible(true);
        $sessionProp->setValue($transporter, 'test-session-id');

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

    test('successful request returns result field', function () {
        [$transporter, $mockClient] = createTransporterWithMockedSession();

        $response = new Response(200, [], json_encode(['result' => ['foo' => 'bar']]));
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', 'someAction', Mockery::on(function ($options) {
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
            ->with('POST', 'otherAction', Mockery::on(function ($options) {
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
            ->with('POST', 'bad', Mockery::on(function ($options) {
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
            ->with('POST', 'errorAction', Mockery::on(function ($options) {
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
            ->with('POST', 'networkFailure', Mockery::on(function ($options) {
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

    test('initializeSession stores header session id', function () {
        $transporter = new HttpTransporter;
        $mock = Mockery::mock(Client::class);

        $mock->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn($o) => isset($o['json']['method']) && $o['json']['method'] === 'initialize'))
            ->andReturn(new Response(200, ['mcp-session-id' => 'abc'], '{}'));
        $mock->shouldReceive('request')->once()
            ->with('POST', 'do', Mockery::on(function ($o) {
                return isset($o['headers']['mcp-session-id']) && $o['headers']['mcp-session-id'] === 'abc';
            }))
            ->andReturn(new Response(200, [], json_encode(['result' => ['ok' => true]])));

        $ref = new ReflectionClass($transporter);
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($transporter, $mock);

        expect($transporter->request('do'))->toEqual(['ok' => true]);

        $sidProp = $ref->getProperty('sessionId');
        $sidProp->setAccessible(true);
        expect($sidProp->getValue($transporter))->toBe('abc');
    });

    test('missing session header causes property error', function () {
        $transporter = new HttpTransporter;
        $mock = Mockery::mock(Client::class);

        $mock->shouldReceive('request')->once()
            ->with('POST', '', Mockery::type('array'))
            ->andReturn(new Response(200, [], '{}'));

        $ref = new ReflectionClass($transporter);
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($transporter, $mock);

        expect(fn () => $transporter->request('fail'))
            ->toThrow(Error::class);
    });
});

