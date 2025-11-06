<?php

return [
    'servers' => [
        'github' => [
            'type' => \Redberry\MCPClient\Enums\Transporters::HTTP,
            'base_url' => 'https://api.githubcopilot.com/mcp',
            'timeout' => 30,
            'token' => env('GITHUB_API_TOKEN', null),
            'id_type' => 'string', // 'string' or 'int' - controls JSON-RPC id type (default: 'int')
            'headers' => [
                // Add custom headers here - these will override default headers
            ],
        ],
        'npx_mcp_server' => [
            'type' => \Redberry\MCPClient\Enums\Transporters::STDIO,
            'command' => [
                'npx',
                '-y',
                '@modelcontextprotocol/server-memory',
            ],
            'timeout' => 30,
            'cwd' => base_path(),
            'env' => [],
            'startup_delay' => 50, // milliseconds - delay after process start (default: 50)
            'poll_interval' => 10, // milliseconds - polling interval for response (default: 10)
        ],
    ],
];
