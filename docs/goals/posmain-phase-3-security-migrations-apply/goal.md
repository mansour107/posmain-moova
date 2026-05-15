# POSMAIN Phase 3 Security Migration Apply

## Objective

Apply the already-verified Phase 3 additive security migrations for the local POSMAIN database, then test the resulting security/login/audit behavior and iterate on any migration-related failures.

## Goal Kind

`specific`

## Current Tranche

This tranche is complete when the local/dev database at `127.0.0.1:3307` has the Phase 3 `security_audit_log` and `failed_login_attempts` tables applied, the migration runner reports no pending changes, focused Phase 3 security tests pass against the migrated DB, and a final audit receipt records the exact target and residual risks.

## Non-Negotiable Constraints

- Use the completed Phase 3 board at `docs/goals/posmain-phase-3-security-hardening` as the source of truth for desired migrations.
- Apply only to the verified local/dev database target unless the user explicitly names another environment.
- Take a backup first when the local tooling can do it.
- Do not mix unrelated dirty worktree changes.
- Do not alter implementation files unless a migration failure proves a code issue and a new Worker task explicitly allows it.
- Stop before any destructive migration, production credential, remote host, or ambiguous DB target.

## Stop Rule

Stop when the migrated local DB passes verification, or when applying/testing is blocked by missing tooling, DB availability, destructive SQL, or an unsafe/ambiguous target.

## Canonical Board

Machine truth lives at:

`docs/goals/posmain-phase-3-security-migrations-apply/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins.

## Run Command

```text
/goal Follow docs/goals/posmain-phase-3-security-migrations-apply/goal.md
```
