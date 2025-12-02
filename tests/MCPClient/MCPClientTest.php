<?php

use Illuminate\Support\Facades\Config;
use Redberry\MCPClient\Collection;
use Redberry\MCPClient\Core\TransporterPool;
use Redberry\MCPClient\Core\Transporters\Transporter;
use Redberry\MCPClient\Enums\Transporters;
use Redberry\MCPClient\MCPClient;

beforeEach(function () {
    Config::set('mcp-client.servers', [
        'without_enum' => [
            'type' => Transporters::HTTP,
            'base_url' => 'https://example.com/mcp',
            'timeout' => 30,
            'token' => 'token_value',
        ],
        'using_enum' => [
            'type' => Transporters::HTTP,
            'base_url' => 'https://example.com/mcp',
            'timeout' => 30,
            'token' => 'token_value',
        ],
        'npx_mcp_server' => [
            'type' => Transporters::STDIO,
            'command' => [
                'npx',
                '-y',
                '@modelcontextprotocol/some-server',
            ],
            'timeout' => 30,
            'root_path' => '../path/to/mcp-server',
        ],
    ]);
});
describe('MCPClient', function () {

    test('connect sets server config and transporter', function () {

        $mockPool = Mockery::mock(TransporterPool::class);
        $mockTransporter = Mockery::mock(Transporter::class);

        $mockPool->shouldReceive('get')
            ->once()
            ->with('using_enum', config('mcp-client.servers.using_enum'))
            ->andReturn($mockTransporter);

        $client = new MCPClient(config('mcp-client.servers'), $mockPool);
        $connected = $client->connect('using_enum');

        expect($connected)->toBeInstanceOf(MCPClient::class);
    });

    test('connect sets server config and transporter when type is not enum', function () {

        $mockPool = Mockery::mock(TransporterPool::class);
        $mockTransporter = Mockery::mock(Transporter::class);

        $mockPool->shouldReceive('get')
            ->once()
            ->with('without_enum', config('mcp-client.servers.without_enum'))
            ->andReturn($mockTransporter);

        $client = new MCPClient(config('mcp-client.servers'), $mockPool);
        $connected = $client->connect('without_enum');

        expect($connected)->toBeInstanceOf(MCPClient::class);
    });

    test('tools returns collection of tools', function () {
        $mockTransporter = Mockery::mock(Transporter::class);
        $mockPool = Mockery::mock(TransporterPool::class);

        $mockTransporter->shouldReceive('request')
            ->once()
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'tool1'], ['name' => 'tool2']]]);

        $mockPool->shouldReceive('get')->andReturn($mockTransporter);

        $client = new MCPClient(config('mcp-client.servers'), $mockPool);
        $client->connect('using_enum');
        $tools = $client->tools();

        expect($tools)->toBeInstanceOf(Collection::class)
            ->toHaveCount(2);
    });

    test('resources returns collection of resources', function () {
        $mockTransporter = Mockery::mock(Transporter::class);
        $mockPool = Mockery::mock(TransporterPool::class);
        $mockTransporter->shouldReceive('request')
            ->once()
            ->with('resources/list')
            ->andReturn(['resources' => [['id' => 1], ['id' => 2]]]);

        $mockPool->shouldReceive('get')->andReturn($mockTransporter);

        $client = new MCPClient(config('mcp-client.servers'), $mockPool);
        $client->connect('using_enum');
        $resources = $client->resources();

        expect($resources)->toBeInstanceOf(Collection::class)
            ->toHaveCount(2);
    });

    test('tools throws exception when not connected', function () {
        $client = new MCPClient(config('mcp-client.servers'));

        $client->tools(); // should throw
    })->throws(RuntimeException::class, 'Server configuration is not set. Please connect to a server first.');

    test('resources throws exception when not connected', function () {
        $client = new MCPClient(config('mcp-client.servers'));

        $client->resources(); // should throw
    })->throws(RuntimeException::class, 'Server configuration is not set. Please connect to a server first.');

    test('multiple connects to same server reuse transporter', function () {
        $mockPool = Mockery::mock(TransporterPool::class);
        $mockTransporter = Mockery::mock(Transporter::class);

        // The pool's get method will be called twice (once per connect)
        // but it should return the same transporter instance
        $mockPool->shouldReceive('get')
            ->twice()
            ->with('using_enum', config('mcp-client.servers.using_enum'))
            ->andReturn($mockTransporter);

        $mockTransporter->shouldReceive('request')
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'tool1']]]);

        $mockTransporter->shouldReceive('request')
            ->with('resources/list')
            ->andReturn(['resources' => [['id' => 1]]]);

        $client = new MCPClient(config('mcp-client.servers'), $mockPool);

        // First connect
        $client->connect('using_enum');
        $tools = $client->tools();

        // Second connect to the same server - pool returns the same transporter
        $client->connect('using_enum');
        $resources = $client->resources();

        expect($tools)->toBeInstanceOf(Collection::class)
            ->and($resources)->toBeInstanceOf(Collection::class);
    });
});
