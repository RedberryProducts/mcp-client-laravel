# Transporter Rules

When adding a new transport (e.g. WebSocket) or modifying `HttpTransporter` / `StdioTransporter`, follow these rules.

## Contract

1. Every transport implements `Redberry\MCPClient\Core\Transporters\Transporter` and only that interface — one method, `request(string $action, array $params = [], ?callable $onEvent = null): array`.
2. The return value is the JSON-RPC `result`, decoded as an associative array. If the spec `result` isn't an array (rare — null/scalar), return the full envelope so the `: array` return contract holds. `HttpTransporter::parseResponse()` already does this; mirror it.
3. Throw `Redberry\MCPClient\Core\Exceptions\TransporterRequestException` for any transport-layer failure: network error, JSON-RPC error response, malformed payload, timeout, session loss. Carry the spec error code through as the exception code where possible.
4. Throw `Redberry\MCPClient\Core\Exceptions\ServerConfigurationException` from the constructor for invalid config shapes (e.g. missing required key, wrong type).
5. The constructor takes `array $config` as its first parameter. Any external dependency (HTTP client, process factory) should be the second parameter, optional, with a sensible default — this is the seam tests use to inject mocks.

## Initialization

6. Run the MCP `initialize` handshake exactly once per instance, lazily on the first user request. Don't run it in the constructor.
7. The initialize payload must include `protocolVersion`, `capabilities` (empty object `(object)[]` is fine), and `clientInfo` (`name`, `version`). After a successful `initialize`, send a `notifications/initialized` notification (no `id` field — it's not a request). See `mcp-spec.md` for the exact shape.
8. Reference `Redberry\MCPClient\Core\Mcp::PROTOCOL_VERSION` for the protocol version. Don't redeclare a per-class constant.
9. `clientInfo` (both `name` and `version`) must come from `Redberry\MCPClient\Core\Mcp::clientInfo()`, which already handles version resolution and the missing-package fallback.

## Request IDs

10. Use a per-instance incrementing counter for JSON-RPC `id` (see `StdioTransporter::$requestId`). Don't use `random_int` — birthday collisions become non-trivial over a long-lived session. The `id_type` config flag (`'int'` vs `'string'`) only controls how the counter is cast.

## State and reuse

11. A transporter instance may hold session state (HTTP session id, STDIO process handle) for the duration of its life. Don't share state across instances via static properties.
12. On detected session loss (HTTP 404 with the spec session-not-found code, or a JSON-RPC error indicating the same), clear the session, re-initialize once, and retry the original request once. Surface as `TransporterRequestException` if the retry also fails. (See ROADMAP P3 — the HTTP transporter doesn't do this yet; new transports should plan for it.)

## The `$onEvent` callback

13. If the transport streams (server can emit multiple JSON-RPC messages between request and final result), invoke `$onEvent` for **every** decoded message — notifications, progress, and the final result-bearing one — in arrival order.
14. If the transport doesn't stream (single request/response), `$onEvent` is a no-op. Document this explicitly in the class-level docblock so callers know the callback is conditional on the active transport.
15. `$onEvent` must be safe to call when null. Don't call without a null guard.

## Registration

16. Add the new transport to the `Redberry\MCPClient\Enums\Transporters` enum and to `TransporterFactory::make()`'s `match` arm. The factory is the only place that maps config `type` → concrete class — don't add resolution logic anywhere else.
17. If the new transport requires new config keys, document them in `config/mcp-client.php` with inline comments describing default and unit (ms / seconds).

## Don't

- Don't read `config()` from inside a transporter — accept everything via `array $config` in the constructor.
- Don't throw raw `\Exception` or `\RuntimeException` from request paths — wrap in `TransporterRequestException` so callers have one exception type to catch.
- Don't introduce a second IO seam (e.g. raw `fopen` / `curl_exec`). Reuse Guzzle for HTTP-shaped transports and Symfony Process for subprocess-shaped ones.
- Don't add cross-request global state (static properties, singletons).
- Don't break the existing `Transporter` interface signature without a coordinated change to all implementations.
