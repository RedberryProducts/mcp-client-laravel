# MCP Client for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/redberry/mcp-client-laravel.svg?style=flat-square)](https://packagist.org/packages/redberry/mcp-client-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/redberryproducts/mcp-client-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/redberryproducts/mcp-client-laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/redberryproducts/mcp-client-laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/redberryproducts/mcp-client-laravel/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/redberry/mcp-client-laravel.svg?style=flat-square)](https://packagist.org/packages/redberry/mcp-client-laravel)

A Laravel client for the [Model Context Protocol](https://modelcontextprotocol.io/). It speaks JSON-RPC 2.0 over both [Streamable HTTP](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http) (`2025-03-26`) and STDIO, and exposes a single facade for listing and calling tools and reading resources. The HTTP transport content-negotiates with the server on every request, so you receive the final result whether the server replies with one JSON object or a stream of server-sent events.

## Installation

Install the package via Composer:

```bash
composer require redberry/mcp-client-laravel
```

Then publish the configuration file:

```bash
php artisan vendor:publish --tag="mcp-client-config"
```

This will create a `config/mcp-client.php` file in your application.

## Configuration

The `mcp-client.servers` array maps a server name to its connection details. Each server uses one of two transports — `HTTP` for remote servers, `STDIO` for local subprocesses:

```php
use Redberry\MCPClient\Enums\Transporters;

return [
    'servers' => [
        'github' => [
            'type'     => Transporters::HTTP,
            'base_url' => 'https://api.githubcopilot.com/mcp',
            'token'    => env('GITHUB_API_TOKEN'),
            'timeout'  => 30,
        ],

        'memory' => [
            'type'    => Transporters::STDIO,
            'command' => ['npx', '-y', '@modelcontextprotocol/server-memory'],
            'cwd'     => base_path(),
        ],
    ],
];
```

### HTTP Transport

The HTTP transport implements MCP's [Streamable HTTP transport](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http). On the first request, it performs the `initialize` handshake and captures the `mcp-session-id` for the rest of the session. If the server later signals that the session has expired with an HTTP `404`, the client clears its session, re-handshakes, and retries the call once.

| Key | Default | Description |
| --- | --- | --- |
| `base_url` | — | The MCP endpoint URL. |
| `token` | `null` | Bearer token; sent as `Authorization: Bearer {token}` when present. |
| `timeout` | `30` | Connection timeout in seconds. |
| `read_timeout` | `60` | Maximum gap between SSE chunks before the parser aborts a wedged stream. The clock resets on every chunk. Set to `null` to disable. |
| `max_session_retries` | `1` | Automatic retries after a session-loss `404`. Set to `0` to disable. |
| `headers` | `[]` | Additional headers merged into every request. |
| `id_type` | `'int'` | `'int'` or `'string'`; controls how JSON-RPC ids are cast. |

### STDIO Transport

The STDIO transport launches the configured command as a subprocess and exchanges newline-delimited JSON-RPC over its standard streams. The subprocess starts lazily on the first call and is reused for every subsequent request.

| Key | Default | Description |
| --- | --- | --- |
| `command` | — | Array of command parts to launch. |
| `cwd` | `null` | Working directory for the subprocess. |
| `env` | `null` | Environment variables, merged on top of the parent environment. |
| `inherit_env` | `true` | When `false`, the subprocess receives only the keys listed in `env`. |
| `request_timeout` | `30` | Per-call wait for a JSON-RPC response, in seconds. Falls back to the legacy `timeout` key when only that is set. |
| `process_timeout` | `null` | Symfony Process kill timer, in seconds. Set this only if you need a hard upper bound on the subprocess lifetime. |
| `startup_delay` | `100` | Milliseconds to wait after `Process::start()` before sending the `initialize` handshake. Increase if a cold-start `npx -y …` is still booting when the handshake fires. |
| `poll_interval` | `20` | Milliseconds between reads of the subprocess output buffer. |

> **Note.** The STDIO transport does not work under `php artisan serve`. The built-in PHP development server tears down its worker between requests, which kills the long-running subprocess. Run your application via Octane, a queue worker, Sail, or Valet to use STDIO servers.

## Connecting to a Server

Resolve the client and call `connect` with a server name from your configuration:

```php
use Redberry\MCPClient\Facades\MCPClient;

$github = MCPClient::connect('github');
```

The container binds the client as a singleton and aliases the `Redberry\MCPClient\Contracts\MCPClient` interface to it, so the facade and dependency injection on the contract resolve the same instance:

```php
use Redberry\MCPClient\Contracts\MCPClient;

class GithubToolService
{
    public function __construct(private MCPClient $client) {}

    public function tools()
    {
        return $this->client->connect('github')->tools();
    }
}
```

`connect` returns a per-server clone of the client, so you may hold handles to multiple servers at once without one routing through another:

```php
$github = MCPClient::connect('github');
$memory = MCPClient::connect('memory');

$github->tools();
$memory->tools();
```

Repeated `connect` calls for the same server reuse a cached transporter, so the `initialize` handshake is paid once per server per root instance.

## Listing Tools and Resources

The `tools` and `resources` methods return a `Collection` of associative arrays:

```php
$tools = $github->tools();

$tools->all();                          // raw array
$tools->only('search', 'create_issue'); // by name
$tools->except('delete_repository');    // by name
$tools->map(fn ($tool) => $tool['name']);
```

The same `Collection` wraps both lists, but `only` and `except` know which key to filter on — `name` for tools and `uri` for resources.

## Calling a Tool

Pass the tool name and an associative array of arguments. The method returns the decoded JSON-RPC `result` array:

```php
$result = $github->callTool('create_issue', [
    'owner' => 'laravel',
    'repo'  => 'framework',
    'title' => 'Documentation feedback',
    'body'  => '…',
]);
```

## Reading a Resource

Pass the URI of the resource. The method returns the decoded JSON-RPC `result` array:

```php
$result = $github->readResource('file:///project/src/main.rs');
```

## Streaming Events

Both `callTool` and `readResource` accept an optional callback as the last argument. When the server replies with an SSE stream, the callback is invoked for every decoded JSON-RPC message — progress notifications, log entries, partial results, and the final result-bearing one — as each arrives. The call still blocks until the final result is returned:

```php
$result = $github->callTool('long_running_task', $args, function (array $event) {
    Log::info('mcp event', $event);
});
```

The callback is invoked zero times when the server replies with a single JSON object, so it is safe to pass unconditionally. Streaming is an HTTP-only concept; the callback is a no-op under the STDIO transport.

## Custom Transports

The package ships with HTTP and STDIO transports, and the IO seam is a single interface — `Redberry\MCPClient\Core\Transporters\Transporter`. To add another, implement the interface, register a case on `Redberry\MCPClient\Enums\Transporters`, and add a `match` arm to `TransporterFactory::make`. See [`ARCHITECTURE.md`](ARCHITECTURE.md) and [`.claude/rules/transporters.md`](.claude/rules/transporters.md) for the full contract.

## Testing

```bash
composer test
```

## Upgrading

Upgrading from `1.x` to `2.x`? See [`UPGRADE.md`](UPGRADE.md) for the migration guide.

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md) for the list of changes.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for guidelines.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) before reporting a vulnerability.

## Credits

- [Nika Jorjoliani](https://github.com/nikajorjika)
- [All Contributors](../../contributors)

Built and maintained by [Redberry](https://redberry.international/?utm_source=github&utm_medium=github_mcp_readme&utm_campaign=AI+service+campaign), a Diamond-tier Laravel partner. We also run a [5-week AI agent PoC sprint](https://redberry.international/ai-agent-development/?utm_source=github&utm_medium=github_mcp_readme&utm_campaign=AI+service+campaign) for teams exploring agentic features in Laravel.

## License

The MIT License (MIT). Please see [`LICENSE.md`](LICENSE.md) for more information.
