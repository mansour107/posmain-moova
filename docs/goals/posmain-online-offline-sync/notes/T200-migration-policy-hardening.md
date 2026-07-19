# T200 migration and reverse-sync policy hardening

## Outcome

The local release tooling now supports a reviewed additive-only migration scope without permitting deferred statements to fall through to the whole-schema apply path. Production configuration also forces both automatic hosted-to-branch mechanisms off, while the existing guarded manual restore CLI remains independent.

No hosted system, hosted database, service, queue, or staged artifact was accessed or changed by this task.

## Impacted surfaces mapped

- Migration CLI contract: the existing `--dry-run` and `--apply` full-scope behavior remains the default; `--scope=additive` and optional `--labels=` are additive options.
- Database access: subset execution uses only the selected statements, upgrades compatible legacy migration ledgers, and verifies every selected checksum before the first selected business-schema statement.
- State/order: selected and deferred labels are explicit; an unknown, duplicated, or non-additive requested label fails closed.
- Production configuration: automatic cloud pull and automatic reverse publish both resolve false for branch and cloud roles, even if stale environment input requests them.
- Manual recovery: the guarded branch restore/export commands are not coupled to the automatic flags and remain available.

## Compatibility and regression controls

- Full scope remains the compatibility default for existing callers.
- Additive subset scope never invokes `SyncSchemaManager::apply()`, so no deferred rewrite, destructive, or ambiguous statement can execute indirectly.
- `UPDATE`, `INSERT`, `REPLACE`, and `ALTER ... MODIFY|CHANGE|RENAME` are classified as rewrites; destructive DDL/DML is classified separately; unknown SQL is ambiguous.
- Production or rewrite/destructive execution still requires a readable backup, and destructive execution still requires the explicit destructive opt-in.
- Legacy ledgers with the observed required columns and `metadata_json` but without `status` are upgraded in place. Missing required identity/checksum columns are rejected.
- Checksum compatibility is preflighted for every selected label before any selected schema write, preventing partial execution caused by a later mismatch.
- The T198 staged artifact predates this correction and is not promotable.

## Verification evidence

- PHP lint passed for all five changed PHP files.
- `php tests/sync/sync_migration_plan_test.php` passed pure planner contracts and a disposable-MySQL runtime proof:
  - one allowlisted additive statement applied alone;
  - a deferred additive table remained absent;
  - the legacy ledger gained `status` without losing `metadata_json`;
  - an intentionally wrong checksum exited nonzero before the selected business table was created.
- `php tests/sync/production_sync_profile_test.php` passed for branch/cloud forced-false automatic reverse flags and independent manual restore entry points.
- `php tools/phpunit.phar tests/sync/sync_schema_migration_test.php` passed: 11 tests, 243 assertions.
- `php tests/sync/branch_restore_safety_test.php` passed.
- `php tests/sync/branch_restore_export_safety_test.php` passed.
- `git diff --check` passed.

## Next safe decision

Before any hosted apply or promotion, a Judge should review this exact correction and authorize only a newly built manifest-bound artifact, a fresh all-tenant additive-scoped dry-run, and a disposable restore rehearsal. The old T198 artifact must remain isolated.
