# Branch Protection Policy

## Goal

- Allow direct push only to `feature/*` branches.
- Optionally allow direct push to `dev` when team agrees.
- Forbid direct push to `main` and `pre-prod`.

## GitHub (UI)

1. Open repository `Settings` -> `Branches`.
2. Create a ruleset (or branch protection rule) targeting `main` and `pre-prod`.
3. Enable:
   - Require a pull request before merging
   - Require at least 1 approval
   - Require conversation resolution before merging
   - Block force pushes
   - Block branch deletion
4. Set bypass to none (or only a very small admin group if required by policy).

Optional for `dev`:

1. Create a separate rule for `dev`.
2. Keep PR required, or allow controlled push only for designated maintainers.

## GitLab (UI)

1. Open repository `Settings` -> `Repository` -> `Protected branches`.
2. Protect `main` and `pre-prod` with:
   - Allowed to push: `No one`
   - Allowed to merge: `Maintainers` (or your protected role)
3. Ensure force push is disabled.

Optional for `dev`:

1. Protect `dev` with team-agreed policy.
2. Example:
   - Allowed to push: `Maintainers`
   - Allowed to merge: `Maintainers`

