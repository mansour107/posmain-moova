# T214 reviewed exact-label migration scope

## Result

Added a `reviewed` migration scope for an exact operator-approved label set. This removes the last reason to use a broad unlabeled production rewrite command.

## Contract

- `--scope=reviewed` requires a nonempty `--labels=` list for dry-run and apply before database connection.
- Unknown, duplicate, and ambiguous labels fail closed.
- Reviewed additive, rewrite, and destructive labels are selected only when named, in the planner's canonical pending order.
- Unrequested pending statements remain deferred and are listed in dry-run output.
- Destructive statements still require the existing `--allow-destructive` plus readable-backup gate.
- Every rewrite still requires a readable backup.
- All selected checksums are still verified before the first migration-ledger or business-schema write.
- Started/applied ledger recording is unchanged.
- Existing additive discovery and exact-label apply behavior is unchanged.
- Full `scope=all` remains unlabeled and unchanged; it is not needed for the reviewed production sequence.

## Verification

- PHP syntax and scoped diff checks passed.
- Planner coverage proves missing, duplicate, unknown, additive, rewrite, destructive, and ambiguous behavior plus canonical ordering/deferred selection.
- A disposable MySQL runtime test created a legacy `acc_head.balance DECIMAL(10,2)`, proved reviewed dry-run, proved apply without backup fails, applied only `acc_head.modify_balance_decimal24_6` with a readable fixture backup, verified `DECIMAL(24,6)` and applied ledger status, and verified deferred `sync_outbox` was not created.
- Standalone migration-plan contract passed.
- Focused schema/order financial regression suite passed: 25 tests / 395 assertions.

No hosted system was accessed or changed in this Worker.
