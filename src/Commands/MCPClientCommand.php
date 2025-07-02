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
        $tools = MCPClient::connect('github')->tools()->only(['add_issue_comment', 'update_pull_request']);

        return self::SUCCESS;
    }
}
