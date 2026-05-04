<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Core;

use Composer\InstalledVersions;
use OutOfBoundsException;

final class Mcp
{
    public const PROTOCOL_VERSION = '2025-03-26';

    public const CLIENT_NAME = 'mcp-client-laravel';

    public const PACKAGE_NAME = 'redberry/mcp-client-laravel';

    public const FALLBACK_VERSION = '0.0.0-dev';

    /**
     * @return array{name: string, version: string}
     */
    public static function clientInfo(): array
    {
        return [
            'name' => self::CLIENT_NAME,
            'version' => self::resolveClientVersion(),
        ];
    }

    private static function resolveClientVersion(): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);
        } catch (OutOfBoundsException) {
            return self::FALLBACK_VERSION;
        }

        return $version ?? self::FALLBACK_VERSION;
    }
}
