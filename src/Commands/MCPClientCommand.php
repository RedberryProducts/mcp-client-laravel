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
        //        $tools = MCPClient::connect($server)->tools();
        //
        //        $this->info("Available tools for server '{$server}':");
        //
        //        $tools->map(function ($tool) {
        //            $this->line(" - {$tool['name']}  -  ({$tool['description']})");
        //        });

        $params = [
            'owner' => 'RedberryProducts',         // required: GitHub org or username
            'repo' => 'laravel-mcp-client',                // required: repository name
            'title' => 'Add support for new JSON-RPC error codes', // required: issue title
            'body' => 'We should add handling for new JSON-RPC error codes introduced in v2.3 of the spec.', // optional
            'assignees' => ['nikajorjika'],   // optional
            'labels' => ['bug', 'json-rpc'],
        ];
        $tool = MCPClient::connect($server)->callTool('create_issue', $params);

        return self::SUCCESS;
    }
}
