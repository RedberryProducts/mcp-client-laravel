<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Tests\Http\Fixtures;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A PSR-7 stream that never EOFs and never produces data — simulates a wedged
 * SSE connection where the server keeps the socket open without sending bytes.
 * Only `read()` and `eof()` are exercised by `SseStreamParser`; the remaining
 * methods throw to make any unintended use during tests obvious.
 */
final class WedgedStream implements StreamInterface
{
    public function read(int $length): string
    {
        return '';
    }

    public function eof(): bool
    {
        return false;
    }

    public function close(): void {}

    public function detach() {}

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        throw new RuntimeException('not implemented');
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('not seekable');
    }

    public function rewind(): void
    {
        throw new RuntimeException('not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getContents(): string
    {
        throw new RuntimeException('not implemented');
    }

    public function getMetadata(?string $key = null)
    {
        return null;
    }

    public function __toString(): string
    {
        return '';
    }
}
