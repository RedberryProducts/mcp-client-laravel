# Upgrade Guide

## Upgrading from `1.x` to `2.x`

Version `2.0` lands the spec-correct Streamable HTTP transport, a singleton service binding, an immutable `connect()`, and a clean split between the STDIO request timeout and the subprocess kill timer. Most users will only need to read sections **1**, **5**, and **6**.

If you maintain a third-party transport or a class that implements `Contracts\MCPClient`, also read section **8**.

### Quick scan

| Likely affects you if you… | See |
| --- | --- |
| Hold two `connect()` results to two different servers in the same scope | [1. `connect()` is now immutable](#1-connect-is-now-immutable) |
| Mutate `config('mcp-client.servers')` at runtime | [2. `MCPClient` is bound as a singleton](#2-mcpclient-is-bound-as-a-singleton) |
| Type-hint `Contracts\MCPClient` in DI | [3. The contract is now resolvable from DI](#3-the-contract-is-now-resolvable-from-di) |
| Set `timeout` on a STDIO server config | [4. STDIO `timeout` no longer kills the subprocess](#4-stdio-timeout-no-longer-kills-the-subprocess) |
| Rely on a sealed environment for a STDIO subprocess | [5. STDIO `env` now inherits the parent env](#5-stdio-env-now-inherits-the-parent-env) |
| Run long-idle SSE streams through the HTTP transport | [6. HTTP transport defaults to a 60s SSE read timeout](#6-http-transport-defaults-to-a-60s-sse-read-timeout) |
| Treat `callTool()` / `readResource()` results as scalars | [7. `callTool()` / `readResource()` return `array`](#7-calltool--readresource-return-array) |
| Implement a custom `Transporter` or `Contracts\MCPClient` | [8. Interface signatures tightened](#8-interface-signatures-tightened) |

---

### 1. `connect()` is now immutable

**What changed.** `MCPClient::connect($server)` used to mutate the root client in place and return `$this`. It now returns a *clone* configured for the requested server; the root client is unchanged. Repeated `connect($same)` reuses a cached `Transporter` on the root, so the `initialize` handshake is paid once per server per root instance.

**Why.** The previous behavior silently broke any code holding two handles to two servers — both handles routed through whichever server was connected to last.

**Action.** If you chain (`MCPClient::connect('github')->tools()`) you are already correct. If you hold the result, capture it explicitly per server:

```php
// Before — the second connect() rerouted $github to the npx server.
$github = MCPClient::connect('github');
$npx    = MCPClient::connect('npx_mcp_server');

$github->tools(); // accidentally hit npx_mcp_server in 1.x

// After — each handle is isolated.
$github = MCPClient::connect('github');
$npx    = MCPClient::connect('npx_mcp_server');

$github->tools(); // correct, hits github
```

If a class member held the connected client, capture it once and reuse:

```php
class MyService
{
    public function __construct(private MCPClient $mcpClient)
    {
        $this->mcpClient = $mcpClient->connect('github');
    }
}
```

---

### 2. `MCPClient` is bound as a singleton

**What changed.** `MCPClientServiceProvider` now binds `MCPClient` with `singleton()` instead of `bind()`. Every resolve from the container — facade, constructor injection, `app(MCPClient::class)` — returns the same instance. Server configuration is captured at first resolve.

**Action.** If you mutate `config('mcp-client.servers')` after the first resolve and need the new config to take effect, force a re-resolve:

```php
config()->set('mcp-client.servers.github.token', $newToken);

app()->forgetInstance(\Redberry\MCPClient\MCPClient::class);
```

Most apps will not need this — config is normally fixed for the request.

---

### 3. The contract is now resolvable from DI

**What changed.** `Redberry\MCPClient\Contracts\MCPClient` is now aliased to the concrete singleton. Type-hinting the contract used to throw an unresolvable container error; it now resolves to the same singleton as the concrete class.

**Action.** None required. If you previously worked around this by type-hinting the concrete `MCPClient`, you can switch to the contract for cleaner DI:

```php
use Redberry\MCPClient\Contracts\MCPClient;

public function __construct(private MCPClient $client) {}
```

---

### 4. STDIO `timeout` no longer kills the subprocess

**What changed.** The single `timeout` config key used to control both the per-request wait *and* Symfony Process's kill timer. They are now separate keys:

- `request_timeout` (default `30`s) — per-call wait inside `waitForResponse()`.
- `process_timeout` (default `null`, kill timer disabled) — Symfony Process's 5th constructor argument.

The legacy `timeout` key still falls back to `request_timeout` when only it is set. It no longer doubles as a process kill timer.

**Why.** With the old behavior, queue workers issuing many tool calls over the same STDIO subprocess were killed mid-flight after `timeout` seconds of process life — every call after that failed.

**Action.** Most users want the new behavior (long-lived subprocess, bounded per-request wait). If you genuinely want the old hard-kill behavior, set both keys:

```php
// Before
'timeout' => 30,

// After (long-lived subprocess, 30s per request — recommended)
'request_timeout' => 30,

// After (preserve 1.x behavior — process killed at 30s)
'request_timeout' => 30,
'process_timeout' => 30,
```

Default `request_timeout` was also raised from `3` to `30` so the README's `npx -y @modelcontextprotocol/server-memory` example completes its first-run cold start out of the box.

---

### 5. STDIO `env` now inherits the parent env

**What changed.** The STDIO transport used to forward only `PATH` from the parent process — every other env var was scrubbed. It now passes `null` to Symfony Process when no `env` is supplied (full parent inheritance), and merges user `env` on top of `getenv()` when one is supplied.

**Why.** `npx`, `node`, and most MCP servers need `HOME` (npm cache, npmrc), often `LANG`, `NODE_OPTIONS`, `NPM_CONFIG_*`. The 1.x behavior broke these silently.

**Action.** No action required for typical use — your subprocess will usually now have *more* env access, not less. If you depend on a sealed environment (hermetic execution, secret scrubbing), opt in explicitly:

```php
'npx_mcp_server' => [
    // …
    'env' => ['NODE_OPTIONS' => '--max-old-space-size=512'],
    'inherit_env' => false, // sealed: only the keys in `env` are forwarded
],
```

---

### 6. HTTP transport defaults to a 60s SSE read timeout

**What changed.** `HttpTransporter` now enforces a maximum gap between SSE chunks via the new `read_timeout` config key (default `60` seconds). The clock resets on every received chunk, so a tool that emits progress events every few seconds stays alive indefinitely.

**Action.** If you call tools that legitimately go silent for more than 60s mid-stream, raise or disable it:

```php
'github' => [
    // …
    'read_timeout' => 120,  // longer gap allowed
    // or
    'read_timeout' => null, // disable the inter-chunk timeout entirely
],
```

This timeout has no effect on plain `application/json` responses.

---

### 7. `callTool()` / `readResource()` return `array`

**What changed.** The return types on the contract tightened from `mixed` to concrete types:

| Method | 1.x | 2.x |
| --- | --- | --- |
| `tools()` | (untyped) | `Collection` |
| `resources()` | (untyped) | `Collection` |
| `callTool()` | `mixed` | `array` |
| `readResource()` | `mixed` | `array` |

**Action.** None for the common case — both methods always returned arrays in practice. If you assigned `$result = $client->callTool(...)` to a variable typed as `mixed` and used it as a scalar, your code already had a latent bug; treat the return value as `array`.

Both methods now also accept an optional third `?callable $onEvent` argument; existing call sites without it are unchanged.

`callTool()` and `readResource()` called *without* a prior `connect()` now throw a `RuntimeException` with the message `"Call connect($serverName) before callTool()."` (or `…readResource().`). 1.x leaked a `TypeError` from an uninitialized typed property in the same situation.

---

### 8. Interface signatures tightened

**Only relevant if you implement `Redberry\MCPClient\Core\Transporters\Transporter` or `Redberry\MCPClient\Contracts\MCPClient` in your own code.**

#### `Transporter::request()`

Added a third optional parameter:

```php
// 1.x
public function request(string $action, array $params = []): array;

// 2.x
public function request(string $action, array $params = [], ?callable $onEvent = null): array;
```

If your transport doesn't stream, accept the parameter and ignore it — match the contract exactly. PHP's interface compatibility check requires the signature to align.

#### `Contracts\MCPClient`

```php
// 1.x
public function tools();
public function resources();
public function callTool(string $toolName, mixed $params = []): mixed;
public function readResource(string $uri): mixed;

// 2.x
public function tools(): Collection;
public function resources(): Collection;
public function callTool(string $toolName, mixed $params = [], ?callable $onEvent = null): array;
public function readResource(string $uri, ?callable $onEvent = null): array;
```

Update return types and add the `?callable $onEvent` parameter to bring your implementation in line.

---

### After upgrading

1. Bump your `composer.json` constraint: `"redberry/mcp-client-laravel": "^2.0"`.
2. Run `composer update redberry/mcp-client-laravel`.
3. Run your test suite. The most likely surface for regressions is multi-server code paths (section 1) and any STDIO worker that depended on the old kill-timer behavior (section 4).
4. If you publish `config/mcp-client.php`, diff it against the 2.x default to pick up the new `read_timeout`, `max_session_retries`, `request_timeout`, `process_timeout`, and `inherit_env` keys. None are required — defaults are backwards-compatible apart from the changes called out in sections 4–6.

If you hit something this guide doesn't cover, please open an issue at <https://github.com/RedberryProducts/mcp-client-laravel/issues>.
