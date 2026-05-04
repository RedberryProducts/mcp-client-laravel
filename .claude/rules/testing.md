# Testing Rules

- Framework: **Pest** (not raw PHPUnit), bootstrapped via `tests/Pest.php` which applies `Redberry\MCPClient\Tests\TestCase` to everything under `tests/`.
- Base class: `tests/TestCase.php` extends Orchestra Testbench and registers `MCPClientServiceProvider`. Don't introduce a second base class — extend or compose if you need more setup.
- File naming: `{ClassName}Test.php` placed under the directory matching its concern (`Transporter/`, `Http/`, `MCPClient/`, `Helpers/`).
- Use `it('does X', function () { ... })` and `describe()` blocks. Don't use PHPUnit-style `public function test_X()` methods in new files.
- Run a single test: `vendor/bin/pest --filter="test name"`. Run a directory: `vendor/bin/pest tests/Transporter/`.

## No real network or subprocesses

- HTTP transporter tests must mock Guzzle. The constructor accepts `?GuzzleHttp\ClientInterface`, so build a `Client` with a `GuzzleHttp\Handler\MockHandler` stack and inject it. There is an existing helper in `tests/Transporter/HttpTransporterTest.php` showing constructor injection — copy that pattern.
- Don't use `ReflectionClass` to set private state on a transporter in new tests. The legacy `createTransporterWithMockedSession()` helper is on the way out (ROADMAP P4) — adding more callers makes that cleanup harder.
- STDIO transporter tests must not spawn real `npx` or other commands. Use a script fixture or a stub command that emits known JSON-RPC lines.
- SSE parser tests should feed a Guzzle/PSR `StreamInterface` mock — see `tests/Http/SseStreamParserTest.php` for the pattern.

## Fixtures

- Real-shape responses live in `tests/Datasets/` (e.g. `GithubResponseExample.php`). Add new fixture data there as `return`-style PHP files, not inline string heredocs.
- When a fixture corresponds to a specific MCP server's response, name the file after the server (`GithubResponseExample.php`, not `Example1.php`).

## What to test for a new transporter

- Happy path: a successful `request()` returns the decoded JSON-RPC `result` array.
- Error path: a JSON-RPC error response throws `TransporterRequestException` carrying the spec error code.
- Initialization: the `initialize` exchange happens exactly once per instance and uses the spec-correct payload (`protocolVersion`, `capabilities`, `clientInfo`) — see `mcp-spec.md`.
- `$onEvent`: invoked for every decoded event when the transport streams; not invoked when the transport returns a single message. Document explicitly which case applies.

## What to test for changes to `MCPClient` or the facade

- `connect()` raises a `RuntimeException` for unknown server names (see `MCPClient::ensureConfigurationValidity()`).
- `tools()` and `resources()` strip the `tools`/`resources` envelope and return a `Collection`.
- `callTool()` forwards `$onEvent` to the transporter; `tools()`/`resources()` do not (they call `request()` directly).

## Architecture rule

`tests/ArchTest.php` forbids `dd`, `dump`, `ray`. Don't disable or relax that rule. If you need to debug, use a local `error_log()` or `\Illuminate\Support\Facades\Log` call and remove it before committing.
