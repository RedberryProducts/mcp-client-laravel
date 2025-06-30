<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Enums;

enum Transporters: string
{
    case HTTP = 'http';
    case STDIO = 'stdio';
}
