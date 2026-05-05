# Roadmap

Follow-up work tracked after PR #33 (Streamable HTTP transport). Tasks are ordered by priority — please complete them in order and ship each as its own PR for clean review unless noted otherwise.

All file paths below are relative to the package root.

---

## ~~P0 — Spec-correct `initialize` handshake for `HttpTransporter`~~ ✅ Done

**Problem.** `HttpTransporter::initializeSession()` (`src/Core/Transporters/HttpTransporter.php`, lines 34–63) currently sends a bare `initialize` payload (just `method`, empty `params`). The MCP spec requires `protocolVersion`, `capabilities`, and `clientInfo`, plus a follow-up `notifications/initialized` notification. Strict-spec servers (the official TS reference, Anthropic's MCP servers, others) will reject what we send today. STDIO already does this correctly — see `StdioTransporter::sendInitializeRequests()` (`src/Core/Transporters/StdioTransporter.php`, lines 153–185) for the exact shape.

**Tasks.**
1. In `initializeSession()`, build the initialize payload with `protocolVersion`, `capabilities` (empty object is fine), and `clientInfo` (`name`, `version`) — match the STDIO shape.
2. After capturing `mcp-session-id` from the response, send a second POST with `notifications/initialized` and no `id` field (it's a notification, not a request — server doesn't reply).
3. Promote the `PROTOCOL_VERSION` constant to a shared location (e.g. a `Core/Mcp.php` value object or a constant on the `Transporter` interface). Both transporters should reference the same value — today STDIO has `'2024-11-05'` hardcoded; pick the version that matches the Streamable HTTP spec we're implementing (`2025-03-26`) and align both transporters.
4. The `clientInfo.version` should not be hardcoded (`'0.1.0'` is wrong already on STDIO). Pull it from `composer.json` via `\Composer\InstalledVersions::getPrettyVersion('redberry/mcp-client-laravel')` with a fallback string.

**Acceptance.**
- New tests covering: (a) initialize payload contains all three required fields, (b) `notifications/initialized` is sent after a successful initialize, (c) the notification has no `id`.
- Existing tests still pass; STDIO and HTTP both report the same `protocolVersion`.
- Manual smoke test against a real MCP server (e.g. `@modelcontextprotocol/server-everything` over HTTP) — confirm `tools/list` succeeds.

---

## ~~P1 — Defensive error-message handling in `SseStreamParser`~~ ✅ Done

**Problem.** `SseStreamParser::dispatch()` (`src/Core/Http/SseStreamParser.php`, lines 127–130) builds the exception message as `"JSON-RPC error: {$decoded['error']['message']}"`. If a server sends `{"error":{"code":-32601}}` without a `message` key, this emits a PHP warning and produces an empty-string error. STDIO already does this defensively in `StdioTransporter` (lines 239–240).

**Task.** Mirror the STDIO pattern: `$message = $decoded['error']['message'] ?? 'Unknown JSON-RPC error';`. Add a regression test feeding an SSE event with a missing `message` field.

**Acceptance.** New test asserts the parser throws `TransporterRequestException` with `'Unknown JSON-RPC error'` (and the code, when present) when only `code` is sent.

---

## ~~P2 — SSE read-timeout / wedge protection~~ ✅ Done

**Problem.** `HttpTransporter` passes `timeout` to Guzzle, but that bounds connection/request setup, not body reads on a streamed response. A misbehaving server that keeps the connection open while sending nothing will park a queue worker indefinitely on `$stream->read()` in `SseStreamParser::parse()` (`src/Core/Http/SseStreamParser.php`, lines 41–42).

**Tasks.**
1. Add an optional `read_timeout` config key on `HttpTransporter` (default e.g. 60s). Document it in `config/mcp-client.php`.
2. Pass it into `SseStreamParser::parse()` as a parameter.
3. In the parse loop, track `microtime(true)` per iteration and throw `TransporterRequestException` ("SSE read timed out after Xs") if the time since the *last received chunk* exceeds the timeout. Reset the clock whenever a chunk arrives — a long-running operation that streams progress events should not time out as long as something is coming through.

**Acceptance.**
- Test using a `StreamInterface` mock that returns `''` from `read()` (no data) and never EOFs — assert it throws within roughly the configured timeout.
- Test that progress events reset the clock (stream returns chunks slower than the timeout but never exceeds it between chunks).

---

## ~~P3 — Session-loss recovery~~ ✅ Done

**Problem.** `HttpTransporter::$initialized` and `$sessionId` are sticky on the instance. If the server tears down the session (per the MCP spec, this surfaces as HTTP 404 with a known error code, or a JSON-RPC error indicating "session not found"), we'll keep posting the stale `mcp-session-id` until the worker restarts. Realistic for queue workers and long-running daemons.

**Tasks.**
1. In `parseResponse()` / the request flow, detect session-loss responses (HTTP 404 from the server, *or* a JSON-RPC error with the spec's session-not-found code — check the 2025-03-26 spec for the exact code).
2. On detection: clear `$sessionId`, set `$initialized = false`, then retry the original request *once*. If the retry also fails for the same reason, surface as `TransporterRequestException`.
3. Add a `max_session_retries` config knob (default 1) so the behavior is overridable.

**Acceptance.** Test simulates: first request succeeds → second request returns 404 → transporter automatically re-initializes and retries → returns the third response. Also test the "retry also fails" path throws.

---

## ~~P4 — Cleanup: migrate reflection-based test setup~~ ✅ Done

**Resolved in [PR #42](https://github.com/RedberryProducts/mcp-client-laravel/pull/42).** The reflection-based `createTransporterWithMockedSession()` helper was replaced with `setUpInitializedTransporter()`, which uses constructor injection and primes the `initialize` + `notifications/initialized` handshake via Mockery. The Problem/Task/Acceptance text below describes the pre-refactor state and references file locations that no longer exist.

**Problem.** `createTransporterWithMockedSession()` in `tests/Transporter/HttpTransporterTest.php` (lines 16–38) uses `ReflectionClass` to poke private state. The new constructor-injection pattern from the same file (`client can be injected via constructor (no reflection needed)`, lines 288–302) is much cleaner.

**Task.** Migrate every test that calls `createTransporterWithMockedSession()` to use constructor injection plus a real first call that primes the `initialize` exchange (you can add a tiny helper that returns `[$transporter, $mockClient]` and stubs the initialize POST). Delete the old helper. No behavior changes — just a refactor.

**Acceptance.** No `ReflectionClass`/`ReflectionMethod` calls remain in `HttpTransporterTest.php` for state-setup purposes (testing `private` *methods* via reflection — `preparePayload`, `generateId`, `getClientBaseConfig` — is fine to keep). All tests still pass.

---

## ~~P5 — Cleanup: switch HTTP request IDs to an incrementing counter~~ ✅ Done

**Problem.** `HttpTransporter::generateId()` (`src/Core/Transporters/HttpTransporter.php`, lines 144–151) uses `random_int(1, 1_000_000)`. Birthday-paradox collisions become non-trivial over a long-lived session (~1-in-1000 chance after 1k requests). STDIO uses `++$this->requestId`, which is the right pattern.

**Task.** Replace with a per-instance incrementing counter, matching STDIO. Keep the `id_type` config (int vs string) — just change the source of the number. Update tests accordingly.

**Acceptance.** Two sequential requests on the same transporter instance produce IDs 1 and 2 (or "1" and "2" with `id_type=string`).

---

## P6 — Document `$onEvent` is a no-op on STDIO

**Problem.** `StdioTransporter::request()` (`src/Core/Transporters/StdioTransporter.php`, line 80) accepts `$onEvent` and silently ignores it. The interface docblock mentions this, but a README user reading the streaming-callback example may not realize it depends on the active transport.

**Task.** Add a one-paragraph note in the README, immediately after the `$onEvent` example, stating that the callback is only invoked when the connected server returns `text/event-stream`; with the STDIO transport, or when an HTTP server returns a single JSON response, the callback fires zero times.

**Acceptance.** README change merged. No code changes required.

---

## Suggested PR order

P0 → P1 → P2 → P3 → P4 → P5 → P6. P0–P3 should each be its own PR; P4–P6 can be bundled if convenient. Don't combine P0 with anything else — it's the spec-compliance fix and needs to stand on its own in the changelog.

## Out of scope (do not start without checking first)

- Long-lived `GET /` SSE channel for unsolicited server→client notifications.
- `Last-Event-ID` resumability.
