<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Core;

use Redberry\MCPClient\Core\Transporters\HttpTransporter;
use Redberry\MCPClient\Core\Transporters\StdioTransporter;
use Redberry\MCPClient\Core\Transporters\Transporter as ITransporter;
use Redberry\MCPClient\Enums\Transporters;

/**
 * Factory to instantiate the correct transporter based on config.
 */
class TransporterFactory
{
    /**
     * Create a transporter.
     *
     * @param  string|Transporters  $type  'http' or 'stdio' (or custom key)
     * @param  array  $config  Transporter-specific config
     */
    public static function make(
        string|Transporters $type,
        array $config = []
    ): ITransporter {
        if ($type instanceof Transporters) {
            $type = $type->value;
        }

        return match ($type) {
            'http' => new HttpTransporter($config),
            'stdio' => new StdioTransporter(),
            default => throw new \InvalidArgumentException("Unsupported transporter type: {$type}"),
        };
    }
}
