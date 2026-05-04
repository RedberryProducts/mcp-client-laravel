<?php

declare(strict_types=1);

namespace Redberry\MCPClient\Core\Http;

use JsonException;
use Psr\Http\Message\StreamInterface;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;

/**
 * Parses an MCP Streamable HTTP `text/event-stream` response.
 *
 * Each Server-Sent Event carries a complete JSON-RPC message. Any event
 * with a `result` field is treated as the final response; intermediate
 * events (notifications, progress) are forwarded to the optional
 * `$onEvent` callback so callers can observe partial output.
 */
class SseStreamParser
{
    private const READ_BYTES = 8192;

    private const DONE_SENTINEL = '[DONE]';

    /**
     * Read the stream to completion and return the final JSON-RPC `result`.
     *
     * @param  callable(array $event):void|null  $onEvent  Invoked for every decoded event message.
     *
     * @throws TransporterRequestException If the stream ends without a result, or any event carries a JSON-RPC error.
     * @throws JsonException
     */
    public static function parse(StreamInterface $stream, ?callable $onEvent = null): array
    {
        $buffer = '';
        $current = self::emptyEvent();
        $final = null;

        while (! $stream->eof()) {
            $chunk = $stream->read(self::READ_BYTES);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            self::drainLines($buffer, $current, $final, $onEvent);
        }

        // Force-flush a trailing line and/or event that lacks the SSE terminator.
        if ($buffer !== '' || $current['data'] !== '') {
            $buffer .= "\n\n";
            self::drainLines($buffer, $current, $final, $onEvent);
        }

        if ($final === null) {
            throw new TransporterRequestException('SSE stream ended without a JSON-RPC result.');
        }

        return $final;
    }

    /**
     * Drain complete (newline-terminated) lines from the buffer, updating the
     * pending event state and recording the latest result-bearing payload.
     *
     * @param  callable(array $event):void|null  $onEvent
     *
     * @throws TransporterRequestException
     * @throws JsonException
     */
    private static function drainLines(string &$buffer, array &$current, ?array &$final, ?callable $onEvent): void
    {
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = rtrim(substr($buffer, 0, $pos), "\r");
            $buffer = substr($buffer, $pos + 1);

            if ($line === '') {
                $payload = self::dispatch($current, $onEvent);
                if ($payload !== null) {
                    $final = $payload;
                }
                $current = self::emptyEvent();

                continue;
            }

            if (str_starts_with($line, ':')) {
                continue; // SSE comment
            }

            if (str_starts_with($line, 'event:')) {
                $current['event'] = trim(substr($line, 6));

                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $piece = ltrim(substr($line, 5), ' ');
                $current['data'] .= $current['data'] === '' ? $piece : "\n".$piece;
            }
        }
    }

    /**
     * Decode an event's `data` payload and surface it. Returns the result-bearing
     * payload (which becomes the new "final"), or null if this event has no result.
     *
     * @throws TransporterRequestException
     * @throws JsonException
     */
    private static function dispatch(array $event, ?callable $onEvent): ?array
    {
        $data = trim($event['data']);
        if ($data === '' || $data === self::DONE_SENTINEL) {
            return null;
        }

        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

        if (! \is_array($decoded)) {
            return null;
        }

        if (isset($decoded['error'])) {
            throw new TransporterRequestException(
                "JSON-RPC error: {$decoded['error']['message']}",
                (int) ($decoded['error']['code'] ?? 0),
            );
        }

        if ($onEvent !== null) {
            $onEvent($decoded);
        }

        if (\array_key_exists('result', $decoded)) {
            return \is_array($decoded['result']) ? $decoded['result'] : $decoded;
        }

        return null;
    }

    private static function emptyEvent(): array
    {
        return ['event' => null, 'data' => ''];
    }
}
