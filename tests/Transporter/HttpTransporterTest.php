<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Mockery\MockInterface;
use Psr\Http\Message\StreamInterface;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
use Redberry\MCPClient\Core\Mcp;
use Redberry\MCPClient\Core\Transporters\HttpTransporter;

afterEach(function () {
    Mockery::close();
});

describe('HttpTransporter', function () {
    /**
     * Builds an HttpTransporter wired to a Mockery-mocked Guzzle client via
     * constructor injection, and primes the initialize + notifications/initialized
     * handshake so the first user request triggers a session id of 'test-session-id'.
     *
     * The handshake expectations are method-specific, so subsequent test
     * expectations on the same mock won't be intercepted.
     *
     * @return array{0: HttpTransporter, 1: MockInterface}
     */
    function setUpInitializedTransporter(array $config = []): array
    {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter($config, $mockClient);

        $initResp = new Response(
            200,
            ['mcp-session-id' => 'test-session-id', 'Content-Type' => 'application/json'],
            json_encode(['result' => []])
        );
        $notifyResp = new Response(202, [], '');

        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initResp);
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andReturn($notifyResp);

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
        expect((is_string($payload['id']) || is_int($payload['id'])) && preg_match('/^\d+$/', (string) $payload['id']))->toBeTrue();
    });

    test('generateId starts at 1 and increments per call', function () {
        $transporter = new HttpTransporter;
        $gen = new ReflectionMethod(HttpTransporter::class, 'generateId');
        $gen->setAccessible(true);

        expect($gen->invoke($transporter))->toBe(1)
            ->and($gen->invoke($transporter))->toBe(2)
            ->and($gen->invoke($transporter))->toBe(3);
    });

    test('generateId returns int when id_type is integer', function () {
        $transporter = new HttpTransporter(['id_type' => 'integer']);
        $gen = new ReflectionMethod(HttpTransporter::class, 'generateId');
        $gen->setAccessible(true);

        expect($gen->invoke($transporter))->toBe(1);
    });

    test('generateId returns int by default', function () {
        $transporter = new HttpTransporter;
        $gen = new ReflectionMethod(HttpTransporter::class, 'generateId');
        $gen->setAccessible(true);

        expect($gen->invoke($transporter))->toBeInt();
    });

    test('generateId returns string when id_type is string', function () {
        $transporter = new HttpTransporter(['id_type' => 'string']);
        $gen = new ReflectionMethod(HttpTransporter::class, 'generateId');
        $gen->setAccessible(true);

        expect($gen->invoke($transporter))->toBe('1')
            ->and($gen->invoke($transporter))->toBe('2');
    });

    test('getClientBaseConfig has default values', function () {
        $transporter = new HttpTransporter;
        $method = new ReflectionMethod(HttpTransporter::class, 'getClientBaseConfig');
        $method->setAccessible(true);

        $config = $method->invoke($transporter);

        expect($config['base_uri'])->toBe('http://localhost/api');
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

        expect($config['base_uri'])->toBe('http://example.com/api');
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

        expect($config['base_uri'])->toBe('https://api.example.com');
        expect($config['headers'])->toHaveKey('Authorization', 'Bearer secret-token');
        expect($config['headers'])->toHaveKey('X-Custom-Header', 'custom-value');
        expect($config['headers'])->toHaveKey('Accept', 'application/vnd.api+json');
        expect($config['headers'])->toHaveKey('Content-Type', 'application/json');
    });

    test('successful request returns result field', function () {
        [$transporter, $mockClient] = setUpInitializedTransporter();

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
        [$transporter, $mockClient] = setUpInitializedTransporter();

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
        [$transporter, $mockClient] = setUpInitializedTransporter();

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
        [$transporter, $mockClient] = setUpInitializedTransporter();

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

    test('JSON-RPC error without message field falls back to "Unknown JSON-RPC error" without warnings', function () {
        [$transporter, $mockClient] = setUpInitializedTransporter();

        // Per JSON-RPC 2.0, `message` is SHOULD-not-MUST. Spec-compliant servers may omit it;
        // the previous code interpolated it directly and emitted a PHP warning + empty message.
        $error = ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000]];
        $response = new Response(200, [], json_encode($error));
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'errMissingMessage'))
            ->andReturn($response);

        // Promote PHP warnings to throwables so any regression of the null-coalesce fails this test.
        set_error_handler(function ($severity, $message) {
            throw new ErrorException($message, 0, $severity);
        }, E_WARNING);

        try {
            $transporter->request('errMissingMessage', []);
            expect()->fail('Expected TransporterRequestException for JSON-RPC error.');
        } catch (TransporterRequestException $e) {
            expect($e->getMessage())->toContain('Unknown JSON-RPC error')
                ->and($e->getCode())->toBe(-32000);
        } finally {
            restore_error_handler();
        }
    });

    test('Guzzle exception is wrapped in TransporterRequestException', function () {
        [$transporter, $mockClient] = setUpInitializedTransporter();

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

    test('client can be injected via constructor (no reflection needed)', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter([], $mockClient);

        $initResp = new Response(200, ['mcp-session-id' => 'sid-99', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp = new Response(202, [], '');
        $reqResp = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => ['ok' => 1]]));

        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initResp);
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andReturn($notifyResp);
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['headers']['mcp-session-id'] ?? null) === 'sid-99'
                && ($opts['json']['method'] ?? null) === 'ping'))
            ->andReturn($reqResp);

        expect($transporter->request('ping'))->toEqual(['ok' => 1]);
    });

    test('every request advertises both content types in Accept', function () {
        [$transporter, $mockClient] = setUpInitializedTransporter();

        $response = new Response(200, [], json_encode(['result' => ['ok' => true]]));
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['headers']['Accept'] ?? '') === 'application/json, text/event-stream'
                && ($opts['stream'] ?? null) === true))
            ->andReturn($response);

        $transporter->request('act');
    });

    test('SSE response is parsed and final result returned', function () {
        [$transporter, $mockClient] = setUpInitializedTransporter();

        $sse = <<<'SSE'
event: jsonrpc.message
data: {"jsonrpc":"2.0","id":1,"result":{"value":"final"}}

data: [DONE]

SSE;

        $response = new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($sse));
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::type('array'))
            ->andReturn($response);

        expect($transporter->request('stream'))->toEqual(['value' => 'final']);
    });

    test('SSE Content-Type with charset suffix is recognized', function () {
        [$transporter, $mockClient] = setUpInitializedTransporter();

        $sse = "data: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"ok\":true}}\n\n";
        $response = new Response(200, ['Content-Type' => 'text/event-stream; charset=utf-8'], Utils::streamFor($sse));
        $mockClient->shouldReceive('request')->once()->andReturn($response);

        expect($transporter->request('stream'))->toEqual(['ok' => true]);
    });

    test('SSE response that wedges is aborted by the configured read_timeout', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter(['read_timeout' => 0.05], $mockClient);

        $initResp = new Response(200, ['mcp-session-id' => 'sid', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp = new Response(202, [], '');

        $wedgedBody = Mockery::mock(StreamInterface::class);
        $wedgedBody->shouldReceive('eof')->andReturn(false);
        $wedgedBody->shouldReceive('read')->andReturn('');

        $reqResp = new Response(200, ['Content-Type' => 'text/event-stream'], $wedgedBody);

        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initResp);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andReturn($notifyResp);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'wedge'))
            ->andReturn($reqResp);

        $start = microtime(true);

        try {
            $transporter->request('wedge');
            expect()->fail('Expected the configured read_timeout to abort the wedged stream.');
        } catch (TransporterRequestException $e) {
            expect($e->getMessage())->toContain('SSE read timed out')
                ->and(microtime(true) - $start)->toBeLessThan(1.0);
        }
    });

    test('onEvent callback receives every SSE event including the final result', function () {
        [$transporter, $mockClient] = setUpInitializedTransporter();

        $sse = <<<'SSE'
data: {"jsonrpc":"2.0","method":"progress","params":{"pct":50}}

data: {"jsonrpc":"2.0","id":1,"result":{"done":true}}

SSE;

        $response = new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($sse));
        $mockClient->shouldReceive('request')->once()->andReturn($response);

        $events = [];
        $result = $transporter->request('stream', [], function (array $evt) use (&$events) {
            $events[] = $evt;
        });

        expect($result)->toEqual(['done' => true])
            ->and($events)->toHaveCount(2)
            ->and($events[0]['method'])->toBe('progress')
            ->and($events[1]['result']['done'])->toBeTrue();
    });

    test('connection failure during initialize is wrapped as TransporterRequestException', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter([], $mockClient);

        $mockClient->shouldReceive('request')
            ->once()
            ->andThrow(new TransferException('init refused', 503));

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessage('HTTP error during initialize handshake: init refused');
        $this->expectExceptionCode(503);

        $transporter->request('anything');
    });

    test('JSON response with scalar result returns the full envelope', function () {
        [$transporter, $mockClient] = setUpInitializedTransporter();

        $response = new Response(200, ['Content-Type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => 'plain-string']));
        $mockClient->shouldReceive('request')->once()->andReturn($response);

        $result = $transporter->request('act');

        expect($result)->toBeArray()
            ->and($result['result'])->toBe('plain-string');
    });

    test('initialize handshake captures mcp-session-id and reuses it', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter([], $mockClient);

        $initResp = new Response(200, ['mcp-session-id' => 'session-xyz', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp = new Response(202, [], '');
        $reqResp = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => ['after' => true]]));

        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ! isset($opts['headers']['mcp-session-id'])
                && ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initResp);

        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'
                && ($opts['headers']['mcp-session-id'] ?? null) === 'session-xyz'))
            ->andReturn($notifyResp);

        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['headers']['mcp-session-id'] ?? null) === 'session-xyz'
                && ($opts['json']['method'] ?? null) === 'after_init'))
            ->andReturn($reqResp);

        expect($transporter->request('after_init'))->toEqual(['after' => true]);
    });

    test('initialize payload contains protocolVersion, capabilities and clientInfo', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter([], $mockClient);

        $captured = null;
        $initResp = new Response(200, ['mcp-session-id' => 'sid', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp = new Response(202, [], '');
        $reqResp = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => ['ok' => true]]));

        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(function ($opts) use (&$captured) {
                if (($opts['json']['method'] ?? null) !== 'initialize') {
                    return false;
                }
                $captured = $opts['json'];

                return true;
            }))
            ->andReturn($initResp);
        $mockClient->shouldReceive('request')->once()->andReturn($notifyResp);
        $mockClient->shouldReceive('request')->once()->andReturn($reqResp);

        $transporter->request('ping');

        expect($captured['params']['protocolVersion'])->toBe(Mcp::PROTOCOL_VERSION)
            ->and($captured['params']['capabilities'])->toBeInstanceOf(stdClass::class)
            ->and($captured['params']['clientInfo']['name'])->toBe('mcp-client-laravel')
            ->and($captured['params']['clientInfo']['version'])->toBeString()
            ->and($captured['params']['clientInfo']['version'])->not->toBe('');
    });

    test('notifications/initialized is sent after initialize, with the captured session id and no id field', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter([], $mockClient);

        $captured = null;
        $initResp = new Response(200, ['mcp-session-id' => 'sid-42', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp = new Response(202, [], '');
        $reqResp = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => []]));

        $mockClient->shouldReceive('request')->once()->andReturn($initResp);
        $mockClient->shouldReceive('request')
            ->once()
            ->with('POST', '', Mockery::on(function ($opts) use (&$captured) {
                if (($opts['json']['method'] ?? null) !== 'notifications/initialized') {
                    return false;
                }
                $captured = $opts;

                return true;
            }))
            ->andReturn($notifyResp);
        $mockClient->shouldReceive('request')->once()->andReturn($reqResp);

        $transporter->request('ping');

        expect($captured['json'])->not->toHaveKey('id')
            ->and($captured['headers']['mcp-session-id'])->toBe('sid-42')
            ->and($captured['headers']['Accept'])->toBe('application/json, text/event-stream');
    });

    test('initialize handshake uses the literal id "init", leaving the counter at 0', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter([], $mockClient);

        $initJson = null;
        $userJson = null;
        $initResp = new Response(200, ['mcp-session-id' => 'sid', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp = new Response(202, [], '');
        $userResp = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => []]));

        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(function ($opts) use (&$initJson) {
                if (($opts['json']['method'] ?? null) !== 'initialize') {
                    return false;
                }
                $initJson = $opts['json'];

                return true;
            }))
            ->andReturn($initResp);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andReturn($notifyResp);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(function ($opts) use (&$userJson) {
                if (($opts['json']['method'] ?? null) !== 'first') {
                    return false;
                }
                $userJson = $opts['json'];

                return true;
            }))
            ->andReturn($userResp);

        $transporter->request('first');

        expect($initJson['id'])->toBe('init')
            ->and($userJson['id'])->toBe(1);
    });

    test('two sequential user requests produce ids 1 and 2', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter([], $mockClient);

        $ids = [];
        $initResp = new Response(200, ['mcp-session-id' => 'sid', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp = new Response(202, [], '');
        $userResp = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => []]));

        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initResp);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andReturn($notifyResp);
        $mockClient->shouldReceive('request')->twice()
            ->with('POST', '', Mockery::on(function ($opts) use (&$ids) {
                if (($opts['json']['method'] ?? null) !== 'work') {
                    return false;
                }
                $ids[] = $opts['json']['id'];

                return true;
            }))
            ->andReturn($userResp);

        $transporter->request('work');
        $transporter->request('work');

        expect($ids)->toBe([1, 2]);
    });

    test('handshake POSTs occur in the order initialize → notifications/initialized → user request', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter([], $mockClient);

        $methods = [];
        $initResp = new Response(200, ['mcp-session-id' => 'sid', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp = new Response(202, [], '');
        $reqResp = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => []]));

        $mockClient->shouldReceive('request')->times(3)
            ->with('POST', '', Mockery::on(function ($opts) use (&$methods) {
                $methods[] = $opts['json']['method'] ?? null;

                return true;
            }))
            ->andReturn($initResp, $notifyResp, $reqResp);

        $transporter->request('tools/list');

        expect($methods)->toEqual(['initialize', 'notifications/initialized', 'tools/list']);
    });

    test('failure on notifications/initialized leaves transporter uninitialized so the next call retries', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter([], $mockClient);

        $initResp = new Response(200, ['mcp-session-id' => 'sid', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp = new Response(202, [], '');
        $reqResp = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => ['ok' => true]]));

        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initResp);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andThrow(new TransferException('notify refused', 503));

        expect(fn () => $transporter->request('first'))
            ->toThrow(TransporterRequestException::class, 'HTTP error sending notifications/initialized');

        // Next call should retry the full handshake.
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initResp);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andReturn($notifyResp);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'second'))
            ->andReturn($reqResp);

        expect($transporter->request('second'))->toEqual(['ok' => true]);
    });

    test('HTTP 404 with active session re-initializes and retries the request once', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter([], $mockClient);

        $initResp1 = new Response(200, ['mcp-session-id' => 'sid-1', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp1 = new Response(202, [], '');
        $firstReqResp = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => ['n' => 1]]));

        $sessionLost = new ClientException(
            'Session not found',
            new Request('POST', ''),
            new Response(404, [], '')
        );

        $initResp2 = new Response(200, ['mcp-session-id' => 'sid-2', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp2 = new Response(202, [], '');
        $retryResp = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => ['n' => 2]]));

        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initResp1);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andReturn($notifyResp1);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'first'
                && ($opts['headers']['mcp-session-id'] ?? null) === 'sid-1'))
            ->andReturn($firstReqResp);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'second'
                && ($opts['headers']['mcp-session-id'] ?? null) === 'sid-1'))
            ->andThrow($sessionLost);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initResp2);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andReturn($notifyResp2);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'second'
                && ($opts['headers']['mcp-session-id'] ?? null) === 'sid-2'))
            ->andReturn($retryResp);

        expect($transporter->request('first'))->toEqual(['n' => 1]);
        expect($transporter->request('second'))->toEqual(['n' => 2]);
    });

    test('HTTP 404 followed by another 404 on retry surfaces as TransporterRequestException', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter([], $mockClient);

        $initResp1 = new Response(200, ['mcp-session-id' => 'sid-1', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp1 = new Response(202, [], '');
        $sessionLost1 = new ClientException(
            'Session not found',
            new Request('POST', ''),
            new Response(404, [], '')
        );
        $initResp2 = new Response(200, ['mcp-session-id' => 'sid-2', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp2 = new Response(202, [], '');
        $sessionLost2 = new ClientException(
            'Session not found again',
            new Request('POST', ''),
            new Response(404, [], '')
        );

        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initResp1);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andReturn($notifyResp1);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'doomed'))
            ->andThrow($sessionLost1);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initResp2);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andReturn($notifyResp2);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'doomed'))
            ->andThrow($sessionLost2);

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessage('HTTP error for doomed: Session not found again');

        $transporter->request('doomed');
    });

    test('max_session_retries=0 surfaces a 404 immediately without re-initializing', function () {
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter(['max_session_retries' => 0], $mockClient);

        $initResp = new Response(200, ['mcp-session-id' => 'sid-1', 'Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp = new Response(202, [], '');
        $sessionLost = new ClientException(
            'Session not found',
            new Request('POST', ''),
            new Response(404, [], '')
        );

        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initResp);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andReturn($notifyResp);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'noRetry'))
            ->andThrow($sessionLost);
        // No further initialize/request calls are expected — the assertion is implicit
        // in Mockery's strict expectations, which will fail if the transporter retries.

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessage('HTTP error for noRetry: Session not found');

        $transporter->request('noRetry');
    });

    test('404 from a sessionless server is not treated as session loss', function () {
        // Some servers may complete `initialize` without returning an `mcp-session-id` header
        // (sessionless mode). In that case a follow-up 404 is a real error, not session expiry,
        // and must not trigger a retry.
        $mockClient = Mockery::mock(Client::class);
        $transporter = new HttpTransporter([], $mockClient);

        $initRespNoSession = new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => []]));
        $notifyResp = new Response(202, [], '');
        $notFound = new ClientException(
            'Not found',
            new Request('POST', ''),
            new Response(404, [], '')
        );

        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'initialize'))
            ->andReturn($initRespNoSession);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'notifications/initialized'))
            ->andReturn($notifyResp);
        $mockClient->shouldReceive('request')->once()
            ->with('POST', '', Mockery::on(fn ($opts) => ($opts['json']['method'] ?? null) === 'whatever'))
            ->andThrow($notFound);
        // No retry — the strict Mockery expectations will fail if a re-initialize is attempted.

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessage('HTTP error for whatever: Not found');

        $transporter->request('whatever');
    });
});
