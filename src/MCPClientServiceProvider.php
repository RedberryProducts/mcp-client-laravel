<?php

namespace Redberry\MCPClient;

use Redberry\MCPClient\Commands\FetchResources;
use Redberry\MCPClient\Commands\FetchTools;
use Redberry\MCPClient\Commands\TestAllConnections;
use Redberry\MCPClient\Commands\TestConnection;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MCPClientServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-mcp-client')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_mcp_client_table');

    }

    public function packageBooted(): void
    {
        $this->app->singleton(MCPClient::class, function ($app) {
            return new MCPClient(config('mcp-client.servers'));
        });

        $this->commands([
            FetchTools::class,
            FetchResources::class,
            TestConnection::class,
            TestAllConnections::class,
        ]);
    }
}
