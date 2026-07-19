# T199 Pre-promotion Correction Decision

## Decision

Do one local, test-only hardening slice before any new hosted action. Make the migration runner capable of a fail-closed additive subset, harden legacy tracking-table compatibility and pre-apply checksum validation, and force automatic hosted-to-branch pull/reverse publish off in the production profile. Do not apply migrations, rebuild/promote staging, or change live configuration in this slice.

## Root causes

1. `SyncSchemaManager::pendingStatements()` is a whole-application plan. On the four hosted tenants it produces 133–136 statements, including 22–25 rewrites. The runner has no supported subset boundary, so the current command cannot safely apply only reviewed prerequisites.
2. The runner's rewrite detector only catches leading DML/drop/truncate forms. It does not classify `ALTER TABLE ... MODIFY/CHANGE/RENAME`, even though those are the majority of the live rewrite set.
3. A future filtered runner must not fall through to `$manager->apply($conn)`, because that method recomputes and applies every remaining statement. That would silently defeat an additive/allowlisted scope.
4. Checksum compatibility is currently asserted inside the execution loop. A mismatch on a later statement could therefore be discovered after earlier statements were already applied. All selected checksums must be validated before the first business-schema write.
5. The legacy hosted migration ledgers contain `id`, `version`, `filename`, `checksum`, `applied_at`, `applied_by`, and `metadata_json`, but not `status`. The current runner adds `status`, which is enough for this observed shape, but the compatibility path should explicitly ensure every column later used by `syncMigrationRecord()`.
6. Local config defaults reverse pull/publish to false, but an explicit old environment value still resolves pull to true because the production profile does not enforce the manual-recovery policy.

## Why a six-label sync migration is not yet authorized

The newly added sync domains project into authoritative POS tables, not only the inbox JSON journal. Customer snapshots require the customer tables; shift/money snapshots require drawer/session/count/resolution/summary shapes; inventory, production and procurement snapshots require their aggregate tables and revision columns; financial and fulfillment snapshots rely on their related operational tables. The staged application is also 18 commits beyond the hosted base, so promoting the complete artifact after only the six obvious missing sync objects would not certify adjacent application flows.

The correct next boundary is tooling, not a guessed schema allowlist. Once the runner can produce and enforce a classified subset, a later Judge can derive a reviewed additive plan from the protected tenant report and prove staged code behavior against it. Rewrite migrations remain separate and require per-table data/range/null/enum validation plus a maintenance window.

## T200 implementation contract

- Add a small migration-plan classifier/selector used by the CLI and directly unit tested.
- Classify create/add operations as additive; leading DML and `ALTER ... MODIFY/CHANGE/RENAME` as rewrite; drops/truncates/deletes as destructive; unknown SQL as ambiguous.
- Support a dry-run/apply additive boundary that prints selected and deferred counts. Applying that boundary must fail if any selected statement is not additive or any requested label is unknown.
- Preserve the current full-plan behavior for existing callers, but make it structurally impossible for a subset apply to call the manager's all-pending `apply()` path.
- Validate all selected stored checksums before executing the first selected statement.
- Ensure tracking-table columns required by the record/reconcile code are present idempotently, including `status` and `metadata_json`.
- Force `cloud_pull_enabled=false` and `cloud_to_branch_publish_enabled=false` after production-profile resolution. Manual restore commands remain available because they do not depend on automatic pull.
- Add focused tests for classification, subset fail-closed behavior, no fallthrough, legacy tracking shape, checksum preflight ordering and production sync policy.

## Hard limits

T200 is local code/tests only. It may not touch the hosted backup or staging directories, live code/config/services, any database, queue or worker. The next hosted artifact must be rebuilt and re-manifested after T200; the current staged artifact is evidence, not a promotable release.
