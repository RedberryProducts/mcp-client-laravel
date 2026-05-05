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
            'request_timeout' => 30, // seconds - per-call wait for a JSON-RPC response (default: 30)
            'process_timeout' => null, // seconds - Symfony Process kill timer; null disables it (default: null). Set only if you need a hard upper bound on the entire subprocess lifetime.
            'cwd' => base_path(),
            // 'env' => ['NODE_OPTIONS' => '--max-old-space-size=512'], // merged on top of the parent env (PATH, HOME, etc. are inherited by default)
            // 'inherit_env' => false, // sealed env: forward only the keys listed in `env`. Default: true.
            'startup_delay' => 100, // milliseconds - delay after process start before sending the initialize handshake (default: 100). Increase for slow cold-starts (e.g. first-run `npx -y` downloads).
            'poll_interval' => 20, // milliseconds - polling interval for response (default: 20)
        ],
    ],
];
