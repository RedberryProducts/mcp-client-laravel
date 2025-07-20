<?php

namespace Redberry\MCPClient\Commands;

use Illuminate\Console\Command;
use Redberry\MCPClient\Facades\MCPClient;
use Throwable;

class FetchResources extends Command
{
    protected $signature = 'mcp-client:fetch-resources {server}';

    protected $description = 'Connect to given server, fetch and list resources';

    public function handle(): int
    {
        $server = $this->argument('server');

        try {
            $resources = MCPClient::connect($server)->resources()->toArray();
        } catch (Throwable $e) {
            $this->error('Error: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (empty($resources)) {
            $this->info('No resources found.');

            return self::SUCCESS;
        }

        $first = reset($resources);
        if (is_array($first)) {
            $this->table(array_keys($first), $resources);
        } else {
            $this->line(json_encode($resources));
        }

        return self::SUCCESS;
    }
}
