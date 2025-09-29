<?php

namespace Redberry\MCPClient;

use Redberry\MCPClient\Commands\MCPClientCommand;
use Redberry\MCPClient\Core\TransporterFactory;
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
            ->name('mcp-client-laravel')
            ->hasConfigFile('mcp-client')
            ->hasCommand(MCPClientCommand::class);

    }

    public function packageBooted(): void
    {
        $this->app->bind(MCPClient::class, function ($app) {
            $servers = $app['config']->get('mcp-client.servers', []);

            return new MCPClient($servers, $app->make(TransporterFactory::class));
        });
    }
}
