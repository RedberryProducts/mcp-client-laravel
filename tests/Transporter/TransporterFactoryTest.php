<?php

declare(strict_types=1);

use Redberry\MCPClient\Core\TransporterFactory;
use Redberry\MCPClient\Core\Transporters\HttpTransporter;
use Redberry\MCPClient\Core\Transporters\StdioTransporter;
use Redberry\MCPClient\Enums\Transporters as TransporterEnum;

it('creates an HTTP transporter via factory', function () {
    $transporter = TransporterFactory::make(TransporterEnum::HTTP, ['foo' => 'bar']);
    expect($transporter)->toBeInstanceOf(HttpTransporter::class);
});

it('creates a stdio transporter via factory', function () {
    $transporter = TransporterFactory::make(TransporterEnum::STDIO, ['foo' => 'bar']);
    expect($transporter)->toBeInstanceOf(StdioTransporter::class);
});

it('throws when creating an unsupported transporter type', function () {
    TransporterFactory::make('unsupported', []);
})->throws(InvalidArgumentException::class, 'Unsupported transporter type: unsupported');
