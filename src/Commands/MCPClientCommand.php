<?php

namespace Redberry\MCPClient\Commands;

use Illuminate\Console\Command;
use Redberry\MCPClient\Core\TransporterFactory;

class MCPClientCommand extends Command
{
    public $signature = 'mcp-client:fetch {server}';

    public $description = 'My command';

    public function handle(): int
    {
        $config = config('mcp-client.servers');
        $server = $this->argument('server');
        $selectedServer = $config[$server] ?? null;

        if (!$selectedServer) {
            $this->error("Server configuration for '{$server}' not found.");
            return self::FAILURE;
        }

        $transport = TransporterFactory::make($selectedServer['type'], $selectedServer);

        $response = $transport->request('tools/list');

        $this->info('Response from MCP server:');
        $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Command executed successfully.');
        return self::SUCCESS;
    }
}
