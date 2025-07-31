<?php

namespace Redberry\MCPClient\Commands;

use Illuminate\Console\Command;

class MCPClientCommand extends Command
{
    public $signature = 'mcp-client:fetch {server}';

    public $description = 'My command';

    public function handle(): int
    {

        return self::SUCCESS;
    }
}
