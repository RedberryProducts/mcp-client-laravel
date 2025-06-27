<?php

namespace Redberry\MCPClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Redberry\MCPClient\MCPClient
 */
class MCPClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Redberry\MCPClient\MCPClient::class;
    }
}
