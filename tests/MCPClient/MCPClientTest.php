<?php

use Illuminate\Support\Facades\Config;
use Redberry\MCPClient\Collection;
use Redberry\MCPClient\Core\TransporterFactory;
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

        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter = Mockery::mock(Transporter::class);

        $mockFactory->shouldReceive('make')
            ->once()
            ->with(config('mcp-client.servers.using_enum'))
            ->andReturn($mockTransporter);

        $client = new MCPClient(config('mcp-client.servers'), $mockFactory);
        $connected = $client->connect('using_enum');

        expect($connected)->toBeInstanceOf(MCPClient::class);
    });

    test('connect sets server config and transporter when type is not enum', function () {

        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter = Mockery::mock(Transporter::class);

        $mockFactory->shouldReceive('make')
            ->once()
            ->with(config('mcp-client.servers.without_enum'))
            ->andReturn($mockTransporter);

        $client = new MCPClient(config('mcp-client.servers'), $mockFactory);
        $connected = $client->connect('without_enum');

        expect($connected)->toBeInstanceOf(MCPClient::class);
    });

    test('tools returns collection of tools', function () {
        $mockTransporter = Mockery::mock(Transporter::class);
        $mockFactory = Mockery::mock(TransporterFactory::class);

        $mockTransporter->shouldReceive('request')
            ->once()
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'tool1'], ['name' => 'tool2']]]);

        $mockFactory->shouldReceive('make')->andReturn($mockTransporter);

        $client = new MCPClient(config('mcp-client.servers'), $mockFactory);
        $client->connect('using_enum');
        $tools = $client->tools();

        expect($tools)->toBeInstanceOf(Collection::class)
            ->toHaveCount(2);
    });

    test('resources returns collection of resources', function () {
        $mockTransporter = Mockery::mock(Transporter::class);
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter->shouldReceive('request')
            ->once()
            ->with('resources/list')
            ->andReturn(['resources' => [['id' => 1], ['id' => 2]]]);

        $mockFactory->shouldReceive('make')->andReturn($mockTransporter);

        $client = new MCPClient(config('mcp-client.servers'), $mockFactory);
        $client->connect('using_enum');
        $resources = $client->resources();

        expect($resources)->toBeInstanceOf(Collection::class)
            ->toHaveCount(2);
    });

    test('callTool forwards onEvent callback to the transporter', function () {
        $callback = fn (array $event) => null;

        $mockTransporter = Mockery::mock(Transporter::class);
        $mockFactory = Mockery::mock(TransporterFactory::class);

        $mockTransporter->shouldReceive('request')
            ->once()
            ->with('tools/call', Mockery::type('array'), $callback)
            ->andReturn(['ok' => true]);

        $mockFactory->shouldReceive('make')->andReturn($mockTransporter);

        $client = new MCPClient(config('mcp-client.servers'), $mockFactory);
        $client->connect('using_enum');

        expect($client->callTool('do', ['x' => 1], $callback))->toEqual(['ok' => true]);
    });

    test('readResource forwards onEvent callback to the transporter', function () {
        $callback = fn (array $event) => null;

        $mockTransporter = Mockery::mock(Transporter::class);
        $mockFactory = Mockery::mock(TransporterFactory::class);

        $mockTransporter->shouldReceive('request')
            ->once()
            ->with('resources/read', ['uri' => 'file:///x'], $callback)
            ->andReturn(['content' => 'hi']);

        $mockFactory->shouldReceive('make')->andReturn($mockTransporter);

        $client = new MCPClient(config('mcp-client.servers'), $mockFactory);
        $client->connect('using_enum');

        expect($client->readResource('file:///x', $callback))->toEqual(['content' => 'hi']);
    });

    test('resources()->only() filters by uri (not name)', function () {
        $mockTransporter = Mockery::mock(Transporter::class);
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter->shouldReceive('request')
            ->once()
            ->with('resources/list')
            ->andReturn(['resources' => [
                ['uri' => 'file:///a', 'name' => 'first'],
                ['uri' => 'file:///b', 'name' => 'second'],
            ]]);

        $mockFactory->shouldReceive('make')->andReturn($mockTransporter);

        $client = new MCPClient(config('mcp-client.servers'), $mockFactory);
        $client->connect('using_enum');

        expect($client->resources()->only('file:///a')->all())->toBe([
            ['uri' => 'file:///a', 'name' => 'first'],
        ]);
    });

    test('tools()->only() filters by name (regression)', function () {
        $mockTransporter = Mockery::mock(Transporter::class);
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter->shouldReceive('request')
            ->once()
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'toolA'], ['name' => 'toolB']]]);

        $mockFactory->shouldReceive('make')->andReturn($mockTransporter);

        $client = new MCPClient(config('mcp-client.servers'), $mockFactory);
        $client->connect('using_enum');

        expect($client->tools()->only('toolA')->all())->toBe([['name' => 'toolA']]);
    });

    test('tools throws exception when not connected', function () {
        $client = new MCPClient(config('mcp-client.servers'));

        $client->tools(); // should throw
    })->throws(RuntimeException::class, 'Server configuration is not set. Please connect to a server first.');

    test('resources throws exception when not connected', function () {
        $client = new MCPClient(config('mcp-client.servers'));

        $client->resources(); // should throw
    })->throws(RuntimeException::class, 'Server configuration is not set. Please connect to a server first.');
});
