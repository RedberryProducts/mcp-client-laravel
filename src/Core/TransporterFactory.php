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
     * @param  array  $config  Transporter-specific config
     *
     * @return ITransporter
     */
    public static function make(
        array $config = []
    ): ITransporter {
        $type = $config['type'] instanceof Transporters ? $config['type']->value : $config['type'];

        return match ($type) {
            'http' => new HttpTransporter($config),
            'stdio' => new StdioTransporter,
            default => throw new \InvalidArgumentException("Unsupported transporter type: {$type}"),
        };
    }
}
