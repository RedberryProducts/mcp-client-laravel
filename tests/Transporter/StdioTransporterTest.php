<?php

use Redberry\MCPClient\Core\Exceptions\ServerConfigurationException;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
use Redberry\MCPClient\Core\Mcp;
use Redberry\MCPClient\Core\Transporters\StdioTransporter;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Mockery::getConfiguration()->allowMockingNonExistentMethods(true);
});

afterEach(function () {
    Mockery::close();
});

describe('StdioTransporter', function () {

    it('throws exception if command config is empty', function () {
        $this->expectException(ServerConfigurationException::class);
        new StdioTransporter([]);
    });

    it('accepts valid configuration without errors', function () {
        $instance = new StdioTransporter(['command' => ['echo', 'hello']]);
        expect($instance)->toBeInstanceOf(StdioTransporter::class);
    });

    it('builds correct command line', function () {
        $instance = new StdioTransporter(['command' => ['echo', 'hello']]);
        $method = new ReflectionMethod($instance, 'buildCommandLine');
        $method->setAccessible(true);

        $line = $method->invoke($instance);
        expect($line)->toContain('echo');
        expect($line)->toContain('hello');
    });

    it('throws exception on startup failure', function () {
        $command = PHP_OS_FAMILY === 'Windows'
            ? ['cmd', '/c', 'exit', '1']
            : ['false'];

        $transporter = new StdioTransporter([
            'command' => $command,
        ]);

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessageMatches('/Process failed to start/');

        $transporter->request('something');
    });

    it('sends request with empty params as object', function () {
        $mock = Mockery::mock(StdioTransporter::class)->makePartial();
        $mock->shouldAllowMockingProtectedMethods();

        $mock->shouldReceive('start')->once();
        $mock->shouldReceive('waitForResponse')->andReturn(['mocked' => true]);

        $inputStream = Mockery::mock(InputStream::class);
        $inputStream->shouldReceive('write')->once()->with('{"jsonrpc":"2.0","id":"1","method":"foo","params":{}}'."\n");

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('clearOutput')->once();
        $process->shouldReceive('clearErrorOutput')->once();

        $ref = new ReflectionClass(StdioTransporter::class);

        $streamProp = $ref->getProperty('inputStream');
        $streamProp->setAccessible(true);
        $streamProp->setValue($mock, $inputStream);

        $processProp = $ref->getProperty('process');
        $processProp->setAccessible(true);
        $processProp->setValue($mock, $process);

        $mock->request('foo', []);
    });

    it('throws TransporterRequestException on timeout', function () {
        $config = ['command' => ['echo', 'hi'], 'timeout' => 1, 'env' => []];
        $mock = Mockery::mock(StdioTransporter::class, [$config])->makePartial();
        $mock->shouldAllowMockingProtectedMethods();

        $mock->shouldReceive('start')->once();

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('clearOutput')->once();
        $process->shouldReceive('clearErrorOutput')->once();
        $process->shouldReceive('getIncrementalOutput')->andReturn('');
        $process->shouldReceive('isRunning');

        $ref = new ReflectionClass(StdioTransporter::class);

        $procProp = $ref->getProperty('process');
        $procProp->setAccessible(true);
        $procProp->setValue($mock, $process);

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessageMatches('/Timeout after 1 seconds/');

        $inputStream = Mockery::mock(InputStream::class);
        $inputStream->shouldReceive('write')->once();
        $inputStream->shouldReceive('close');

        $inputStreamProp = $ref->getProperty('inputStream');
        $inputStreamProp->setAccessible(true);
        $inputStreamProp->setValue($mock, $inputStream);

        $mock->request('timeout-test');
    });

    it('start exits early if process is already running', function () {
        $transporter = Mockery::mock(StdioTransporter::class, [['command' => ['echo', 'ok']]])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isRunning')->once()->andReturnTrue();
        $process->shouldReceive('stop');

        $transporter->shouldNotReceive('initializeProcess');
        $transporter->shouldNotReceive('sendInitializeRequests');
        $process->shouldNotReceive('start');

        $ref = new ReflectionClass(StdioTransporter::class);
        $prop = $ref->getProperty('process');
        $prop->setAccessible(true);
        $prop->setValue($transporter, $process);

        $method = $ref->getMethod('start');
        $method->setAccessible(true);
        $method->invoke($transporter);

        expect(true)->toBeTrue();
    });

    it('start throws TransporterRequestException when process start fails', function () {
        $transporter = Mockery::mock(StdioTransporter::class, [['command' => ['echo', 'hi']]])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $transporter->shouldReceive('initializeProcess')->once();
        $transporter->shouldReceive('cleanup')->once();
        $transporter->shouldNotReceive('sendInitializeRequests'); // should not reach this

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('start')->once()->andThrow(new RuntimeException('start failure'));
        $process->shouldReceive('isRunning')->andReturn(false)->byDefault();

        $ref = new ReflectionClass(StdioTransporter::class);
        $procProp = $ref->getProperty('process');
        $procProp->setAccessible(true);
        $procProp->setValue($transporter, $process);

        $startMethod = $ref->getMethod('start');
        $startMethod->setAccessible(true);

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessage('Unable to start process: start failure');

        $startMethod->invoke($transporter);
    });

    it('start completes successfully and calls sendInitializeRequests', function () {
        $transporter = Mockery::mock(StdioTransporter::class, [['command' => ['echo', 'hi']]])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $transporter->shouldReceive('initializeProcess')->once();
        $transporter->shouldReceive('sendInitializeRequests')->once();

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('start')->once();
        $process->shouldReceive('isRunning')->twice()->andReturn(false, true); // once before, once after start
        $process->shouldReceive('stop')->byDefault(); // for destructor/cleanup safety

        $ref = new ReflectionClass(StdioTransporter::class);
        $procProp = $ref->getProperty('process');
        $procProp->setAccessible(true);
        $procProp->setValue($transporter, $process);

        $startMethod = $ref->getMethod('start');
        $startMethod->setAccessible(true);
        $startMethod->invoke($transporter);

        expect(true)->toBeTrue(); // No exceptions = success
    });

    it('close closes input stream and stops running process', function () {
        $transporter = Mockery::mock(StdioTransporter::class, [['command' => ['echo', 'hi']]])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $inputStream = Mockery::mock(InputStream::class);
        $inputStream->shouldReceive('close')->once();

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isRunning')->once()->andReturn(true);
        $process->shouldReceive('stop')->once();

        $ref = new ReflectionClass(StdioTransporter::class);

        $inputProp = $ref->getProperty('inputStream');
        $inputProp->setAccessible(true);
        $inputProp->setValue($transporter, $inputStream);

        $procProp = $ref->getProperty('process');
        $procProp->setAccessible(true);
        $procProp->setValue($transporter, $process);

        $closeMethod = $ref->getMethod('close');
        $closeMethod->setAccessible(true);
        $closeMethod->invoke($transporter);

        expect(true)->toBeTrue();
    });

    it('sendInitializeRequests writes two correct payloads', function () {
        $transporter = Mockery::mock(StdioTransporter::class, [['command' => ['echo', 'hi']]])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $inputStream = Mockery::mock(InputStream::class);

        $inputStream->shouldReceive('write')
            ->twice()
            ->withArgs(function ($arg) {
                $decoded = json_decode(trim($arg), true);

                return isset($decoded['jsonrpc']) && isset($decoded['method']);
            });

        $inputStream->shouldReceive('close')->byDefault();

        $ref = new ReflectionClass(StdioTransporter::class);
        $streamProp = $ref->getProperty('inputStream');
        $streamProp->setAccessible(true);
        $streamProp->setValue($transporter, $inputStream);

        $method = $ref->getMethod('sendInitializeRequests');
        $method->setAccessible(true);
        $method->invoke($transporter);

        expect(true)->toBeTrue();
    });

    it('waitForResponse returns result when valid matching response is found', function () {
        $config = ['command' => ['echo', 'hi'], 'timeout' => 1, 'env' => []];
        $transporter = Mockery::mock(StdioTransporter::class, [$config])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $id = '123';
        $responseLine = json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['foo' => 'bar'],
        ]);

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getIncrementalOutput')->andReturn($responseLine."\n");

        $process->shouldReceive('isRunning')->byDefault()->andReturn(false);
        $process->shouldReceive('stop')->byDefault();

        $ref = new ReflectionClass(StdioTransporter::class);

        $procProp = $ref->getProperty('process');
        $procProp->setAccessible(true);
        $procProp->setValue($transporter, $process);

        $method = $ref->getMethod('waitForResponse');
        $method->setAccessible(true);

        $result = $method->invoke($transporter, $id);

        expect($result)->toBe(['foo' => 'bar']);
    });

    it('waitForResponse throws exception when response contains error', function () {
        $config = ['command' => ['echo', 'hi'], 'timeout' => 1, 'env' => []];
        $transporter = Mockery::mock(StdioTransporter::class, [$config])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $id = '456';
        $responseLine = json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['message' => 'Something went wrong'],
        ]);

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getIncrementalOutput')->andReturn($responseLine."\n");

        $process->shouldReceive('isRunning')->byDefault()->andReturn(false);
        $process->shouldReceive('stop')->byDefault();

        $ref = new ReflectionClass(StdioTransporter::class);

        $procProp = $ref->getProperty('process');
        $procProp->setAccessible(true);
        $procProp->setValue($transporter, $process);

        $method = $ref->getMethod('waitForResponse');
        $method->setAccessible(true);

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessage('JSON-RPC error: Something went wrong');

        $method->invoke($transporter, $id);
    });

    it('waitForResponse skips invalid JSON and returns valid result', function () {
        $transporter = Mockery::mock(StdioTransporter::class, [['command' => ['echo', 'hi']]])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $id = '789';
        $invalidLine = '{"jsonrpc": "2.0", "id": ';
        $validLine = json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['x' => 1],
        ]);

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getIncrementalOutput')->andReturn($invalidLine."\n".$validLine."\n");

        $process->shouldReceive('isRunning')->byDefault()->andReturn(false);
        $process->shouldReceive('stop')->byDefault();

        $ref = new ReflectionClass(StdioTransporter::class);

        $procProp = $ref->getProperty('process');
        $procProp->setAccessible(true);
        $procProp->setValue($transporter, $process);

        $method = $ref->getMethod('waitForResponse');
        $method->setAccessible(true);

        $result = $method->invoke($transporter, $id);

        expect($result)->toBe(['x' => 1]);
    });

    it('sendInitializeRequests uses the shared protocol version and notifications/initialized method', function () {
        $transporter = Mockery::mock(StdioTransporter::class, [['command' => ['echo', 'hi']]])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $captured = [];

        $inputStream = Mockery::mock(InputStream::class);
        $inputStream->shouldReceive('write')
            ->twice()
            ->withArgs(function ($arg) use (&$captured) {
                $captured[] = json_decode(trim($arg), true);

                return true;
            });
        $inputStream->shouldReceive('close')->byDefault();

        $ref = new ReflectionClass(StdioTransporter::class);
        $streamProp = $ref->getProperty('inputStream');
        $streamProp->setAccessible(true);
        $streamProp->setValue($transporter, $inputStream);

        $method = $ref->getMethod('sendInitializeRequests');
        $method->setAccessible(true);
        $method->invoke($transporter);

        [$initialize, $notification] = $captured;

        expect($initialize['method'])->toBe('initialize')
            ->and($initialize['id'])->toBe('init')
            ->and($initialize['params']['protocolVersion'])->toBe(Mcp::PROTOCOL_VERSION)
            ->and($initialize['params']['clientInfo']['name'])->toBe('mcp-client-laravel')
            ->and($initialize['params']['clientInfo']['version'])->toBeString()
            ->and($initialize['params']['clientInfo']['version'])->not->toBe('')
            ->and($notification['method'])->toBe('notifications/initialized')
            ->and(array_key_exists('id', $notification))->toBeFalse();
    });

    it('uses request_timeout over legacy timeout when both are set', function () {
        $config = ['command' => ['echo', 'hi'], 'timeout' => 99, 'request_timeout' => 1, 'env' => []];
        $transporter = Mockery::mock(StdioTransporter::class, [$config])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getIncrementalOutput')->andReturn('');
        $process->shouldReceive('isRunning')->byDefault()->andReturn(false);
        $process->shouldReceive('stop')->byDefault();

        $ref = new ReflectionClass(StdioTransporter::class);
        $procProp = $ref->getProperty('process');
        $procProp->setAccessible(true);
        $procProp->setValue($transporter, $process);

        $method = $ref->getMethod('waitForResponse');
        $method->setAccessible(true);

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessageMatches('/Timeout after 1 seconds/');

        $method->invoke($transporter, 'never-arrives');
    });

    it('falls back to legacy timeout when request_timeout is not set', function () {
        $config = ['command' => ['echo', 'hi'], 'timeout' => 1, 'env' => []];
        $transporter = Mockery::mock(StdioTransporter::class, [$config])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getIncrementalOutput')->andReturn('');
        $process->shouldReceive('isRunning')->byDefault()->andReturn(false);
        $process->shouldReceive('stop')->byDefault();

        $ref = new ReflectionClass(StdioTransporter::class);
        $procProp = $ref->getProperty('process');
        $procProp->setAccessible(true);
        $procProp->setValue($transporter, $process);

        $method = $ref->getMethod('waitForResponse');
        $method->setAccessible(true);

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessageMatches('/Timeout after 1 seconds/');

        $method->invoke($transporter, 'never-arrives');
    });

    it('preserves fractional request_timeout values instead of truncating to int', function () {
        $config = ['command' => ['echo', 'hi'], 'request_timeout' => 0.5, 'env' => []];
        $transporter = Mockery::mock(StdioTransporter::class, [$config])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getIncrementalOutput')->andReturn('');
        $process->shouldReceive('isRunning')->byDefault()->andReturn(false);
        $process->shouldReceive('stop')->byDefault();

        $ref = new ReflectionClass(StdioTransporter::class);
        $procProp = $ref->getProperty('process');
        $procProp->setAccessible(true);
        $procProp->setValue($transporter, $process);

        $method = $ref->getMethod('waitForResponse');
        $method->setAccessible(true);

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessageMatches('/Timeout after 0\.5 seconds/');

        $method->invoke($transporter, 'never-arrives');
    });

    it('treats request_timeout=null as unset and falls through to legacy timeout', function () {
        $config = ['command' => ['echo', 'hi'], 'request_timeout' => null, 'timeout' => 1, 'env' => []];
        $transporter = Mockery::mock(StdioTransporter::class, [$config])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('getIncrementalOutput')->andReturn('');
        $process->shouldReceive('isRunning')->byDefault()->andReturn(false);
        $process->shouldReceive('stop')->byDefault();

        $ref = new ReflectionClass(StdioTransporter::class);
        $procProp = $ref->getProperty('process');
        $procProp->setAccessible(true);
        $procProp->setValue($transporter, $process);

        $method = $ref->getMethod('waitForResponse');
        $method->setAccessible(true);

        $this->expectException(TransporterRequestException::class);
        $this->expectExceptionMessageMatches('/Timeout after 1 seconds/');

        $method->invoke($transporter, 'never-arrives');
    });

    it('process_timeout defaults to null and does not fall back to legacy timeout', function () {
        $transporter = new StdioTransporter(['command' => ['echo', 'hi'], 'timeout' => 30]);

        $ref = new ReflectionMethod($transporter, 'resolveProcessTimeout');
        $ref->setAccessible(true);

        expect($ref->invoke($transporter))->toBeNull();
    });

    it('process_timeout uses the configured value when set', function () {
        $transporter = new StdioTransporter(['command' => ['echo', 'hi'], 'process_timeout' => 90]);

        $ref = new ReflectionMethod($transporter, 'resolveProcessTimeout');
        $ref->setAccessible(true);

        expect($ref->invoke($transporter))->toBe(90.0);
    });

    it('resolveEnv returns null when no user env is provided so Symfony inherits the parent env', function () {
        $transporter = new StdioTransporter(['command' => ['echo', 'hi']]);

        $ref = new ReflectionMethod($transporter, 'resolveEnv');
        $ref->setAccessible(true);

        expect($ref->invoke($transporter))->toBeNull();
    });

    it('resolveEnv returns null when env is an empty array', function () {
        $transporter = new StdioTransporter(['command' => ['echo', 'hi'], 'env' => []]);

        $ref = new ReflectionMethod($transporter, 'resolveEnv');
        $ref->setAccessible(true);

        expect($ref->invoke($transporter))->toBeNull();
    });

    it('resolveEnv merges user env on top of the parent env when env is supplied', function () {
        $parentKey = 'MCP_CLIENT_TEST_PARENT_ONLY';
        $parentValue = 'parent-only-value';
        putenv("$parentKey=$parentValue");

        try {
            $transporter = new StdioTransporter([
                'command' => ['echo', 'hi'],
                'env' => ['FOO' => 'bar'],
            ]);

            $ref = new ReflectionMethod($transporter, 'resolveEnv');
            $ref->setAccessible(true);
            $env = $ref->invoke($transporter);

            expect($env)->toBeArray()
                ->and($env['FOO'])->toBe('bar')
                ->and($env[$parentKey] ?? null)->toBe($parentValue);
        } finally {
            putenv($parentKey);
        }
    });

    it('resolveEnv user keys win over parent env when both define the same key', function () {
        $key = 'MCP_CLIENT_TEST_OVERRIDE';
        putenv("$key=parent-value");
        try {
            $transporter = new StdioTransporter([
                'command' => ['echo', 'hi'],
                'env' => [$key => 'user-value'],
            ]);

            $ref = new ReflectionMethod($transporter, 'resolveEnv');
            $ref->setAccessible(true);
            $env = $ref->invoke($transporter);

            expect($env[$key])->toBe('user-value');
        } finally {
            putenv($key);
        }
    });

    it('resolveEnv returns only user keys when inherit_env is false (sealed env)', function () {
        $transporter = new StdioTransporter([
            'command' => ['echo', 'hi'],
            'env' => ['FOO' => 'bar'],
            'inherit_env' => false,
        ]);

        $ref = new ReflectionMethod($transporter, 'resolveEnv');
        $ref->setAccessible(true);
        $env = $ref->invoke($transporter);

        expect($env)->toBe(['FOO' => 'bar']);
    });

    it('resolveEnv returns an empty array under inherit_env=false with no user env', function () {
        $transporter = new StdioTransporter([
            'command' => ['echo', 'hi'],
            'inherit_env' => false,
        ]);

        $ref = new ReflectionMethod($transporter, 'resolveEnv');
        $ref->setAccessible(true);

        expect($ref->invoke($transporter))->toBe([]);
    });

    it('handleStartupFailure surfaces stderr in the exception message', function () {
        $command = PHP_OS_FAMILY === 'Windows'
            ? ['cmd', '/c', 'echo bootfail-marker 1>&2 & exit 1']
            : ['sh', '-c', 'printf bootfail-marker 1>&2; exit 1'];

        $transporter = new StdioTransporter(['command' => $command]);

        try {
            $transporter->request('something');
            $this->fail('Expected TransporterRequestException');
        } catch (TransporterRequestException $e) {
            expect($e->getMessage())
                ->toContain('Process failed to start')
                ->toContain('bootfail-marker');
        }
    });

    it('cleanup stops running process and unsets properties', function () {
        $transporter = Mockery::mock(StdioTransporter::class, [['command' => ['echo', 'run']]])->makePartial();
        $transporter->shouldAllowMockingProtectedMethods();

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isRunning')->once()->andReturn(true);
        $process->shouldReceive('stop')->once();

        $ref = new ReflectionClass(StdioTransporter::class);

        $procProp = $ref->getProperty('process');
        $procProp->setAccessible(true);
        $procProp->setValue($transporter, $process);

        $inputStreamProp = $ref->getProperty('inputStream');
        $inputStreamProp->setAccessible(true);
        $inputStream = Mockery::mock(InputStream::class);
        $inputStreamProp->setValue($transporter, $inputStream);

        $cleanupMethod = $ref->getMethod('cleanup');
        $cleanupMethod->setAccessible(true);

        $cleanupMethod->invoke($transporter);

        expect(true)->toBeTrue();
    });

});
