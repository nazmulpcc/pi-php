# Repository Guidelines

## Project Structure & Module Organization
This repository is a PHP 8.3 port of the Pi agent. The active PHP implementation currently lives in `packages/agent/`. Production code is under `packages/agent/src`, organized by concern such as `Content/`, `Event/`, `Message/`, and `Tool/`. Tests live in `packages/agent/tests`.

The original TypeScript implementation is cloned under `pi-mono/` and is reference material only. Treat `pi-mono/` as upstream source context, not as part of the PHP runtime. Do not mix PHP implementation work into `pi-mono/`.

Root-level config includes `composer.json`, `phpunit.xml`, and `pint.json`. Notes like `pi.md` and `plan.md` are working documents and may guide implementation, but they are not runtime inputs.

## Build, Test, and Development Commands
Install dependencies with `composer install`.

- `composer test`: runs the full check pipeline, including formatting and Pest tests.
- `composer lint`: formats `packages/agent/src` and `packages/agent/tests` with Pint.
- `composer lint:check`: verifies formatting without changing files.
- `./vendor/bin/pest packages/agent/tests`: runs tests only when you want a faster loop.
- `composer dump-autoload`: refresh Composer autoload mappings after adding, moving, or renaming classes.

Run commands from the repository root so Composer paths resolve correctly.

## Coding Style & Naming Conventions
Follow strict types and PSR-4 namespaces under `Pi\\Agent\\...`. Match the existing layout: one class per file, PascalCase class names, and descriptive suffixes like `*Event`, `*Message`, or `*ToolResult`. Use 4-space indentation and let Laravel Pint enforce formatting.

This codebase is moving toward async behavior with ReactPHP. When changing public APIs in the agent runtime, prefer `React\\Promise\\PromiseInterface` for async boundaries rather than inventing project-local promise abstractions. Keep cancellation separate in the project-owned token types.

Prefer small, typed methods and immutable-style value objects where the codebase already uses them. When porting behavior from `pi-mono`, preserve semantics first and naming second.

## Testing Guidelines
Tests use Pest on top of PHPUnit. Add new tests in `packages/agent/tests` with names ending in `Test.php`; keep `describe()` and `it()` blocks focused on one behavior each.

Cover both success paths and runtime edge cases, especially around:

- message flow
- event ordering
- async listener behavior
- tool execution and tool progress updates
- follow-up and steering queue semantics

When porting from `pi-mono`, add regression tests for any behavior that is easy to accidentally change during translation. Run `composer test` before opening a PR.

## Commit & Pull Request Guidelines
Recent history uses short, imperative commit subjects such as `introduce async with reactphp`. Keep commits focused and avoid bundling refactors with behavioral changes. Pull requests should include a brief description, the reason for the change, test coverage notes, and sample output or event-flow details when behavior changes are hard to infer from code alone.

## Configuration Tips
Do not commit secrets or machine-specific overrides. Dependencies are managed with Composer. Keep `pi-mono/` gitignored and local. If autoloaded classes change, run `composer dump-autoload`.
