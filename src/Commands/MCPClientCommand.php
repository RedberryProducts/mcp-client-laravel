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

        $tools = MCPClient::connect($this->argument('server'))->tools();

        $this->info('Available tools:');

        foreach ($tools as $tool) {
            $this->line("- {$tool['name']} ({$tool['description']})");
        }

        return self::SUCCESS;
    }
}
