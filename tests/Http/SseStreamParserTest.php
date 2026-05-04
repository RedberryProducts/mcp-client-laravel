<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Redberry\MCPClient\Core\Exceptions\TransporterRequestException;
use Redberry\MCPClient\Core\Http\SseStreamParser;
use Redberry\MCPClient\Tests\Http\Fixtures\WedgedStream;

describe('SseStreamParser', function () {
    test('returns the result of a single event', function () {
        $sse = "event: jsonrpc.message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"foo\":\"bar\"}}\n\n";

        $result = SseStreamParser::parse(Utils::streamFor($sse));

        expect($result)->toEqual(['foo' => 'bar']);
    });

    test('returns the last result-bearing event when several are sent', function () {
        $sse = <<<'SSE'
event: jsonrpc.message
data: {"jsonrpc":"2.0","id":1,"result":{"step":"first"}}

event: jsonrpc.message
data: {"jsonrpc":"2.0","id":1,"result":{"step":"second"}}

data: [DONE]

SSE;

        $result = SseStreamParser::parse(Utils::streamFor($sse));

        expect($result)->toEqual(['step' => 'second']);
    });

    test('invokes $onEvent for every decoded event', function () {
        $sse = <<<'SSE'
data: {"jsonrpc":"2.0","method":"progress","params":{"pct":25}}

data: {"jsonrpc":"2.0","method":"progress","params":{"pct":75}}

data: {"jsonrpc":"2.0","id":1,"result":{"done":true}}

SSE;

        $seen = [];
        $result = SseStreamParser::parse(
            Utils::streamFor($sse),
            function (array $event) use (&$seen) {
                $seen[] = $event;
            }
        );

        expect($result)->toEqual(['done' => true])
            ->and($seen)->toHaveCount(3)
            ->and($seen[0]['params']['pct'])->toBe(25)
            ->and($seen[1]['params']['pct'])->toBe(75)
            ->and($seen[2]['result']['done'])->toBeTrue();
    });

    test('joins multi-line `data:` fields with newlines', function () {
        $sse = "data: {\"jsonrpc\":\"2.0\",\"id\":1,\ndata: \"result\":{\"text\":\"hi\"}}\n\n";

        $result = SseStreamParser::parse(Utils::streamFor($sse));

        expect($result)->toEqual(['text' => 'hi']);
    });

    test('skips comment lines starting with colon', function () {
        $sse = <<<'SSE'
: this is a heartbeat

: another comment
data: {"jsonrpc":"2.0","id":1,"result":{"ok":true}}

SSE;

        $result = SseStreamParser::parse(Utils::streamFor($sse));

        expect($result)->toEqual(['ok' => true]);
    });

    test('treats [DONE] sentinel events as terminators (no result)', function () {
        $sse = <<<'SSE'
data: {"jsonrpc":"2.0","id":1,"result":{"value":42}}

data: [DONE]

SSE;

        $result = SseStreamParser::parse(Utils::streamFor($sse));

        expect($result)->toEqual(['value' => 42]);
    });

    test('flushes a trailing event that lacks a terminating blank line', function () {
        $sse = 'data: {"jsonrpc":"2.0","id":1,"result":{"trailing":true}}';

        $result = SseStreamParser::parse(Utils::streamFor($sse));

        expect($result)->toEqual(['trailing' => true]);
    });

    test('throws when an event carries a JSON-RPC error', function () {
        $sse = "data: {\"jsonrpc\":\"2.0\",\"id\":1,\"error\":{\"code\":-32601,\"message\":\"Method not found\"}}\n\n";

        expect(fn () => SseStreamParser::parse(Utils::streamFor($sse)))
            ->toThrow(TransporterRequestException::class, 'JSON-RPC error: Method not found');
    });

    test('falls back to a default message when the JSON-RPC error omits message', function () {
        $sse = "data: {\"jsonrpc\":\"2.0\",\"id\":1,\"error\":{\"code\":-32601}}\n\n";

        try {
            SseStreamParser::parse(Utils::streamFor($sse));
            expect()->fail('Expected TransporterRequestException was not thrown.');
        } catch (TransporterRequestException $e) {
            expect($e->getMessage())->toBe('JSON-RPC error: Unknown JSON-RPC error')
                ->and($e->getCode())->toBe(-32601);
        }
    });

    test('throws when the stream ends without any result', function () {
        $sse = <<<'SSE'
data: {"jsonrpc":"2.0","method":"progress","params":{"pct":50}}

data: [DONE]

SSE;

        expect(fn () => SseStreamParser::parse(Utils::streamFor($sse)))
            ->toThrow(TransporterRequestException::class, 'SSE stream ended without a JSON-RPC result.');
    });

    test('throws JsonException on malformed event payload', function () {
        $sse = "data: not-valid-json\n\n";

        expect(fn () => SseStreamParser::parse(Utils::streamFor($sse)))
            ->toThrow(JsonException::class);
    });

    test('handles an empty stream by throwing', function () {
        expect(fn () => SseStreamParser::parse(Utils::streamFor('')))
            ->toThrow(TransporterRequestException::class, 'SSE stream ended without a JSON-RPC result.');
    });

    test('throws TransporterRequestException when no chunks arrive within the read timeout', function () {
        $start = microtime(true);

        try {
            SseStreamParser::parse(new WedgedStream, null, 0.05);
            expect()->fail('Expected a SSE read timeout to fire.');
        } catch (TransporterRequestException $e) {
            $elapsed = microtime(true) - $start;

            expect($e->getMessage())->toContain('SSE read timed out')
                ->and($elapsed)->toBeLessThan(1.0);
        }
    });

    test('does not time out when chunks arrive within the read timeout', function () {
        $sse = "data: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"ok\":true}}\n\n";

        $result = SseStreamParser::parse(Utils::streamFor($sse), null, 0.05);

        expect($result)->toEqual(['ok' => true]);
    });
});
