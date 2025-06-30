<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
use Redberry\MCPClient\Core\Transporters\HttpTransporter;

afterEach(function () {
    Mockery::close();
});

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
    $transporter = new HttpTransporter;
    $response = new Response(200, [], json_encode(['result' => ['foo' => 'bar']]));

    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('post')->once()->andReturn($response);

    $ref = new ReflectionClass($transporter);
    $prop = $ref->getProperty('client');
    $prop->setAccessible(true);
    $prop->setValue($transporter, $mockClient);

    expect($transporter->request('someAction', ['a' => 1]))->toEqual(['foo' => 'bar']);
});

test('successful request returns full data when no result', function () {
    $transporter = new HttpTransporter;
    $response = new Response(200, [], json_encode(['foo' => 'bar']));

    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('post')->once()->andReturn($response);

    $ref = new ReflectionClass($transporter);
    $prop = $ref->getProperty('client');
    $prop->setAccessible(true);
    $prop->setValue($transporter, $mockClient);

    expect($transporter->request('otherAction', []))->toEqual(['foo' => 'bar']);
});

test('invalid JSON response throws TransporterRequestException', function () {
    $transporter = new HttpTransporter;
    $response = new Response(200, [], 'not-json');

    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('post')->once()->andReturn($response);

    $ref = new ReflectionClass($transporter);
    $prop = $ref->getProperty('client');
    $prop->setAccessible(true);
    $prop->setValue($transporter, $mockClient);

    $this->expectException(TransporterRequestException::class);
    $this->expectExceptionMessage('Invalid JSON response:');

    $transporter->request('bad', []);
});

test('JSON-RPC error throws TransporterRequestException with code', function () {
    $transporter = new HttpTransporter;
    $error = ['error' => ['message' => 'Something went wrong', 'code' => 400]];
    $response = new Response(200, [], json_encode($error));

    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('post')->once()->andReturn($response);

    $ref = new ReflectionClass($transporter);
    $prop = $ref->getProperty('client');
    $prop->setAccessible(true);
    $prop->setValue($transporter, $mockClient);

    $this->expectException(TransporterRequestException::class);
    $this->expectExceptionMessage('JSON-RPC error: Something went wrong');
    $this->expectExceptionCode(400);

    $transporter->request('errorAction', []);
});

test('Guzzle exception is wrapped in TransporterRequestException', function () {
    $transporter = new HttpTransporter;

    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('post')->once()->andThrow(new TransferException('Network failure', 502));

    $ref = new ReflectionClass($transporter);
    $prop = $ref->getProperty('client');
    $prop->setAccessible(true);
    $prop->setValue($transporter, $mockClient);

    $this->expectException(TransporterRequestException::class);
    $this->expectExceptionMessage('HTTP error for networkFailure: Network failure');

    $transporter->request('networkFailure', []);
});
