<?php

namespace Redberry\MCPClient\Commands;

use Illuminate\Console\Command;
use Redberry\MCPClient\Facades\MCPClient;
use Throwable;

class TestConnection extends Command
{
    protected $signature = 'mcp-client:test-connection {server}';

    protected $description = 'Test connection to the given server';

    public function handle(): int
    {
        $server = $this->argument('server');

        try {
            MCPClient::connect($server);
            $this->info("✅ Successfully connected to [{$server}]");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('❌ Failed to connect: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
