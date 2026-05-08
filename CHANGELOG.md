# Changelog

All notable changes to `mcp-client-laravel` will be documented in this file.

## v1.1.1 - 2026-05-08

Backport release. Public API unchanged.

### Added

- Laravel 13 and PHP 8.5 support. `composer.json` now allows `illuminate/contracts: ^10.0||^11.0||^12.0||^13.0`, `php: ^8.3||^8.4||^8.5`, and `orchestra/testbench: ^8.22||^9.0||^10.0||^11.0`. The CI matrix runs PHP 8.3/8.4/8.5 against Laravel 11/12/13 (each with its matching Testbench major), `prefer-lowest` and `prefer-stable`. Larastan pinned to `^3.0`; Pest widened to `^2.0||^3.0||^4.0` (with matching plugin majors); `nunomaduro/collision` widened to include `^9.0`.

### Changed

- `StdioTransporter::handleStartupFailure()` no longer mirrors the failure line through `error_log()` before throwing. The `TransporterRequestException` already carries the command, exit code, stderr, and stdout in its message. Removing the duplicate write keeps Pest 4's risky-output detection happy on the new test stack; user-visible behavior is unchanged.
- Dropped `spatie/laravel-ray` from dev deps. It was never used by the package (the arch test forbids `ray()` calls) and triggered a `BindingResolutionException` during `testbench package:discover` on the new dep tree.
