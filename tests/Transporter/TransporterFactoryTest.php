<?php

declare(strict_types=1);

use Redberry\MCPClient\Core\TransporterFactory;
use Redberry\MCPClient\Core\Transporters\HttpTransporter;
use Redberry\MCPClient\Core\Transporters\StdioTransporter;
use Redberry\MCPClient\Enums\Transporters as TransporterEnum;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('mcp-client.servers', [
        'github' => [
            'type' => TransporterEnum::HTTP,
            'base_url' => 'https://example.com/mcp',
            'timeout' => 30,
            'token' => 'token_value',
        ],
        'npx_mcp_server' => [
            'type' => TransporterEnum::STDIO,
            'command' => [
                'npx',
                '-y',
                '@modelcontextprotocol/some-server',
            ],
            'timeout' => 30,
            'root_path' => '../path/to/mcp-server',
        ],
    ]);
});
describe('TransporterFactory', function () {


    it('creates an HTTP transporter via factory', function () {
        $transporter = TransporterFactory::make(config('mcp-client.servers.github'));
        expect($transporter)->toBeInstanceOf(HttpTransporter::class);
    });

    it('creates a stdio transporter via factory', function () {
        $transporter = TransporterFactory::make(config('mcp-client.servers.npx_mcp_server'));
        expect($transporter)->toBeInstanceOf(StdioTransporter::class);
    });

    it('throws when creating an unsupported transporter type', function () {
        TransporterFactory::make([
            'type' => 'unsupported',
            'base_url' => 'https://example.com/mcp',
            'timeout' => 30,
        ]);
    })->throws(InvalidArgumentException::class, 'Unsupported transporter type: unsupported');
});
