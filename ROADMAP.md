# Roadmap

Follow-up work tracked after [PR #33](https://github.com/RedberryProducts/mcp-client-laravel/pull/33) (Streamable HTTP transport). All P0–P6 items have shipped.

## Completed

- ~~**P0** — Spec-correct `initialize` handshake for `HttpTransporter`~~ — [PR #35](https://github.com/RedberryProducts/mcp-client-laravel/pull/35)
- ~~**P1** — Defensive error-message handling in `SseStreamParser`~~ — [PR #37](https://github.com/RedberryProducts/mcp-client-laravel/pull/37)
- ~~**P2** — SSE read-timeout / wedge protection~~ — [PR #38](https://github.com/RedberryProducts/mcp-client-laravel/pull/38)
- ~~**P3** — Session-loss recovery~~ — [PR #41](https://github.com/RedberryProducts/mcp-client-laravel/pull/41)
- ~~**P4** — Cleanup: migrate reflection-based test setup~~ — [PR #42](https://github.com/RedberryProducts/mcp-client-laravel/pull/42)
- ~~**P5** — Cleanup: switch HTTP request IDs to an incrementing counter~~ — [PR #43](https://github.com/RedberryProducts/mcp-client-laravel/pull/43)
- ~~**P6** — Document `$onEvent` is a no-op on STDIO~~ — [PR #43](https://github.com/RedberryProducts/mcp-client-laravel/pull/43)

The original problem statements, tasks, and acceptance criteria for each item are preserved in this file's git history.

## Out of scope

Don't start work on these without explicit confirmation:

- Long-lived `GET /` SSE channel for unsolicited server→client notifications.
- `Last-Event-ID` resumability on Streamable HTTP.

If a feature in this list becomes necessary, raise it as a new ROADMAP item before writing code.
