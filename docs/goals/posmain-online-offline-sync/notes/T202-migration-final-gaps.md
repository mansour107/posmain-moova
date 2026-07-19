# T202 final migration CLI gaps

## Outcome

The migration CLI now separates read-only discovery from executable authorization:

- `--dry-run --scope=additive` may omit labels and reports all additive candidates plus deferred statements.
- `--apply --scope=additive` fails immediately unless `--labels=` contains an explicit reviewed allowlist.
- The missing-label failure occurs before database connection, tracking-table upgrade, or business-schema mutation.
- `UPDATE` remains classified as a rewrite but again requires the pre-T200 explicit `--allow-destructive` opt-in as well as a readable backup. Other rewrite forms retain the backup gate without unintentionally gaining that extra opt-in.

No hosted access or mutation occurred.

## Verification

- PHP lint passed for the planner, runner, and focused test.
- `php tests/sync/sync_migration_plan_test.php` passed, including disposable-MySQL proof that additive discovery without labels succeeds, additive apply without labels fails before tracking mutation, labels-explicit apply creates only the selected table, deferred schema remains absent, and checksum mismatch stops before the selected business-schema write.
- `php tools/phpunit.phar tests/sync/sync_schema_migration_test.php` passed: 11 tests, 243 assertions.
- `git diff --check` and the Goal Maker checker passed.

## Regression avoidance

- Full-scope CLI behavior is unchanged.
- Additive discovery remains usable without guessing labels.
- Additive execution cannot broaden itself from a reviewed subset to all additive candidates.
- Existing UPDATE operator authorization is preserved.
- Production reverse-sync policy and guarded manual restore were not changed.
