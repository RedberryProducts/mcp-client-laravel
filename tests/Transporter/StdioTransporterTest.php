<?php

use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
use Redberry\MCPClient\Core\Transporters\StdioTransporter;

afterEach(function () {
    Mockery::close();
});

test('preparePayload builds correct payload', function () {
    $transporter = new StdioTransporter;
    $method = new ReflectionMethod(StdioTransporter::class, 'preparePayload');
    $method->setAccessible(true);

    $payload = $method->invoke($transporter, 'test', ['a' => 1]);

    expect($payload['jsonrpc'])->toBe('2.0');
    expect($payload['method'])->toBe('test');
    expect($payload['params'])->toEqual(['a' => 1]);
    expect(is_string($payload['id']) && preg_match('/^\d+$/', $payload['id']))->toBeTrue();
});

test('generateId returns numeric string', function () {
    $transporter = new StdioTransporter;
    $method = new ReflectionMethod(StdioTransporter::class, 'generateId');
    $method->setAccessible(true);

    $id = $method->invoke($transporter);

    expect(is_string($id))->toBeTrue();
    expect(preg_match('/^\d+$/', $id) === 1)->toBeTrue();
});

test('successful request returns result', function () {
    $command = ['php', '-r', 'echo json_encode(["result"=>["foo"=>"bar"]]);'];
    $transporter = new StdioTransporter(['command' => $command]);

    $result = $transporter->request('foo');

    expect($result)->toEqual(['foo' => 'bar']);
});

test('successful request returns full data when no result', function () {
    $command = ['php', '-r', 'echo json_encode(["foo"=>"bar"]);'];
    $transporter = new StdioTransporter(['command' => $command]);

    $result = $transporter->request('bar');

    expect($result)->toEqual(['foo' => 'bar']);
});

test('invalid JSON throws TransporterRequestException', function () {
    $command = ['php', '-r', 'echo "not-json";'];
    $transporter = new StdioTransporter(['command' => $command]);

    $this->expectException(TransporterRequestException::class);
    $this->expectExceptionMessage('Invalid JSON response:');

    $transporter->request('bad');
});

test('error response throws TransporterRequestException', function () {
    $command = ['php', '-r', 'echo json_encode(["error"=>["message"=>"fail","code"=>123]]);'];
    $transporter = new StdioTransporter(['command' => $command]);

    $this->expectException(TransporterRequestException::class);
    $this->expectExceptionMessage('JSON-RPC error: fail');
    $this->expectExceptionCode(123);

    $transporter->request('error');
});

test('process failure throws TransporterRequestException', function () {
    $command = ['php', '-r', 'fwrite(STDERR,"error"); exit(1);'];
    $transporter = new StdioTransporter(['command' => $command]);

    $this->expectException(TransporterRequestException::class);
    $transporter->request('fail');
});

test('missing command throws InvalidArgumentException', function () {
    $transporter = new StdioTransporter;

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('STDIO command is not defined');

    $transporter->request('foo');
});

test('createProcess respects root_path and timeout', function () {
    $config = [
        'command' => ['php', '-v'],
        'root_path' => __DIR__,
        'timeout' => 10,
    ];
    $transporter = new StdioTransporter($config);
    $method = new ReflectionMethod(StdioTransporter::class, 'createProcess');
    $method->setAccessible(true);

    $process = $method->invoke($transporter, 'input');

    expect($process->getWorkingDirectory())->toBe(__DIR__);
    expect($process->getTimeout())->toBe(10.0);
    expect($process->getCommandLine())->toContain('php');
});
