<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Redberry\MCPClient\Contracts\MCPClient
 *
 * @method static \Redberry\MCPClient\Contracts\MCPClient connect(string $serverName)
 * @method static \Redberry\MCPClient\Collection tools(bool $fresh = false)
 * @method static \Redberry\MCPClient\Collection resources(bool $fresh = false)
 * @method static array callTool(string $toolName, mixed $params = [], ?callable $onEvent = null)
 * @method static array readResource(string $uri, ?callable $onEvent = null)
 * @method static \Redberry\MCPClient\Contracts\MCPClient enableCache()
 * @method static \Redberry\MCPClient\Contracts\MCPClient disableCache()
 * @method static \Redberry\MCPClient\Contracts\MCPClient clearCache()
 */
class MCPClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Redberry\MCPClient\MCPClient::class;
    }
}
