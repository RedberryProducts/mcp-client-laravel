<?php

namespace Redberry\MCPClient\Commands;

use Illuminate\Console\Command;
use Redberry\MCPClient\Facades\MCPClient;
use Throwable;

class FetchTools extends Command
{
    protected $signature = 'mcp-client:fetch-tools {server}';

    protected $description = 'Connect to given server, fetch and list tools';

    public function handle(): int
    {
        $server = $this->argument('server');

        try {
            $tools = MCPClient::connect($server)->tools()->toArray();
        } catch (Throwable $e) {
            $this->error('Error: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (empty($tools)) {
            $this->info('No tools found.');

            return self::SUCCESS;
        }

        $first = reset($tools);
        if (is_array($first)) {
            $this->table(array_keys($first), $tools);
        } else {
            $this->line(json_encode($tools));
        }

        return self::SUCCESS;
    }
}
