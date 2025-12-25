<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
use Redberry\MCPClient\Core\Transporters\StreamableHttpTransporter;

afterEach(function () {
    Mockery::close();
});

describe('StreamableHttpTransporter', function () {
    function createStreamableWithMockedSession(): array
    {
        $transporter = new StreamableHttpTransporter;
        $mockClient = Mockery::mock(Client::class);

        $ref = new ReflectionClass($transporter);
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($transporter, $mockClient);

        $sessionProp = $ref->getProperty('sessionId');
        $sessionProp->setAccessible(true);
        $sessionProp->setValue($transporter, 'test-session-id');

        $initializedProp = $ref->getProperty('initialized');
        $initializedProp->setAccessible(true);
        $initializedProp->setValue($transporter, true);

        return [$transporter, $mockClient];
    }

    test('preparePayload builds correct payload', function () {
        $t = new StreamableHttpTransporter;
        $m = new ReflectionMethod(StreamableHttpTransporter::class, 'preparePayload');
        $m->setAccessible(true);

        $payload = $m->invoke($t, 'testMethod', ['x' => 1]);

        expect($payload['jsonrpc'])->toBe('2.0')
            ->and($payload['method'])->toBe('testMethod')
            ->and($payload['params'])->toEqual(['x' => 1])
            ->and(is_int($payload['id']) || is_string($payload['id']))->toBeTrue();
    });

    test('generateId returns int by default and string when configured', function () {
        $t1 = new StreamableHttpTransporter;
        $g = new ReflectionMethod(StreamableHttpTransporter::class, 'generateId');
        $g->setAccessible(true);
        $id1 = $g->invoke($t1);
        expect(is_int($id1))->toBeTrue();

        $t2 = new StreamableHttpTransporter(['id_type' => 'string']);
        $g2 = new ReflectionMethod(StreamableHttpTransporter::class, 'generateId');
        $g2->setAccessible(true);
        $id2 = $g2->invoke($t2);
        expect(is_string($id2))->toBeTrue();
    });

    test('getClientBaseConfig defaults and overrides', function () {
        $t = new StreamableHttpTransporter([
            'base_url' => 'https://example.com/api',
            'token' => 'secret',
            'headers' => [
                'X-Custom' => 'v',
                'Accept' => 'application/vnd.api+json',
            ],
        ]);
        $m = new ReflectionMethod(StreamableHttpTransporter::class, 'getClientBaseConfig');
        $m->setAccessible(true);
        $cfg = $m->invoke($t);

        expect($cfg['base_uri'])->toBe('https://example.com/api')
            ->and($cfg['headers']['Authorization'])->toBe('Bearer secret')
            ->and($cfg['headers']['X-Custom'])->toBe('v')
            ->and($cfg['headers']['Accept'])->toBe('application/vnd.api+json')
            ->and($cfg['headers']['Content-Type'])->toBe('application/json');
    });

    test('successful JSON response returns result', function () {
        [$t, $mock] = createStreamableWithMockedSession();

        $resp = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => ['foo' => 'bar']]));

        $mock->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(static function ($options) {
                return ($options['headers']['mcp-session-id'] ?? null) === 'test-session-id'
                    && ($options['headers']['Accept'] ?? '') === 'application/json, text/event-stream'
                    && ($options['stream'] ?? null) === true
                    && ($options['timeout'] ?? null) === 30
                    && ($options['json']['method'] ?? null) === 'act';
            }))
            ->andReturn($resp);

        $result = $t->request('act', ['a' => 1]);
        expect($result)->toEqual(['foo' => 'bar']);
    });

    test('initializeSession captures mcp-session-id from first response', function () {
        $t = new StreamableHttpTransporter;
        $mock = Mockery::mock(Client::class);

        $ref = new ReflectionClass($t);
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $prop->setValue($t, $mock);

        // First call: initialize
        $initResp = new Response(200, ['mcp-session-id' => 'abc-123', 'Content-Type' => 'application/json'], json_encode(['ok' => true]));
        // Second call: actual request
        $reqResp = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => ['ok' => 1]]));

        $mock->shouldReceive('request')->once()->with('POST', '', Mockery::type('array'))->andReturn($initResp);
        $mock->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(static function ($options) {
                return ($options['headers']['mcp-session-id'] ?? '') === 'abc-123';
            }))
            ->andReturn($reqResp);

        $result = $t->request('ping');
        expect($result)->toEqual(['ok' => 1]);
    });

    test('parses SSE stream and returns last non-null result', function () {
        [$t, $mock] = createStreamableWithMockedSession();

        $sse = <<<'SSE'
event: jsonrpc.message
data: {"jsonrpc":"2.0","id":1,"result":{"delta":"Hello"}}

event: jsonrpc.message
data: {"jsonrpc":"2.0","id":1,"result":{"final":"World"}}

data: [DONE]
SSE;
        $body = Utils::streamFor($sse);
        $resp = new Response(200, ['Content-Type' => 'text/event-stream'], $body);

        $mock->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::type('array'))
            ->andReturn($resp);

        $result = $t->request('stream', []);
        expect($result)->toEqual(['final' => 'World']);
    });

    test('wraps Guzzle exceptions as TransporterRequestException', function () {
        [$t, $mock] = createStreamableWithMockedSession();

        $mock->shouldReceive('request')
            ->once()
            ->andThrow(new TransferException('boom'));

        $this->expectException(TransporterRequestException::class);
        $t->request('fail');
    });
});
