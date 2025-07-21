<?php

namespace Redberry\MCPClient\Commands;

use Illuminate\Console\Command;
use Redberry\MCPClient\Facades\MCPClient;

class MCPClientCommand extends Command
{
    public $signature = 'mcp-client:fetch {server}';

    public $description = 'My command';

    public function handle(): int
    {
        $server = $this->argument('server');
        $tools = MCPClient::connect($server)->tools();


        $this->info("Available tools for server '{$server}':");

        $tools->map(function ($tool) {
            $this->line(" - {$tool['name']}  -  ({$tool['description']})");
        });
        return self::SUCCESS;
    }
}
