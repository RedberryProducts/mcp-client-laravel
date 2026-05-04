# MCP Spec Rules

This package targets the Model Context Protocol spec. Treat the spec as authoritative; this file pins which version we're on, the message shapes we use, and what is intentionally **not** in scope.

## Pinned spec version

- **HTTP transport:** [`2025-03-26` Streamable HTTP](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http). This is the only HTTP shape we implement.
- **STDIO transport:** Currently uses `protocolVersion: '2024-11-05'` in `StdioTransporter::PROTOCOL_VERSION`. ROADMAP P0 promotes `PROTOCOL_VERSION` to a single shared location and aligns both transporters to `2025-03-26`. Do not introduce a third version.

When you reach for the spec, link the section anchor in your PR description so reviewers can verify against the same revision you used.

## JSON-RPC envelope

All messages are JSON-RPC 2.0:

```json
{ "jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": { ... } }
```

Rules:

- `jsonrpc` is always the literal string `"2.0"`.
- `id` is required for requests, **omitted** for notifications. See `StdioTransporter::sendInitializeRequests()` for an example: `initialize` has an `id`, the follow-up `notifications/initialized` does not.
- Empty `params` must serialize as `{}` (object), not `[]` (array). `HttpTransporter::preparePayload()` handles this with `params === [] ? (object) [] : $params`.
- Errors come back as `{"error": {"code": int, "message": string, "data"?: any}}`. `message` is **optional in practice** even though the spec says SHOULD — defensive code reads it as `$decoded['error']['message'] ?? 'Unknown JSON-RPC error'` (see ROADMAP P1 for the SSE parser's gap).

## Initialize handshake

Required for **both** transports before any user-issued request. Two messages, in order:

```json
// 1. Request
{
  "jsonrpc": "2.0",
  "id": "init",
  "method": "initialize",
  "params": {
    "protocolVersion": "2025-03-26",
    "capabilities": {},
    "clientInfo": { "name": "mcp-client-laravel", "version": "<from composer>" }
  }
}

// 2. Notification (no id field)
{
  "jsonrpc": "2.0",
  "method": "notifications/initialized",
  "params": {}
}
```

- Strict-spec servers (Anthropic's reference servers, the official TS reference) reject an `initialize` payload missing any of `protocolVersion`, `capabilities`, `clientInfo`.
- The HTTP transport additionally captures `mcp-session-id` from the `initialize` response headers and replays it on every subsequent request as a header.
- `clientInfo.version` must come from `\Composer\InstalledVersions::getPrettyVersion('redberry/mcp-client-laravel')`, not a hardcoded string.

## Streamable HTTP specifics

- Every request must advertise `Accept: application/json, text/event-stream`. The server picks per call.
- Response Content-Type drives parsing:
  - `application/json` → single JSON-RPC message; decode and return `result`.
  - `text/event-stream` → hand the body to `SseStreamParser::parse()` and let it drive `$onEvent` until the result-bearing event arrives.
- Session loss surfaces as either HTTP 404 or a JSON-RPC error indicating "session not found". The client should clear session state, re-initialize, and retry once (ROADMAP P3 tracks the implementation).

## SSE event format

Server-Sent Events as MCP uses them:

```
event: message
data: {"jsonrpc":"2.0","id":1,"result":{...}}

```

- Events are separated by a blank line (`\n\n`).
- Multi-line `data:` values are concatenated with `\n` before JSON-decoding.
- Lines starting with `:` are comments — ignore them.
- A bare `[DONE]` sentinel ends the stream without a result; treat as no-op (we don't synthesize a result from it).
- Every decoded JSON-RPC message — notifications, progress (`notifications/progress`), log entries, and the final result — is surfaced via `$onEvent`. Only the final result-bearing message becomes the return value.

## Error code conventions

Pass JSON-RPC error codes through to the `TransporterRequestException` code unchanged. Common ones to handle without inventing new layers:

- `-32700` Parse error
- `-32600` Invalid request
- `-32601` Method not found
- `-32602` Invalid params
- `-32603` Internal error
- Server-defined codes in the `-32000` to `-32099` range — treat opaquely.

## Out of scope

Don't start work on these without explicit confirmation. They're documented as out-of-scope in [ROADMAP.md:98–102](../../ROADMAP.md):

- **Long-lived `GET /` SSE channel** for unsolicited server→client notifications. We are request/response only.
- **`Last-Event-ID` resumability** on Streamable HTTP. We don't checkpoint or resume.
- **Sampling / completions / elicitation flows** beyond `tools/list`, `tools/call`, `resources/list`, `resources/read`. The current `MCPClient` API is intentionally narrow.
- **Server capabilities advertising** beyond what `initialize` returns — we don't currently inspect or branch on the server's capabilities object.

If a feature in this list becomes necessary, raise it as a new ROADMAP item before writing code.

## Reference links

- Spec index: <https://modelcontextprotocol.io/specification/2025-03-26>
- JSON-RPC 2.0: <https://www.jsonrpc.org/specification>
- Streamable HTTP transport: <https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http>
- Lifecycle / `initialize`: <https://modelcontextprotocol.io/specification/2025-03-26/basic/lifecycle>
