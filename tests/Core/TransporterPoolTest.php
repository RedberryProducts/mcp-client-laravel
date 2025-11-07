<?php

declare(strict_types=1);

use Redberry\MCPClient\Core\TransporterFactory;
use Redberry\MCPClient\Core\TransporterPool;
use Redberry\MCPClient\Core\Transporters\Transporter;

describe('TransporterPool', function () {

    it('creates transporter on first call', function () {
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter = Mockery::mock(Transporter::class);

        $config = ['type' => 'http', 'base_url' => 'https://example.com'];

        $mockFactory->shouldReceive('make')
            ->once()
            ->with($config)
            ->andReturn($mockTransporter);

        $pool = new TransporterPool($mockFactory);
        $transporter = $pool->get('github', $config);

        expect($transporter)->toBe($mockTransporter);
    });

    it('returns same transporter on subsequent calls', function () {
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter = Mockery::mock(Transporter::class);

        $config = ['type' => 'http', 'base_url' => 'https://example.com'];

        // Factory should only be called once
        $mockFactory->shouldReceive('make')
            ->once()
            ->with($config)
            ->andReturn($mockTransporter);

        $pool = new TransporterPool($mockFactory);

        // First call
        $transporter1 = $pool->get('github', $config);

        // Second call - should return same instance
        $transporter2 = $pool->get('github', $config);

        expect($transporter1)->toBe($mockTransporter)
            ->and($transporter2)->toBe($mockTransporter)
            ->and($transporter1)->toBe($transporter2);
    });

    it('forget removes transporter from pool', function () {
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter1 = Mockery::mock(Transporter::class);
        $mockTransporter2 = Mockery::mock(Transporter::class);

        $config = ['type' => 'http', 'base_url' => 'https://example.com'];

        // Factory should be called twice (before and after forget)
        $mockFactory->shouldReceive('make')
            ->twice()
            ->with($config)
            ->andReturn($mockTransporter1, $mockTransporter2);

        $pool = new TransporterPool($mockFactory);

        // First call
        $transporter1 = $pool->get('github', $config);
        expect($transporter1)->toBe($mockTransporter1);

        // Forget the transporter
        $pool->forget('github');

        // Next call should create a new transporter
        $transporter2 = $pool->get('github', $config);
        expect($transporter2)->toBe($mockTransporter2);
    });

    it('clear removes all transporters', function () {
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter1 = Mockery::mock(Transporter::class);
        $mockTransporter2 = Mockery::mock(Transporter::class);

        $config1 = ['type' => 'http', 'base_url' => 'https://github.com'];
        $config2 = ['type' => 'http', 'base_url' => 'https://gitlab.com'];

        $mockFactory->shouldReceive('make')
            ->with($config1)
            ->andReturn($mockTransporter1);

        $mockFactory->shouldReceive('make')
            ->with($config2)
            ->andReturn($mockTransporter2);

        $pool = new TransporterPool($mockFactory);

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
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter1 = Mockery::mock(Transporter::class);
        $mockTransporter2 = Mockery::mock(Transporter::class);
        $mockTransporter3 = Mockery::mock(Transporter::class);

        $config1 = ['type' => 'http', 'base_url' => 'https://github.com'];
        $config2 = ['type' => 'http', 'base_url' => 'https://gitlab.com'];
        $config3 = ['type' => 'stdio', 'command' => ['npx', 'test']];

        $mockFactory->shouldReceive('make')
            ->with($config1)
            ->andReturn($mockTransporter1);

        $mockFactory->shouldReceive('make')
            ->with($config2)
            ->andReturn($mockTransporter2);

        $mockFactory->shouldReceive('make')
            ->with($config3)
            ->andReturn($mockTransporter3);

        $pool = new TransporterPool($mockFactory);

        expect($pool->getActiveServers())->toBeEmpty();

        $pool->get('github', $config1);
        expect($pool->getActiveServers())->toBe(['github']);

        $pool->get('gitlab', $config2);
        expect($pool->getActiveServers())->toHaveCount(2)
            ->toContain('github')
            ->toContain('gitlab');

        $pool->get('npx_server', $config3);
        expect($pool->getActiveServers())->toHaveCount(3)
            ->toContain('github')
            ->toContain('gitlab')
            ->toContain('npx_server');
    });

    it('handles multiple different servers correctly', function () {
        $mockFactory = Mockery::mock(TransporterFactory::class);
        $mockTransporter1 = Mockery::mock(Transporter::class);
        $mockTransporter2 = Mockery::mock(Transporter::class);

        $config1 = ['type' => 'http', 'base_url' => 'https://github.com'];
        $config2 = ['type' => 'stdio', 'command' => ['npx', 'test']];

        $mockFactory->shouldReceive('make')
            ->once()
            ->with($config1)
            ->andReturn($mockTransporter1);

        $mockFactory->shouldReceive('make')
            ->once()
            ->with($config2)
            ->andReturn($mockTransporter2);

        $pool = new TransporterPool($mockFactory);

        // Get different servers
        $t1 = $pool->get('github', $config1);
        $t2 = $pool->get('npx_server', $config2);

        // Should be different instances
        expect($t1)->toBe($mockTransporter1)
            ->and($t2)->toBe($mockTransporter2)
            ->and($t1)->not->toBe($t2);

        // Getting same servers again should return same instances
        $t1Again = $pool->get('github', $config1);
        $t2Again = $pool->get('npx_server', $config2);

        expect($t1Again)->toBe($mockTransporter1)
            ->and($t2Again)->toBe($mockTransporter2);
    });
});
