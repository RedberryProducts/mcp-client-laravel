# Changelog

All notable changes to `mcp-client-laravel` will be documented in this file.

## Unreleased

### Added

- HTTP transporter now implements MCP's Streamable HTTP transport (2025-03-26): every request advertises `Accept: application/json, text/event-stream` and content-negotiates with the server, parsing either a single JSON response or an SSE stream of JSON-RPC messages.
- New `SseStreamParser` helper for decoding `text/event-stream` responses.
- Optional `?callable $onEvent` parameter on `MCPClient::callTool()` and `MCPClient::readResource()` (and on the underlying `Transporter::request()` interface) for observing intermediate streamed events.
- `HttpTransporter` constructor now accepts an optional Guzzle `ClientInterface` for dependency injection.

### Changed

- `composer.json` now explicitly requires `guzzlehttp/guzzle` and `psr/http-message`, which the HTTP transporter has always relied on transitively.
