<?php

use Illuminate\Support\Facades\Artisan;
use Redberry\MCPClient\Collection;
use Redberry\MCPClient\Facades\MCPClient;

test('test-connection command reports success', function () {
    MCPClient::shouldReceive('connect')
        ->once()
        ->with('github')
        ->andReturnSelf();

    $exitCode = Artisan::call('mcp-client:test-connection', ['server' => 'github']);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Successfully connected');
});

test('fetch-tools command lists tools', function () {
    MCPClient::shouldReceive('connect')
        ->once()
        ->with('github')
        ->andReturnSelf();
    MCPClient::shouldReceive('tools')
        ->once()
        ->andReturn(new Collection([[ 'name' => 'tool1' ]]));

    $exitCode = Artisan::call('mcp-client:fetch-tools', ['server' => 'github']);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('tool1');
});

test('fetch-resources command lists resources', function () {
    MCPClient::shouldReceive('connect')
        ->once()
        ->with('github')
        ->andReturnSelf();
    MCPClient::shouldReceive('resources')
        ->once()
        ->andReturn(new Collection([[ 'id' => 1 ]]));

    $exitCode = Artisan::call('mcp-client:fetch-resources', ['server' => 'github']);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('1');
});

test('test-all command iterates over all servers', function () {
    config()->set('mcp-client.servers', [ 'github' => [], 'local' => [] ]);

    MCPClient::shouldReceive('connect')->twice()->andReturnSelf();

    $exitCode = Artisan::call('mcp-client:test-all');

    expect($exitCode)->toBe(0);
});
