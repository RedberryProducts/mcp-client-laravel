<?php

namespace Redberry\MCPClient;

use Redberry\MCPClient\Commands\MCPClientCommand;
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
            ->hasMigration('create_laravel_mcp_client_table')
            ->hasCommand(MCPClientCommand::class);
    }
}
