<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Contracts;

use Redberry\MCPClient\Collection;

interface MCPClient
{
    public function connect(string $serverName): self;

    public function tools(bool $fresh = false): Collection;

    public function resources(bool $fresh = false): Collection;

    public function callTool(string $toolName, mixed $params = [], ?callable $onEvent = null): array;

    public function readResource(string $uri, ?callable $onEvent = null): array;

    public function enableCache(): static;

    public function disableCache(): static;

    public function clearCache(): static;
}
