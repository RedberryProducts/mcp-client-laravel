# Contributing

Thanks for taking the time to contribute. This document covers everything you need to get a change merged into `redberry/mcp-client-laravel`.

If you're an AI agent working in this repo, also read [CLAUDE.md](CLAUDE.md), [ARCHITECTURE.md](ARCHITECTURE.md), and the rule files under [.claude/rules/](.claude/rules/).

---

## Local setup

The package is developed inside a Laravel host app via path repository (composer) — but you can also clone it standalone and use Orchestra Testbench (which is wired in via `composer.json`).

### Standalone

```bash
git clone https://github.com/redberryproducts/mcp-client-laravel.git
cd mcp-client-laravel
composer install
composer test          # confirms the toolchain works
```

### Inside a host app (path repository)

```bash
# in your host app's composer.json
{
  "repositories": [
    { "type": "path", "url": "packages/redberry/laravel-mcp-client" }
  ]
}
```

```bash
composer require redberry/mcp-client-laravel:@dev
```

Symlinked path repositories mean edits in `packages/redberry/laravel-mcp-client/` are immediately picked up by the host app — no `composer update` cycle.

### Tooling versions

- PHP **8.3** or **8.4**
- Laravel **10**, **11**, or **12** (Illuminate contracts `^10.0||^11.0||^12.0`)
- Composer 2.x

CI runs Pint, PHPStan, and Pest on every push.

---

## Workflow

### 1. Pick a task

Most non-trivial work is queued in [ROADMAP.md](ROADMAP.md) as P0 → P6. **Pick the lowest-numbered unstarted item unless you have a specific reason to skip ahead.** Each ROADMAP item ships as **its own PR** — don't bundle them. P0 in particular must stand alone (it's the spec-compliance fix and needs to be a clean entry in the changelog).

If you want to do something not in the ROADMAP, open an issue first to discuss scope. The package has a deliberately narrow surface; new public API requires alignment.

### 2. Branch

Create a topic branch off `main`:

```bash
git checkout -b fix/initialize-handshake     # or feat/, refactor/, docs/, test/, chore/
```

We don't enforce a strict naming scheme, but match the type to the Conventional Commits prefix you'll use.

### 3. Make your change

Read [ARCHITECTURE.md](ARCHITECTURE.md) before touching anything in `src/Core/`. The transport contract and the SSE parser are the load-bearing pieces — small changes there ripple everywhere.

Specific rule files for the area you're working in:

- Adding/modifying a transport → [.claude/rules/transporters.md](.claude/rules/transporters.md)
- Anything spec-related (`initialize`, JSON-RPC envelope, error codes) → [.claude/rules/mcp-spec.md](.claude/rules/mcp-spec.md)
- Anything test-related → [.claude/rules/testing.md](.claude/rules/testing.md)

### 4. Add tests

Every change needs at least one test. Concretely:

- **New public API** → at least one happy-path test and one error-path test.
- **Bug fix** → a regression test that fails on `main` and passes on your branch.
- **Refactor** → existing tests must continue to pass; add a new test if the refactor uncovers an untested seam.

Pest is the framework. Tests run against Orchestra Testbench. Mock Guzzle with `MockHandler`. Don't introduce real network or real subprocesses. See [.claude/rules/testing.md](.claude/rules/testing.md) for the full set of testing rules.

### 5. Run the local gate

Before you push, run the same checks CI runs:

```bash
bin/check
```

This runs Pint → PHPStan → Pest in sequence. Any failure should be fixed before you push.

You can also run them individually:

```bash
composer format       # Pint (fixes style)
composer analyse      # PHPStan level 5
composer test         # Pest
```

### 6. Update CHANGELOG and (if needed) README

- **CHANGELOG.md** — every user-facing change gets an entry under `## Unreleased`, grouped under `Added` / `Changed` / `Fixed` / `Removed`. Internal refactors that don't affect users don't need an entry.
- **README.md** — only update when the public API or configuration shape changes. README is for users; ARCHITECTURE.md and CLAUDE.md are for contributors and agents.
- **ROADMAP.md** — tick off the item you completed (or move it / edit scope if it shifted during implementation).

### 7. Commit

We use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add session-loss recovery to HttpTransporter
fix: handle missing error.message in SseStreamParser
chore: bump phpstan to v2
test: cover initialize handshake on HttpTransporter
refactor: migrate HttpTransporterTest off ReflectionClass helper
docs: clarify $onEvent semantics on STDIO
```

One logical change per commit. If you find yourself writing `feat: X and Y`, split it.

### 8. Open a PR

Push your branch and open a PR against `main`. The PR description should:

- Link the ROADMAP item if applicable (e.g. `Closes ROADMAP P3`).
- Summarize what changed and why — focus on **why**, since the code already shows what.
- Note any spec sections referenced (link them — see [.claude/rules/mcp-spec.md](.claude/rules/mcp-spec.md)).
- Include a brief test plan: which scenarios you covered, anything you couldn't test and why.

CI runs Pint, PHPStan, and Pest. All three must be green before review.

---

## Code style

- `declare(strict_types=1);` at the top of every new PHP file. Add it when you touch an older file that doesn't have it.
- Constructor injection only — no `app()`, `resolve()`, or facades inside core services.
- `config()` is read **only** in `MCPClientServiceProvider` and `config/mcp-client.php`. Transporters and services receive config via constructor.
- Throw `TransporterRequestException` for any transport-layer failure. Throw `ServerConfigurationException` for invalid config shapes. Don't leak raw `GuzzleException` or `JsonException` past the transport boundary.
- Return types and parameter types on every public method.
- No `dd()`, `dump()`, `ray()`, or `var_dump()` in committed code. The arch test in `tests/ArchTest.php` will fail.
- Pint enforces formatting — let it. Don't hand-roll style choices.

---

## Reporting bugs

Open a GitHub issue with:

- The Laravel version, PHP version, and package version
- The transport (`http` or `stdio`) and the relevant config block (redact tokens)
- A minimal reproduction — ideally a failing test
- The actual exception message and stack trace

For HTTP issues, the response Content-Type and a sanitized request/response body help a lot.

---

## Reporting security vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities. **Don't open public issues for security problems.**

---

## Releasing (maintainers only)

1. Move everything under `## Unreleased` in `CHANGELOG.md` to a new `## [x.y.z] - YYYY-MM-DD` heading.
2. Tag the commit: `git tag -a vX.Y.Z -m "vX.Y.Z"`.
3. Push the tag: `git push origin vX.Y.Z`. Packagist picks it up automatically.
4. Create a GitHub Release pointing at the tag with the changelog excerpt as the body.

Versioning follows [SemVer](https://semver.org/). The package is pre-1.0; minor versions can include breaking changes if necessary, but flag them clearly in the changelog.

---

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE.md).
