<?php

declare(strict_types=1);

use Redberry\MCPClient\Core\TransporterPool;
use Redberry\MCPClient\Core\Transporters\HttpTransporter;
use Redberry\MCPClient\Core\Transporters\StdioTransporter;

describe('TransporterPool', function () {

    it('creates transporter on first call', function () {
        $config = ['type' => 'http', 'base_url' => 'https://example.com', 'timeout' => 30];

        $pool = new TransporterPool();
        $transporter = $pool->get('github', $config);

        expect($transporter)->toBeInstanceOf(HttpTransporter::class);
    });

    it('returns same transporter on subsequent calls', function () {
        $config = ['type' => 'http', 'base_url' => 'https://example.com', 'timeout' => 30];

        $pool = new TransporterPool();

        // First call
        $transporter1 = $pool->get('github', $config);

        // Second call - should return same instance
        $transporter2 = $pool->get('github', $config);

        expect($transporter1)->toBeInstanceOf(HttpTransporter::class)
            ->and($transporter2)->toBeInstanceOf(HttpTransporter::class)
            ->and($transporter1)->toBe($transporter2);
    });

    it('forget removes transporter from pool', function () {
        $config = ['type' => 'http', 'base_url' => 'https://example.com', 'timeout' => 30];

        $pool = new TransporterPool();

        // First call
        $transporter1 = $pool->get('github', $config);
        expect($transporter1)->toBeInstanceOf(HttpTransporter::class);

        // Forget the transporter
        $pool->forget('github');

        // Next call should create a new transporter
        $transporter2 = $pool->get('github', $config);
        expect($transporter2)->toBeInstanceOf(HttpTransporter::class)
            ->and($transporter1)->not->toBe($transporter2);
    });

    it('clear removes all transporters', function () {
        $config1 = ['type' => 'http', 'base_url' => 'https://github.com', 'timeout' => 30];
        $config2 = ['type' => 'http', 'base_url' => 'https://gitlab.com', 'timeout' => 30];

        $pool = new TransporterPool();

        // Add two transporters
        $pool->get('github', $config1);
        $pool->get('gitlab', $config2);

        expect($pool->getActiveServers())->toHaveCount(2)
            ->toContain('github')
            ->toContain('gitlab');

        // Clear all
        $pool->clear();

        expect($pool->getActiveServers())->toBeEmpty();
    });

    it('getActiveServers returns list of server names', function () {
        $config1 = ['type' => 'http', 'base_url' => 'https://github.com', 'timeout' => 30];
        $config2 = ['type' => 'http', 'base_url' => 'https://gitlab.com', 'timeout' => 30];

        $pool = new TransporterPool();

        expect($pool->getActiveServers())->toBeEmpty();

        $pool->get('github', $config1);
        expect($pool->getActiveServers())->toBe(['github']);

        $pool->get('gitlab', $config2);
        expect($pool->getActiveServers())->toHaveCount(2)
            ->toContain('github')
            ->toContain('gitlab');
    });

    it('handles multiple different servers correctly', function () {
        $config1 = ['type' => 'http', 'base_url' => 'https://github.com', 'timeout' => 30];
        $config2 = ['type' => 'stdio', 'command' => ['echo', 'test'], 'timeout' => 30];

        $pool = new TransporterPool();

        // Get different servers
        $t1 = $pool->get('github', $config1);
        $t2 = $pool->get('npx_server', $config2);

        // Should be different instances and different types
        expect($t1)->toBeInstanceOf(HttpTransporter::class)
            ->and($t2)->toBeInstanceOf(StdioTransporter::class)
            ->and($t1)->not->toBe($t2);

        // Getting same servers again should return same instances
        $t1Again = $pool->get('github', $config1);
        $t2Again = $pool->get('npx_server', $config2);

        expect($t1Again)->toBe($t1)
            ->and($t2Again)->toBe($t2);
    });
});
