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
