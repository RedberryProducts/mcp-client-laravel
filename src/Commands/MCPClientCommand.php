<?php

namespace Redberry\MCPClient\Commands;

use Illuminate\Console\Command;

class MCPClientCommand extends Command
{
    public $signature = 'laravel-mcp-client';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
