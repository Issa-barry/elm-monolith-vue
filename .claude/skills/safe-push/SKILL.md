---
name: safe-push
description: Push only non-critical branches directly. Allow feature/* by default and allow dev only when SAFE_PUSH_ALLOW_DEV=1. Use this instead of direct git push.
disable-model-invocation: true
argument-hint: "[remote] [branch]"
---

Run protected push policy:

1. Execute `powershell -NoProfile -ExecutionPolicy Bypass -File .claude/scripts/safe-push.ps1 $ARGUMENTS`.
2. Never run direct `git push` when this skill is requested.
3. Respect policy:
   - Allowed: `feature/*`
   - Optional: `dev` only with `SAFE_PUSH_ALLOW_DEV=1`
   - Blocked: `main`, `pre-prod`
4. If blocked, explain why and provide the exact compliant command.
