<?php

use Illuminate\Support\Facades\Config;
use Redberry\MCPClient\Collection;
use Redberry\MCPClient\Contracts\MCPClient as MCPClientContract;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
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
        'second_http' => [
            'type' => Transporters::HTTP,
            'base_url' => 'https://other.example.com/mcp',
            'timeout' => 30,
            'token' => 'other_token',
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

        $client = (new MCPClient(config('mcp-client.servers'), $mockFactory))->connect('using_enum');
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

        $client = (new MCPClient(config('mcp-client.servers'), $mockFactory))->connect('using_enum');
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

        $client = (new MCPClient(config('mcp-client.servers'), $mockFactory))->connect('using_enum');

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

        $client = (new MCPClient(config('mcp-client.servers'), $mockFactory))->connect('using_enum');

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

        $client = (new MCPClient(config('mcp-client.servers'), $mockFactory))->connect('using_enum');

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

        $client = (new MCPClient(config('mcp-client.servers'), $mockFactory))->connect('using_enum');

        expect($client->tools()->only('toolA')->all())->toBe([['name' => 'toolA']]);
    });

    test('tools throws exception when not connected', function () {
        $client = new MCPClient(config('mcp-client.servers'));

        $client->tools(); // should throw
    })->throws(RuntimeException::class, 'Call connect($serverName) before tools().');

    test('resources throws exception when not connected', function () {
        $client = new MCPClient(config('mcp-client.servers'));

        $client->resources(); // should throw
    })->throws(RuntimeException::class, 'Call connect($serverName) before resources().');

    test('callTool throws RuntimeException (not TypeError) when not connected', function () {
        $client = new MCPClient(config('mcp-client.servers'));

        $client->callTool('whatever');
    })->throws(RuntimeException::class, 'Call connect($serverName) before callTool().');

    test('readResource throws RuntimeException (not TypeError) when not connected', function () {
        $client = new MCPClient(config('mcp-client.servers'));

        $client->readResource('file:///x');
    })->throws(RuntimeException::class, 'Call connect($serverName) before readResource().');

    test('connect throws helpful error for unknown server', function () {
        $client = new MCPClient(config('mcp-client.servers'));

        $client->connect('does_not_exist');
    })->throws(
        RuntimeException::class,
        "Unknown MCP server 'does_not_exist'. Configured servers: without_enum, using_enum, second_http, npx_mcp_server."
    );

    test('connect lists "(none)" when no servers are configured', function () {
        $client = new MCPClient([]);

        $client->connect('anything');
    })->throws(
        RuntimeException::class,
        "Unknown MCP server 'anything'. Configured servers: (none)."
    );

    test('connect returns a clone — root remains unconnected', function () {
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter = Mockery::mock(Transporter::class);

        $mockFactory->shouldReceive('make')->once()->andReturn($mockTransporter);

        $root = new MCPClient(config('mcp-client.servers'), $mockFactory);
        $connected = $root->connect('using_enum');

        expect($connected)->not->toBe($root);

        // Root is still unconnected and must throw.
        $root->tools();
    })->throws(RuntimeException::class, 'Call connect($serverName) before tools().');

    test('two handles to two servers stay isolated', function () {
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $transporterA = Mockery::mock(Transporter::class);
        $transporterB = Mockery::mock(Transporter::class);

        $mockFactory->shouldReceive('make')
            ->once()
            ->with(config('mcp-client.servers.using_enum'))
            ->andReturn($transporterA);

        $mockFactory->shouldReceive('make')
            ->once()
            ->with(config('mcp-client.servers.second_http'))
            ->andReturn($transporterB);

        $transporterA->shouldReceive('request')
            ->once()
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'a-tool']]]);

        $transporterB->shouldReceive('request')
            ->once()
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'b-tool']]]);

        $root = new MCPClient(config('mcp-client.servers'), $mockFactory);
        $a = $root->connect('using_enum');
        $b = $root->connect('second_http');

        expect($a)->not->toBe($b)
            ->and($a->tools()->all())->toBe([['name' => 'a-tool']])
            ->and($b->tools()->all())->toBe([['name' => 'b-tool']]);
    });

    test('connect from a clone shares the cache with the root', function () {
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $transporterA = Mockery::mock(Transporter::class);
        $transporterB = Mockery::mock(Transporter::class);

        // Each server transporter must be created exactly once across the whole
        // chain — even though one of the connect() calls happens on a clone.
        $mockFactory->shouldReceive('make')
            ->once()
            ->with(config('mcp-client.servers.using_enum'))
            ->andReturn($transporterA);

        $mockFactory->shouldReceive('make')
            ->once()
            ->with(config('mcp-client.servers.second_http'))
            ->andReturn($transporterB);

        $root = new MCPClient(config('mcp-client.servers'), $mockFactory);

        $a = $root->connect('using_enum');     // caches 'using_enum'
        $b = $a->connect('second_http');       // chained on the clone — must cache 'second_http' on the shared cache
        $bAgain = $root->connect('second_http'); // root must hit the cache, not re-make

        expect($b)->not->toBe($bAgain);
    });

    test('connect caches the transporter per server (handshake paid once)', function () {
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter = Mockery::mock(Transporter::class);

        // make() must be called exactly once across two connect('same') calls;
        // Mockery's ->once() expectation enforces the cache contract at teardown.
        $mockFactory->shouldReceive('make')
            ->once()
            ->with(config('mcp-client.servers.using_enum'))
            ->andReturn($mockTransporter);

        // Both handles must route through the one cached transporter. Uses
        // callTool() because tools()/resources() results are themselves cached.
        $mockTransporter->shouldReceive('request')
            ->twice()
            ->with('tools/call', Mockery::type('array'), null)
            ->andReturn(['ok' => true]);

        $root = new MCPClient(config('mcp-client.servers'), $mockFactory);
        $first = $root->connect('using_enum');
        $second = $root->connect('using_enum');

        expect($first)->not->toBe($second);

        $first->callTool('do');
        $second->callTool('do');
    });

    test('container resolves MCPClient as a singleton', function () {
        expect(app(MCPClient::class))->toBe(app(MCPClient::class));
    });

    test('container resolves the contract to the same singleton', function () {
        expect(app(MCPClientContract::class))->toBe(app(MCPClient::class));
    });
});

/**
 * Root client whose single transporter serves every configured server.
 */
function cachingClient(Transporter $transporter): MCPClient
{
    $factory = Mockery::mock(TransporterFactory::class);
    $factory->shouldReceive('make')->andReturn($transporter);

    return new MCPClient(config('mcp-client.servers'), $factory);
}

describe('MCPClient list caching', function () {

    test('first tools() call fetches from the server, second returns the cached result', function () {
        $transporter = Mockery::mock(Transporter::class);
        $transporter->shouldReceive('request')
            ->once()
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'tool1']]]);

        $client = cachingClient($transporter)->connect('using_enum');

        expect($client->tools()->all())->toBe([['name' => 'tool1']])
            ->and($client->tools()->all())->toBe([['name' => 'tool1']]);
    });

    test('resources() results are cached', function () {
        $transporter = Mockery::mock(Transporter::class);
        $transporter->shouldReceive('request')
            ->once()
            ->with('resources/list')
            ->andReturn(['resources' => [['uri' => 'file:///a']]]);

        $client = cachingClient($transporter)->connect('using_enum');
        $client->resources();

        expect($client->resources()->only('file:///a')->all())->toBe([['uri' => 'file:///a']]);
    });

    test('tools and resources are cached under separate keys', function () {
        $transporter = Mockery::mock(Transporter::class);
        $transporter->shouldReceive('request')->once()->with('tools/list')
            ->andReturn(['tools' => [['name' => 'tool1']]]);
        $transporter->shouldReceive('request')->once()->with('resources/list')
            ->andReturn(['resources' => [['uri' => 'file:///a']]]);

        $client = cachingClient($transporter)->connect('using_enum');

        expect($client->tools()->all())->toBe([['name' => 'tool1']])
            ->and($client->resources()->all())->toBe([['uri' => 'file:///a']])
            ->and($client->tools()->all())->toBe([['name' => 'tool1']])
            ->and($client->resources()->all())->toBe([['uri' => 'file:///a']]);
    });

    test('fresh: true bypasses the cache and refreshes it', function () {
        $transporter = Mockery::mock(Transporter::class);
        $transporter->shouldReceive('request')
            ->twice()
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'old']]], ['tools' => [['name' => 'new']]]);

        $client = cachingClient($transporter)->connect('using_enum');

        expect($client->tools()->all())->toBe([['name' => 'old']])
            ->and($client->tools(fresh: true)->all())->toBe([['name' => 'new']])
            // The fresh result replaced the cached one.
            ->and($client->tools()->all())->toBe([['name' => 'new']]);
    });

    test('fresh: true bypasses the cache for resources()', function () {
        $transporter = Mockery::mock(Transporter::class);
        $transporter->shouldReceive('request')
            ->twice()
            ->with('resources/list')
            ->andReturn(['resources' => [['uri' => 'file:///old']]], ['resources' => [['uri' => 'file:///new']]]);

        $client = cachingClient($transporter)->connect('using_enum');
        $client->resources();

        expect($client->resources(fresh: true)->all())->toBe([['uri' => 'file:///new']]);
    });

    test('clearCache() drops cached results', function () {
        $transporter = Mockery::mock(Transporter::class);
        $transporter->shouldReceive('request')->twice()->with('tools/list')
            ->andReturn(['tools' => [['name' => 'tool1']]]);
        $transporter->shouldReceive('request')->twice()->with('resources/list')
            ->andReturn(['resources' => [['uri' => 'file:///a']]]);

        $client = cachingClient($transporter)->connect('using_enum');
        $client->tools();
        $client->resources();

        expect($client->clearCache())->toBe($client);

        $client->tools();
        $client->resources();
    });

    test('disableCache() makes every call hit the server', function () {
        $transporter = Mockery::mock(Transporter::class);
        $transporter->shouldReceive('request')
            ->twice()
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'tool1']]]);

        $client = cachingClient($transporter)->connect('using_enum');

        expect($client->disableCache())->toBe($client);

        $client->tools();
        $client->tools();
    });

    test('calls made while the cache is disabled do not populate it', function () {
        $transporter = Mockery::mock(Transporter::class);
        $transporter->shouldReceive('request')
            ->twice()
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'while-disabled']]], ['tools' => [['name' => 'after-enable']]]);

        $client = cachingClient($transporter)->connect('using_enum');
        $client->disableCache()->tools();

        expect($client->enableCache()->tools()->all())->toBe([['name' => 'after-enable']]);
    });

    test('enableCache() re-enables caching', function () {
        $transporter = Mockery::mock(Transporter::class);
        // One call while disabled, then one call that populates the cache.
        $transporter->shouldReceive('request')
            ->twice()
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'tool1']]]);

        $client = cachingClient($transporter)->connect('using_enum');
        $client->disableCache();
        $client->tools();

        expect($client->enableCache())->toBe($client);

        $client->tools();
        $client->tools();
    });

    test('disableCache() on the root carries over to clones from connect()', function () {
        $transporter = Mockery::mock(Transporter::class);
        $transporter->shouldReceive('request')
            ->twice()
            ->with('tools/list')
            ->andReturn(['tools' => []]);

        $root = cachingClient($transporter)->disableCache();

        $root->connect('using_enum')->tools();
        $root->connect('using_enum')->tools();
    });

    test('cache is shared across connect() clones of the same server', function () {
        $transporter = Mockery::mock(Transporter::class);
        $transporter->shouldReceive('request')
            ->once()
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'tool1']]]);

        $root = cachingClient($transporter);

        // The facade pattern: a new clone per call, still served from the cache.
        $root->connect('using_enum')->tools();

        expect($root->connect('using_enum')->tools()->all())->toBe([['name' => 'tool1']]);
    });

    test('cache keys include the server name — servers never share results', function () {
        $factory = Mockery::mock(TransporterFactory::class);
        $transporterA = Mockery::mock(Transporter::class);
        $transporterB = Mockery::mock(Transporter::class);

        $factory->shouldReceive('make')->with(config('mcp-client.servers.using_enum'))->andReturn($transporterA);
        $factory->shouldReceive('make')->with(config('mcp-client.servers.second_http'))->andReturn($transporterB);

        $transporterA->shouldReceive('request')->once()->with('tools/list')
            ->andReturn(['tools' => [['name' => 'a-tool']]]);
        $transporterB->shouldReceive('request')->once()->with('tools/list')
            ->andReturn(['tools' => [['name' => 'b-tool']]]);

        $root = new MCPClient(config('mcp-client.servers'), $factory);
        $a = $root->connect('using_enum');

        expect($a->tools()->all())->toBe([['name' => 'a-tool']]);

        // Switching servers (including chained off a connected clone) never
        // serves the previous server's cached list.
        $b = $a->connect('second_http');

        expect($b->tools()->all())->toBe([['name' => 'b-tool']])
            ->and($a->tools()->all())->toBe([['name' => 'a-tool']])
            ->and($b->tools()->all())->toBe([['name' => 'b-tool']]);
    });

    test('a failed fetch is not cached', function () {
        $transporter = Mockery::mock(Transporter::class);
        $transporter->shouldReceive('request')
            ->once()
            ->ordered()
            ->with('tools/list')
            ->andThrow(new TransporterRequestException('boom'));
        $transporter->shouldReceive('request')
            ->once()
            ->ordered()
            ->with('tools/list')
            ->andReturn(['tools' => [['name' => 'tool1']]]);

        $client = cachingClient($transporter)->connect('using_enum');

        expect(fn () => $client->tools())->toThrow(TransporterRequestException::class, 'boom')
            ->and($client->tools()->all())->toBe([['name' => 'tool1']]);
    });

    test('callTool() and readResource() are never cached', function () {
        $transporter = Mockery::mock(Transporter::class);
        $transporter->shouldReceive('request')->twice()
            ->with('tools/call', Mockery::type('array'), null)->andReturn(['ok' => true]);
        $transporter->shouldReceive('request')->twice()
            ->with('resources/read', ['uri' => 'file:///x'], null)->andReturn(['content' => 'hi']);

        $client = cachingClient($transporter)->connect('using_enum');

        $client->callTool('do');
        $client->callTool('do');
        $client->readResource('file:///x');
        $client->readResource('file:///x');
    });

    test('tools(fresh: true) still requires a connection', function () {
        (new MCPClient(config('mcp-client.servers')))->tools(fresh: true);
    })->throws(RuntimeException::class, 'Call connect($serverName) before tools().');
});
