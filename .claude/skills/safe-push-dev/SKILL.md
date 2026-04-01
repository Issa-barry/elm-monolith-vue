---
name: safe-push-dev
description: Push the dev branch with a controlled one-shot override (SAFE_PUSH_ALLOW_DEV=1) while keeping main and pre-prod blocked by policy.
disable-model-invocation: true
argument-hint: "[remote]"
---

Run a controlled push for dev only.

1. Execute `powershell -NoProfile -ExecutionPolicy Bypass -File .claude/scripts/safe-push-dev.ps1 $ARGUMENTS`.
2. Use `origin` by default when no argument is provided.
3. Never run direct `git push` in this skill.
4. If push fails, report the exact error and the command executed.