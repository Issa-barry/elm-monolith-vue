---
name: run-quality
description: Execute format, lint, unit, integration, and e2e checks; fix failures and rerun until everything is green. Use when the user asks for a full quality pass or CI stabilization.
disable-model-invocation: true
argument-hint: "[scope optionnel]"
---

Objective: make all quality checks pass without asking for intermediate confirmation.

Rules:
1. Do not stop at first failure.
2. Fix issues, then rerun until success or hard external blocker.
3. Prefer project-native scripts and commands.
4. Keep changes minimal, targeted, and deterministic.
5. Do not ask the user to validate each step.

Execution order:
1. Discover available commands from:
   - `package.json` scripts
   - `composer.json` scripts
   - `Makefile`
   - framework CLIs (`nx`, `turbo`, `vite`, `php artisan`, etc.)
2. Build and run this pipeline when available:
   - format
   - lint
   - unit tests
   - integration tests
   - e2e tests
3. If `$ARGUMENTS` is provided, narrow execution scope to those paths/modules while keeping equivalent coverage.
4. On failure:
   - identify root cause from logs
   - patch code, tests, or config as needed
   - rerun failed stage, then rerun impacted downstream stages
5. Repeat until all discovered checks pass.

When to stop:
- Stop only when every discovered check is green, or when blocked by a true external dependency (missing secret, unreachable external service, permission boundary, unavailable environment).

Final response format (strict):
1) Final status by check (OK/KO)
2) Commands executed
3) Files modified
4) Fixes applied
5) Remaining blockers (if any)
