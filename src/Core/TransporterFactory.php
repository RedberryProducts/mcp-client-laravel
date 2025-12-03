<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Core;

use GuzzleHttp\Exception\GuzzleException;
use Redberry\MCPClient\Core\Transporters\HttpTransporter;
use Redberry\MCPClient\Core\Transporters\StdioTransporter;
use Redberry\MCPClient\Core\Transporters\StreamableHttpTransporter;
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
     * @throws GuzzleException
     */
    public static function make(
        array $config = []
    ): ITransporter {
        $type = $config['type'] instanceof Transporters ? $config['type']->value : $config['type'];

        return match ($type) {
            'http' => new HttpTransporter($config),
            'streamable_http' => new StreamableHttpTransporter($config),
            'stdio' => new StdioTransporter($config),
            default => throw new \InvalidArgumentException("Unsupported transporter type: {$type}"),
        };
    }
}
