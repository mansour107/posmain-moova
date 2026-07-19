# T201 pre-restaging Judge decision

## Decision

Do not re-stage or access the hosted system yet. T200 fixed the principal subset fall-through, checksum ordering, legacy-ledger, and production reverse-sync-policy risks, and its focused/adjacent tests are sound. Two small fail-closed gaps remain in the migration CLI and should be corrected locally first.

## Compatibility review

- Existing full-scope `--dry-run` remains output-compatible and still reports every pending statement.
- Existing full-scope `--apply` still executes the original pending plan and retains the manager's post-migration role seeding.
- Additive subset execution cannot invoke `SyncSchemaManager::apply()` and therefore cannot execute a deferred statement through the all-pending path.
- Every selected checksum is checked before the first selected business-schema statement.
- The observed legacy ledger can receive `status` without losing `metadata_json`; structurally incompatible ledgers fail closed.
- The production profile overrides stale true values for both automatic cloud-pull paths, while `tools/restore_branch_from_hosted.php` remains an explicit independent manual operation.

## Remaining gaps

1. `--apply --scope=additive` currently permits omission of `--labels`, which selects every statement classified additive. That is useful for discovery during dry-run, but an apply is not a reviewed subset unless an explicit allowlist is mandatory.
2. Before T200, `UPDATE` triggered both the backup requirement and `--allow-destructive`. T200 correctly classifies it as a rewrite, but unintentionally removed the explicit destructive opt-in for existing full-scope callers. Preserve that conservative compatibility behavior for `UPDATE` without conflating every rewrite DDL with destructive DML.

## Authorized next sequence after local correction

The next hosted Worker may perform only these reversible pre-promotion actions:

1. Build a new manifest-bound artifact from the then-current local state; never reuse T198 staging.
2. Verify one T198 tenant backup by restoring it into a newly named disposable database, compare structural/table-count evidence, then delete only that disposable database.
3. Run the new runner in additive discovery dry-run mode for router and every active tenant.
4. Derive an explicit label allowlist by mapping required runtime classes/domains to exact pending labels and dependencies; do not infer it from names alone.
5. Re-run a labels-explicit additive dry-run on every active tenant and stop. No apply, current-root change, service restart, queue action, or config mutation.

Only a later Judge may consider schema apply or code promotion from that evidence.
