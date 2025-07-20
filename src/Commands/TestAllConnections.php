<?php

namespace Redberry\MCPClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Redberry\MCPClient\Facades\MCPClient;
use Throwable;

class TestAllConnections extends Command
{
    protected $signature = 'mcp-client:test-all';

    protected $description = 'Test connection to all configured servers';

    public function handle(): int
    {
        $servers = array_keys(Config::get('mcp-client.servers', []));

        foreach ($servers as $server) {
            try {
                MCPClient::connect($server);
                $this->info("✅ Successfully connected to [{$server}]");
            } catch (Throwable $e) {
                $this->error("❌ Failed to connect to [{$server}]: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
