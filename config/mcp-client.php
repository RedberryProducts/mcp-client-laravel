<?php

use Redberry\MCPClient\Enums\Transporters;

return [
    'servers' => [
        'github' => [
            'type' => Transporters::HTTP,
            'base_url' => 'https://api.githubcopilot.com/mcp',
            'timeout' => 30,
            'read_timeout' => 60, // seconds - max gap between SSE chunks before aborting; resets on each chunk. Set to null to disable. (default: 60)
            'max_session_retries' => 1, // count - on HTTP 404 with an active session, clear session + re-initialize and retry up to this many times (default: 1, set 0 to disable)
            'token' => env('GITHUB_API_TOKEN', null),
            'id_type' => 'int', // 'string' or 'int' - controls JSON-RPC id type (default: 'int')
            'headers' => [
                // Add custom headers here - these will override default headers
            ],
        ],
        'npx_mcp_server' => [
            'type' => Transporters::STDIO,
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
