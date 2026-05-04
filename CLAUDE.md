## Project Overview

**MCP Client for Laravel** (`redberry/mcp-client-laravel`) is a Laravel-native client for Model Context Protocol (MCP) servers. It speaks JSON-RPC 2.0 over two transports — Streamable HTTP (spec `2025-03-26`) and STDIO — and exposes them through a single facade so a Laravel app can list/call tools, read resources, and observe streamed events from any MCP-compliant server defined in config.

## Commands

```bash
composer test                          # Run Pest tests
composer test-coverage                 # Tests with coverage
composer analyse                       # PHPStan (level 5, src + config)
composer format                        # Laravel Pint
bin/check                              # Pint → PHPStan → Pest in sequence (the local CI gate)
vendor/bin/pest --filter="test name"   # Run a single test
vendor/bin/pest tests/Transporter/     # Run a directory
composer prepare                       # testbench package:discover (after dep changes)
```

CI runs Pint (`fix-php-code-style-issues.yml`), PHPStan (`phpstan.yml`), and Pest (`run-tests.yml`) on every push.

## File Map

```
src/
  MCPClient.php                        # User-facing client. connect() → tools()/resources()/callTool()/readResource()
  MCPClientServiceProvider.php         # Spatie PackageServiceProvider — config + binding live here only
  Collection.php                       # Lightweight Countable/IteratorAggregate wrapper for tools/resources lists
  Contracts/
    MCPClient.php                      # Public client interface
  Core/
    TransporterFactory.php             # Maps Transporters enum → concrete transporter (the only place that does)
    Transporters/
      Transporter.php                  # Single-method contract: request(string $action, array $params, ?callable $onEvent): array
      HttpTransporter.php              # Streamable HTTP (Guzzle); content-negotiates JSON vs SSE per request
      StdioTransporter.php             # Symfony Process; line-delimited JSON-RPC over stdin/stdout
    Http/
      SseStreamParser.php              # Streaming SSE → JSON-RPC decoder, surfaces every event via $onEvent
    Exceptions/
      TransporterRequestException.php  # Throw this on any transport-level failure
      ServerConfigurationException.php # Throw this on invalid server config
  Enums/Transporters.php               # 'http' | 'stdio'
  Facades/MCPClient.php                # Laravel facade wrapping Redberry\MCPClient\MCPClient
  Commands/MCPClientCommand.php        # Artisan command stub

config/mcp-client.php                  # Server map; published via `vendor:publish --tag=mcp-client-config`
tests/
  Transporter/                         # HTTP/STDIO/factory tests
  Http/SseStreamParserTest.php         # SSE parser tests
  MCPClient/MCPClientTest.php          # Facade-level integration tests
  Helpers/CollectionTest.php           # Collection unit tests
  Datasets/                            # Shared fixtures (e.g. real-shape responses)
  Pest.php, TestCase.php, ArchTest.php # Pest bootstrap, Orchestra base, debug-call arch rule
ROADMAP.md                             # Authoritative list of P0–P6 follow-ups; each item ships as its own PR
```

## Architecture

### Request flow

```
MCPClient::callTool('x', $args)
  → Transporter::request('tools/call', ['name' => 'x', 'arguments' => $args], $onEvent)
    → preparePayload()  // {jsonrpc:"2.0", id, method, params}
    → POST (HTTP) or stdin write (STDIO)
    → parseResponse / waitForResponse
    → returns the JSON-RPC `result` (array)
```

`MCPClient::tools()` and `resources()` call `request()` directly (no `$onEvent`), since list calls don't stream meaningfully.

### HTTP transport (`HttpTransporter`)

Implements MCP's [Streamable HTTP](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http). Lifecycle:

1. **`initializeSession()`** — runs once per instance before the first user request. POSTs an `initialize` payload, captures `mcp-session-id` from the response header, and replays it on every subsequent request via `mcp-session-id` header.
2. Every request advertises `Accept: application/json, text/event-stream`. The server picks per-call.
3. **`parseResponse()`** branches on the response Content-Type: `text/event-stream` → `SseStreamParser::parse()`; anything else → single JSON decode.
4. JSON-RPC errors throw `TransporterRequestException` with the spec error code.

### STDIO transport (`StdioTransporter`)

Spawns the configured command via Symfony `Process`. Lifecycle:

1. **`start()`** — lazy. On first `request()`, starts the process, sleeps `startup_delay` ms, then sends both `initialize` and `initialized` payloads back-to-back.
2. **`request()`** writes one line of JSON to stdin and calls `waitForResponse($id)`, which polls `getIncrementalOutput()` every `poll_interval` ms until it sees a JSON line whose `id` matches.
3. **`__destruct()`** stops the process and closes the input stream.

Note: STDIO **ignores** `$onEvent` — the contract says so, but it's not invoked from STDIO because we don't decode intermediate notifications today (see ROADMAP P6).

### SSE parsing (`SseStreamParser`)

Reads the Guzzle `StreamInterface` in 8KB chunks, drains complete `\n`-terminated lines, accumulates `data:` continuations into one event, and dispatches on blank-line boundaries. For every decoded JSON-RPC message it calls `$onEvent` (notifications, progress, final result alike); the result-bearing payload becomes the return value. Stream ending without a result throws.

### Service binding

`MCPClientServiceProvider::packageBooted()` binds `Redberry\MCPClient\MCPClient` with the resolved `mcp-client.servers` array. **This is the only place in the package that reads `config()` or wires concrete classes.** Everywhere else takes its config via constructor.

## Conventions

- **Namespace:** `Redberry\MCPClient`
- **`declare(strict_types=1);`** at the top of every new PHP file (some older files don't have it — add it when you touch them)
- **Constructor injection only** — no `app()` / `resolve()` / facades inside core services
- **`config()` only in the service provider and `config/mcp-client.php`**, never in transporters or services
- **Throw `TransporterRequestException`** for any transport-layer failure (HTTP error, JSON-RPC error, malformed response, timeout). Throw `ServerConfigurationException` for invalid config shapes.
- **JSON-RPC `id` generation** must be a per-instance incrementing counter, like `StdioTransporter::$requestId` (HTTP currently uses `random_int` — see ROADMAP P5)
- **`PROTOCOL_VERSION` belongs in one place** — reference `Redberry\MCPClient\Core\Mcp::PROTOCOL_VERSION` from both transporters; don't redeclare per-class.
- **Conventional Commits:** `feat:`, `fix:`, `chore:`, `test:`, `refactor:`, `docs:`
- **One ROADMAP item per PR** unless P4–P6 are bundled (see ROADMAP.md for the suggested PR order)

## Don't

- Don't bypass `Transporter::request()` — it's the only IO seam and the contract every transport implements
- Don't add new code paths that read `config()` outside the service provider
- Don't hardcode the protocol version a second time (`'2024-11-05'` in `StdioTransporter` is already a known issue — don't replicate the pattern)
- Don't hardcode `clientInfo.version` — use `Redberry\MCPClient\Core\Mcp::clientInfo()` which sources the version from the installed composer package.
- Don't introduce real network or real subprocesses in tests — mock Guzzle with `MockHandler`, mock STDIO with fixtures
- Don't use `ReflectionClass` to set up transporter state in new tests — use constructor injection (HTTP transporter accepts `?GuzzleClient`)
- Don't use `random_int` for new request id generators — use an incrementing counter
- Don't add `dd()`, `dump()`, `ray()`, or `var_dump()` in committed code (the arch test in `tests/ArchTest.php` will fail)
- Don't widen the changelog scope of P0 by combining it with other ROADMAP items (per ROADMAP.md:96)
- Don't start work on resumability, `Last-Event-ID`, or a long-lived `GET /` SSE channel without checking first (out-of-scope per ROADMAP.md:98–102)

## Definition of Done

1. `bin/check` passes locally (Pint + PHPStan + Pest, in that order)
2. New code paths have tests; new public APIs have at least one happy-path test and one error-path test
3. `CHANGELOG.md` updated under `## Unreleased` with an `Added`/`Changed`/`Fixed` entry for any user-facing change
4. README updated only if the public API or configuration shape changes
5. ROADMAP item ticked off (or moved/edited if scope shifted)
6. Conventional Commits format
