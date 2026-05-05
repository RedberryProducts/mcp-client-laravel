# Changelog

All notable changes to `mcp-client-laravel` will be documented in this file.

## Unreleased

### Added

- HTTP transporter now implements MCP's Streamable HTTP transport (2025-03-26): every request advertises `Accept: application/json, text/event-stream` and content-negotiates with the server, parsing either a single JSON response or an SSE stream of JSON-RPC messages.
- New `SseStreamParser` helper for decoding `text/event-stream` responses.
- Optional `?callable $onEvent` parameter on `MCPClient::callTool()` and `MCPClient::readResource()` (and on the underlying `Transporter::request()` interface) for observing intermediate streamed events.
- `HttpTransporter` constructor now accepts an optional Guzzle `ClientInterface` for dependency injection.
- `read_timeout` config key on the HTTP transporter (default `60` seconds) — the maximum gap between SSE chunks before the parser aborts a wedged stream. The clock resets on every received chunk so long-running operations that stream progress events stay alive.
- HTTP transporter now recovers from session loss: when a request to an active session returns HTTP 404 (the MCP 2025-03-26 signal for an expired/unknown session), the transporter clears the session, re-runs the `initialize` handshake, and retries the original request once. Configurable via the new `max_session_retries` key (default `1`, set `0` to disable).

### Changed

- `composer.json` now explicitly requires `guzzlehttp/guzzle` and `psr/http-message`, which the HTTP transporter has always relied on transitively.
- Both transporters now report `protocolVersion: 2025-03-26` and source `clientInfo.version` from the installed composer package version (was hardcoded `0.1.0` on STDIO). Shared in a new `Redberry\MCPClient\Core\Mcp` value class.
- HTTP transporter tests now exercise the real `initialize` + `notifications/initialized` handshake via constructor injection instead of poking private state with reflection. No production behavior change.

### Fixed

- Spec-correct `initialize` handshake on the HTTP transporter — payload now includes `protocolVersion`, `capabilities`, and `clientInfo`, and the required `notifications/initialized` notification is sent before the first user request. Strict-spec MCP servers (the official TS reference, Anthropic's servers) previously rejected the bare initialize.
- STDIO transporter sent the post-initialize notification with method `initialized`; renamed to spec-correct `notifications/initialized`.
- `SseStreamParser` no longer raises a PHP warning when a server returns a JSON-RPC error without a `message` field; falls back to `"Unknown JSON-RPC error"` while preserving the error `code`.
