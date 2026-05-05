# Architecture

This document describes how `redberry/mcp-client-laravel` is put together internally — the layers, the request flow, transport lifecycles, and the rationale for non-obvious choices. It complements `CLAUDE.md` (which is a quick reference) and the per-domain rule files in `.claude/rules/`.

If you only need to *use* the package, the [README](README.md) has everything. This document is for people changing the package itself.

---

## Layers

The package is four small layers stacked on top of each other:

```
┌────────────────────────────────────────────────────────────────────┐
│ Public API           MCPClient (facade + concrete) ──────────────► │  user-facing
│                      Collection                                    │
├────────────────────────────────────────────────────────────────────┤
│ Service binding      MCPClientServiceProvider                      │  Laravel glue
│                      TransporterFactory                            │
├────────────────────────────────────────────────────────────────────┤
│ Transport            Transporter (interface)                       │  IO seam
│                      ├── HttpTransporter                           │
│                      └── StdioTransporter                          │
├────────────────────────────────────────────────────────────────────┤
│ Wire decoding        SseStreamParser                               │  parsers
│                      JSON-RPC envelope handling (in transporters)  │
└────────────────────────────────────────────────────────────────────┘
```

Each layer talks only to the one immediately below it. The **transport layer is the only IO seam** — every byte that crosses the network or stdin/stdout boundary goes through `Transporter::request()`. That's deliberate: it's the only thing tests need to mock, and the only place a new transport can be added.

---

## Public API surface

The user-facing API lives in `src/MCPClient.php` and is intentionally small:

| Method | Purpose | Streams? |
|---|---|---|
| `connect(string $serverName)` | Picks a server config out of the map and instantiates the transporter via `TransporterFactory` | — |
| `tools(): Collection` | `tools/list` JSON-RPC call | No |
| `resources(): Collection` | `resources/list` JSON-RPC call | No |
| `callTool(string $name, mixed $params, ?callable $onEvent): array` | `tools/call` JSON-RPC call, forwards `$onEvent` | Yes (when transport supports it) |
| `readResource(string $uri, ?callable $onEvent): array` | `resources/read` JSON-RPC call, forwards `$onEvent` | Yes (when transport supports it) |

The contract for both `MCPClient` and the underlying `Transporter` lives in `src/Contracts/MCPClient.php` and `src/Core/Transporters/Transporter.php` — keep both interfaces narrow.

`Collection` (`src/Collection.php`) is a tiny `Countable + IteratorAggregate` wrapper with `all()`, `only(...$names)`, `except(...$names)`, `map($fn)`, and `toArray()`. It is **not** Laravel's `Illuminate\Support\Collection` — keeping it standalone avoids pulling the full collections package as a hard dep and avoids name collisions in user code.

---

## Service binding

`MCPClientServiceProvider` (`src/MCPClientServiceProvider.php`) uses `spatie/laravel-package-tools` to:

1. Register the published config file (`config/mcp-client.php`).
2. Register the `MCPClientCommand` artisan command.
3. In `packageBooted()`, bind `Redberry\MCPClient\MCPClient` to a closure that pulls the `mcp-client.servers` array from config and constructs a fresh client.

**This is the only place in the package that reads `config()` or wires concrete classes.** Every other class takes its configuration through the constructor. Two consequences:

- Tests can construct `MCPClient` directly with a literal `$servers` array — no Laravel container needed beyond what Orchestra Testbench provides.
- A user who wants a non-default config (e.g. a per-tenant server map) can resolve the binding manually, `MCPClient` stays oblivious.

The factory itself (`TransporterFactory::make()`) is the only place that maps the `Transporters` enum value to a concrete transporter class. Adding a new transport is a two-line change there plus a new enum case.

---

## Request flow

The full happy-path flow for a streamed `callTool()`:

```
Caller code
    │
    ▼
MCPClient::callTool($name, $args, $onEvent)
    │   builds {name, arguments} params
    ▼
Transporter::request('tools/call', $params, $onEvent)        ◄── interface call
    │
    ├─ (HTTP)                                  │ ├─ (STDIO)
    │  initializeSession()  if not yet done    │ │  start() if process not running
    │  preparePayload(method, params, id)      │ │  preparePayload(method, params, id)
    │  POST with Accept: json, event-stream    │ │  fwrite(stdin, json + "\n")
    │  parseResponse():                        │ │  waitForResponse($id):
    │    ├─ Content-Type: text/event-stream    │ │    poll getIncrementalOutput()
    │    │  → SseStreamParser::parse(body, $onEvent)         every poll_interval ms
    │    └─ Content-Type: application/json     │ │    until a line with matching id
    │       → json_decode + return result      │ │  return $data['result']
    ▼                                          ▼ ▼
Caller receives the JSON-RPC `result` (decoded array)
```

Two paths meet at the same return shape: the JSON-RPC `result` field, decoded as an associative array. If a server returns a non-array `result` (rare — null, scalar), the HTTP transporter hands back the full envelope so the `: array` return contract holds. STDIO does not currently handle this edge case.

---

## HTTP transport

`Redberry\MCPClient\Core\Transporters\HttpTransporter` implements MCP's [Streamable HTTP](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http) (`2025-03-26`).

### Lifecycle

```
new HttpTransporter($config, ?$guzzleClient)
    │
    ▼
first request()
    │
    ├─ initializeSession()   ◄── runs once per instance
    │   POST '' with initialize payload
    │   capture mcp-session-id from response header
    │   set $initialized = true
    │
    └─ user request
        POST '' with method+params
        replay mcp-session-id header on every call
        parseResponse() branches on Content-Type
```

- **Why lazy initialize.** Constructing a transporter shouldn't make a network call. Deferring the handshake until the first real request keeps the constructor cheap and lets tests build instances without any IO.
- **Why one Guzzle client per instance.** Guzzle clients hold connection pools and base config. Reusing a single client across all requests in a session means HTTP keep-alive works for free.
- **Why `stream => true` on every request.** It lets us hand the body to `SseStreamParser` without buffering the full response. For non-stream responses it's a small no-op cost.
- **Why constructor-injected client.** Tests inject a `Client` wrapping `GuzzleHttp\Handler\MockHandler`. No reflection needed for new tests.

### Response parsing

`parseResponse()` reads the first segment of `Content-Type`, lowercased and trimmed:

- `text/event-stream` → `SseStreamParser::parse($body, $onEvent)`. The parser drives `$onEvent` and returns the result-bearing payload.
- anything else → `json_decode` → return `$data['result']` if it's an array, else the whole envelope.

JSON-RPC errors (`isset($data['error'])`) throw `TransporterRequestException` carrying the spec error code as the exception code. The `message` field is read defensively (`$data['error']['message'] ?? 'Unknown JSON-RPC error'`) so spec-compliant servers that omit it don't trigger PHP warnings.

### Session loss recovery

A request to an active session that returns HTTP `404` is the MCP spec signal that the session has expired or been evicted. `HttpTransporter` catches this case, clears its session id and `$initialized` flag, replays the `initialize` handshake, and retries the original request. The number of retries is bounded by `max_session_retries` (default `1`); set it to `0` to disable.

### SSE read timeout

The HTTP transport advertises `Accept: application/json, text/event-stream` on every request. When the server replies with an event stream, `SseStreamParser::parse()` enforces a maximum gap between chunks via `read_timeout` (default `60` seconds). The clock resets on every received chunk, so a tool that emits progress events stays alive indefinitely; only a wedged stream aborts.

---

## STDIO transport

`Redberry\MCPClient\Core\Transporters\StdioTransporter` runs the configured command as a subprocess via Symfony Process and exchanges newline-delimited JSON-RPC over stdin/stdout.

### Lifecycle

```
new StdioTransporter($config)
    validateConfig()        ◄── empty command → ServerConfigurationException
    │
    ▼
first request()
    │
    ├─ start():
    │   initializeProcess()           ◄── builds Process with InputStream
    │   $process->start()
    │   usleep(startup_delay)               ◄── give server time to boot, default 100ms
    │   isRunning()? else handleStartupFailure()
    │   sendInitializeRequests():
    │     write {initialize} \n
    │     write {notifications/initialized} \n
    │
    └─ user request
        write {method, params, id: ++$requestId} \n
        waitForResponse($id):
          loop:
            buffer .= $process->getIncrementalOutput()
            for each newline-terminated line in buffer:
              json_decode; if id matches, return result (or throw on error)
            usleep(poll_interval), default 20ms
            timeout after request_timeout (default 30s)
    ▼
__destruct() / close()
    inputStream->close()
    process->stop() if still running
```

- **Why lazy start.** Same reasoning as HTTP — constructing shouldn't fork a process.
- **Why a startup delay.** Many MCP servers (especially `npx`-launched Node ones) take a moment to be ready to read from stdin. Without the delay, the first `initialize` write can race the server's readline loop. The default is `100`ms; users override via `startup_delay`.
- **Why two timeouts.** `request_timeout` bounds a single `waitForResponse()` cycle; `process_timeout` is Symfony Process's whole-subprocess kill timer. Bundling them under one key meant queue workers issuing many tool calls over the same subprocess were killed mid-flight after one `timeout` worth of process life. They are now decoupled, with `process_timeout` defaulting to `null` (kill timer disabled).
- **Why parent-env inheritance.** Forwarding only `PATH` silently broke `npx`/`node`, which need `HOME` to find npm caches and `NODE_OPTIONS`/`NPM_CONFIG_*` for many MCP servers. The transport now passes `null` to `Process` (clean inheritance) when no `env` is supplied, and merges user keys on top of `getenv()` when one is. `inherit_env: false` opts into a sealed env.
- **Why polling instead of blocking reads.** Symfony Process's `getIncrementalOutput()` is non-blocking and drains both buffered stdout and any new bytes since the last call. We poll because the server can interleave notifications between requests, and we need to walk every line looking for our matching `id`.
- **Why a per-instance counter for ids.** `++$this->requestId` is collision-free for the lifetime of the instance. No randomness, no `id_type` wobble — the cast to string is the only branch.

### Asymmetry with HTTP

`$onEvent` is **a no-op on STDIO**. The contract accepts the parameter so callers can pass it unconditionally, but `waitForResponse()` only matches against the JSON-RPC `id` and returns the matching result; intermediate notifications are not decoded or surfaced. The `Transporter::request()` docblock and the README both spell this out for callers.

Both transports source `protocolVersion` from `Mcp::PROTOCOL_VERSION` and `clientInfo` from `Mcp::clientInfo()`, so version handling is centralised.

---

## SSE parsing

`Redberry\MCPClient\Core\Http\SseStreamParser` decodes [Server-Sent Events](https://html.spec.whatwg.org/multipage/server-sent-events.html) where each event carries one JSON-RPC message in its `data:` field.

### Wire format

```
event: message
data: {"jsonrpc":"2.0","method":"notifications/progress","params":{...}}

event: message
data: {"jsonrpc":"2.0","id":1,"result":{...}}

```

### Algorithm

```
parse(StreamInterface $stream, ?callable $onEvent): array
    buffer  = ''
    current = {event: null, data: ''}
    final   = null

    while not eof:
        chunk = $stream->read(8192)
        if chunk empty, continue
        buffer .= chunk
        drainLines(buffer, current, final, onEvent)

    # flush trailing partial event without terminator
    if buffer or current.data:
        buffer .= "\n\n"
        drainLines(buffer, current, final, onEvent)

    if final is null:
        throw  // stream ended without a result
    return final
```

`drainLines()` walks complete `\n`-terminated lines from the buffer. A blank line dispatches the accumulated event:

- Decode `current.data` as JSON.
- If the JSON has `error`, throw `TransporterRequestException`.
- If `$onEvent` is set, call it with the decoded message.
- If the message has a `result`, record it as the new "final."
- Reset `current` to an empty event.

Comment lines (`:`-prefixed) and `data: [DONE]` sentinels are ignored. Multi-line `data:` continuations are concatenated with `\n` before decoding. Error events without a `message` field fall back to `'Unknown JSON-RPC error'` so spec-compliant servers that omit it do not trigger PHP warnings.

### Why a custom parser

Guzzle doesn't ship an SSE decoder; pulling one as a dep just for this one use case isn't worth it. The class is ~140 lines and has explicit unit tests in `tests/Http/SseStreamParserTest.php`.

---

## Configuration

`config/mcp-client.php` is a flat map of server-name → server-config. Two transport shapes:

### HTTP

```php
'github' => [
    'type'                => Transporters::HTTP,
    'base_url'            => 'https://api.githubcopilot.com/mcp',
    'token'               => env('GITHUB_API_TOKEN'),
    'timeout'             => 30,           // seconds; connection timeout
    'read_timeout'        => 60,           // seconds; max gap between SSE chunks (null disables)
    'max_session_retries' => 1,            // retries after a session-loss 404 (0 disables)
    'headers'             => [],           // merged into every request
    'id_type'             => 'int',        // 'int' | 'string' — JSON-RPC id cast
],
```

### STDIO

```php
'npx_mcp_server' => [
    'type'            => Transporters::STDIO,
    'command'         => ['npx', '-y', '@modelcontextprotocol/server-memory'],
    'cwd'             => base_path(),
    'env'             => null,             // user keys merged on top of parent env
    'inherit_env'     => true,             // false → forward only the `env` keys
    'request_timeout' => 30,               // seconds, applies to waitForResponse
    'process_timeout' => null,             // seconds, Symfony Process kill timer; null disables
    'startup_delay'   => 100,              // ms after process start
    'poll_interval'   => 20,               // ms between getIncrementalOutput() polls
],
```

The legacy `timeout` key on STDIO falls through to `request_timeout` when only that is set, so 1.x configs keep working without reaching for the kill timer.

The factory dispatches on `type`; everything else is transport-specific and only the relevant transporter inspects its keys.

---

## Exception model

Two exceptions, both extending `\Exception`:

- `Redberry\MCPClient\Core\Exceptions\TransporterRequestException` — every transport-layer failure: HTTP errors, JSON-RPC errors, malformed responses, timeouts, eventual session loss. Code preserves the spec JSON-RPC error code where applicable.
- `Redberry\MCPClient\Core\Exceptions\ServerConfigurationException` — invalid config shape detected at construction (currently only thrown by `StdioTransporter` for missing `command`).

`MCPClient::connect()` throws a vanilla `\RuntimeException` for unknown server names, and `callTool()` / `readResource()` throw the same when called without a prior `connect()` — both are programmer errors, not runtime IO problems.

Callers should catch `TransporterRequestException` for IO failures. They should never need to catch raw `GuzzleException` or `JsonException` — those are wrapped at the transport boundary.

---

## Testing strategy

- **Pest** with Orchestra Testbench (`tests/TestCase.php`).
- **No real network or subprocesses.** HTTP transporter tests inject a Guzzle `Client` wrapping `MockHandler`. SSE parser tests feed a `StreamInterface` mock. STDIO transporter tests use stub commands or fixtures, not real `npx`.
- **Architecture rule.** `tests/ArchTest.php` forbids `dd`, `dump`, `ray` in source files.
- **Constructor injection over reflection.** `HttpTransporter` accepts an optional Guzzle client specifically so tests don't need `ReflectionClass`. New tests should follow that pattern.
- **Real-shape fixtures** live in `tests/Datasets/` (e.g. `GithubResponseExample.php`).

See [`.claude/rules/testing.md`](.claude/rules/testing.md) for the full testing rules.

---

## Adding a new transport

1. Create `src/Core/Transporters/MyTransporter.php` implementing `Transporter`.
2. Add a case to `src/Enums/Transporters.php`.
3. Add a `match` arm to `src/Core/TransporterFactory::make()`.
4. Document new config keys in `config/mcp-client.php` with inline comments.
5. Write tests under `tests/Transporter/MyTransporterTest.php` covering happy path, error path, initialize handshake, and (if streaming) `$onEvent` invocation.
6. Add a CHANGELOG entry under `## Unreleased`.

See [`.claude/rules/transporters.md`](.claude/rules/transporters.md) for the full contract obligations.

---

## What's intentionally not here

Documented in [ROADMAP.md "Out of scope"](ROADMAP.md#out-of-scope):

- Long-lived `GET /` SSE channel for unsolicited server→client notifications.
- `Last-Event-ID` resumability on Streamable HTTP.
- Sampling, completions, elicitation flows beyond `tools/list`, `tools/call`, `resources/list`, `resources/read`.
- Server capabilities introspection.

The `MCPClient` API is deliberately narrow — request/response only, four verbs. Widening it requires a ROADMAP item first.
