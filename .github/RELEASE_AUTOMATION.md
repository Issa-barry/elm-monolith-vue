# Release Automation

This repository now auto-publishes a GitHub Release on every push to `main`.

Workflow file:
- `.github/workflows/auto-release.yml`

## How version bump is selected

The workflow inspects the PR associated with the merged commit on `main`:

1. `release:major` (or `semver:major`, `major`) => major bump
2. `release:minor` (or `semver:minor`, `minor`) => minor bump
3. `release:patch` (or `semver:patch`, `patch`, `hotfix`) => patch bump
4. If no label matches => patch bump (fallback)

It also detects title hints:
- PR title containing `breaking` => major
- PR title starting with `feat:` or `feature:` => minor

## Tag format behavior

The workflow keeps the existing tag style:
- If latest tag is `1.3.0`, next tags stay without prefix (`1.3.1`, `1.4.0`, ...)
- If latest tag is `v1.3.0`, next tags keep `v` prefix (`v1.3.1`, ...)

## What gets created automatically

On `main` push:
1. Next SemVer tag is computed
2. Tag is pushed
3. GitHub Release is created with generated notes

## Team usage (recommended)

Before merging `pre-prod -> main`, add one of these labels to the PR:
- `release:patch`
- `release:minor`
- `release:major`

If omitted, release defaults to patch.
