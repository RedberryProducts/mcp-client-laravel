<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Enums;

enum Transporters: string
{
    case HTTP = 'http';
    case STREAMABLE_HTTP = 'streamable_http';
    case STDIO = 'stdio';
}
