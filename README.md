# MCP Client for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/redberry/mcp-client-laravel.svg?style=flat-square)](https://packagist.org/packages/redberry/mcp-client-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/redberryproducts/mcp-client-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/redberryproducts/mcp-client-laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/redberryproducts/mcp-client-laravel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/redberryproducts/mcp-client-laravel/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/redberry/mcp-client-laravel.svg?style=flat-square)](https://packagist.org/packages/redberry/mcp-client-laravel)

<p align="center">
  <strong>Laravel-native client for Model Context Protocol (MCP) servers</strong><br>
  Built and maintained by <a href="https://redberry.international">Redberry</a>, a Diamond-tier official Laravel partner.
</p>

<p align="center">
  <a href="https://redberry.international/ai-agent-development/">AI PoC Sprint</a>
</p>

---

## 🚀 What is This?

This package provides a Laravel-native client for interacting with **Model Context Protocol (MCP)** servers — enabling your Laravel application to communicate with external tools, structured resources, and memory services in a standardized way.

It is **framework-agnostic** and can be used in any Laravel application. Agent frameworks like [LarAgent](https://github.com/MaestroError/LarAgent) use this package internally to enable tool use, memory management, and reasoning across distributed contexts.

Use it to:

- Connect to any MCP-compliant server over HTTP or STDIO
- Discover and call tools defined on MCP servers
- Access structured memory and contextual resources
- Extend your Laravel apps with AI-ready interfaces to external agents or toolchains

> 🚀 Looking to build an AI agent in Laravel? [Talk to us](https://redberry.international/ai-agent-development/) about our 5-week PoC sprint — from idea to working prototype.

## Installation
_Note that while project is running with `php artisan serve` **STDIO** transporter doesn't work_

You can install the package via composer:

```bash
composer require redberry/mcp-client-laravel
```

After installation, publish the configuration file:

```bash
php artisan vendor:publish --tag="mcp-client-config"
```

This will create a `config/mcp-client.php` file in your application.

## Configuration

The published configuration file contains settings for your MCP servers. Here's an example configuration:

```php
return [
    'servers' => [
        'github' => [
            'type' => \Redberry\MCPClient\Enums\Transporters::HTTP,
            'base_url' => 'https://api.githubcopilot.com/mcp',
            'timeout' => 30,
            'token' => env('GITHUB_API_TOKEN', null),
        ],
        'npx_mcp_server' => [
            'type' => \Redberry\MCPClient\Enums\Transporters::STDIO,
            'command' => [
                'npx',
                '-y',
                '@modelcontextprotocol/server-memory',
            ],
            'timeout' => 30,
            'cwd' => base_path(),
        ],
    ],
];
```

### Configuration Options

#### HTTP Transporter

-   `type`: Set to `Redberry\MCPClient\Enums\Transporters::HTTP` for HTTP connections
-   `base_url`: The base URL of the MCP server
-   `timeout`: Request timeout in seconds
-   `token`: Authentication token (if required)

#### STDIO Transporter

-   `type`: Set to `Redberry\MCPClient\Enums\Transporters::STDIO` for STDIO connections
-   `command`: Array of command parts to execute the MCP server
-   `timeout`: Command timeout in seconds
-   `cwd`: Current working directory for the command

## Usage

### Basic Usage

```php
use Redberry\MCPClient\Facades\MCPClient;

// Connect to a specific MCP server defined in your config
$client = MCPClient::connect('github');

// Get available tools from the MCP server
$tools = $client->tools();

// Get available resources from the MCP server
$resources = $client->resources();
```

### Using Dependency Injection

```php
use Redberry\MCPClient\MCPClient;

class MyService
{
    public function __construct(private MCPClient $mcpClient)
    {
    }

    public function getToolsFromGithub()
    {
        return $this->mcpClient->connect('github')->tools();
    }
}
```

### Working with Collections

The `tools()` and `resources()` methods return a `Collection` object that provides helpful methods for working with the results:

```php
// Get all tools as an array
$allTools = $client->tools()->all();

// Get only specific tools by name
$specificTools = $client->tools()->only('tool1', 'tool2');

// Exclude specific tools
$filteredTools = $client->tools()->except('tool3');

// Map over tools
$mappedTools = $client->tools()->map(function ($tool) {
    return $tool['name'];
});
```

### Call tools

The `callTool` method is used to execute specific tool. Here is the signature:

```php
public function callTool(string $toolName, mixed $params = []): mixed;
```

Example:

```php
$result = $client->callTool('create_entities', [
    'entities' => [
        [
            'name' => 'John Doe',
            'entityType' => 'PERSON',
            'observations' => ['Test observation 1', 'Test observation 2'],
        ]
    ],
]);
```

### Read Resources

The `readResource` method is used to retrieve the resource by the `uri`.

```php
public function readResource(string $uri): mixed;
```

Example:

```php
$result = $client->readResource("file:///project/src/main.rs");
```

## Advanced Usage

### Creating Custom Transporters

If you need to create a custom transporter, you can extend the `Transporter` interface and implement your own transport mechanism. Then register it in the `TransporterFactory`.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

-   [Nika Jorjoliani](https://github.com/nikajorjika)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
