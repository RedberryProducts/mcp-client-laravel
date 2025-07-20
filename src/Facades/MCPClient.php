<?php

namespace Redberry\MCPClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Redberry\MCPClient\Contracts\MCPClient
 *
 * @method static \Redberry\MCPClient\Contracts\MCPClient connect(string $server)
 * @method static \Redberry\MCPClient\Contracts\MCPClient tools()
 * @method static \Redberry\MCPClient\Contracts\MCPClient resources()
 */
class MCPClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Redberry\MCPClient\MCPClient::class;
    }
}
