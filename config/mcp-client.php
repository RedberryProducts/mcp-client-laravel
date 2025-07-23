<?php

return [
    'servers' => [
        'github' => [
            'type' => \Redberry\MCPClient\Enums\Transporters::HTTP,
            'base_url' => 'https://api.githubcopilot.com/mcp',
            'timeout' => 30,
            'token' => env('GITHUB_API_TOKEN', null),
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
        ],
    ],

];
