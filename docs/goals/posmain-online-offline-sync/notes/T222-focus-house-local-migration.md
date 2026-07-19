# T222 Focus House local migration

## Result

Pass. The existing Focus House database was restore-rehearsed and migrated through exactly ten additive statements followed by exactly eighteen reviewed rewrites. The release planner now reports zero pending migrations. No worker, secret, queue row or hosted endpoint was touched.

## Backup and restore proof

- Pre-worker dump: mode `0600`, 257,130,969 bytes, SHA-256 `2dedf405ec35f126d95cfce02e2f0946bd92581b0e5e9c58d8a6dde775384150`.
- It restored cleanly into disposable database `focushouse_t222_restore`.
- Live and restored databases each had exactly 215 base tables, zero missing/extra tables, exact equal row counts for every table, and four triggers each.
- The disposable database was removed after verification.
- Post-additive dump: mode `0600`, 257,138,770 bytes, matching host/container SHA-256 `2f738408bdd931a6906343af7b6b3ae09d6a49c67c99b2ddfcf0ab5ae7bf4b95`.

## Migration proof

- Immediately before each write phase there were zero other `focushouse` sessions and zero corresponding InnoDB transactions.
- Phase one applied only the exact T221 ten-label additive allowlist.
- The intervening planner then reported exactly eighteen pending rewrites and zero additive statements.
- Phase two applied only the exact T221 eighteen-label reviewed allowlist, without destructive opt-in.
- Final planner result: `pending=0, selected=0, deferred=0`.
- Migration ledger now has 29 rows: one prior two-statement receipt plus the exact 28 new statement receipts.

## Data and integrity proof

- All 218 resulting base tables passed `CHECK TABLE ... QUICK`; zero failures.
- Deterministic normalized value fingerprints remained exactly equal before and after:
  - `acc_head.balance`, 102 rows: `35ee9db26f83ba2a5f947e39be396dbf1988d227d8dfcb27ecb36afa39c8ea95`.
  - `fat_details.profit`, 14,000 rows: `f95cc815513fabafbde0539e58bdbfa0c96a282240dbcceda3eaf2e318014d81`.
  - `ot_head` total/tax/profit, 12,613 rows: `d2dec1a73aaf217abe13e500f2280801416567bc7725764085f6c047f6d52ee6`.
- NULL counts remained zero for those populated columns. Empty local cloud projection tables remained empty.
- Target precision/nullability definitions exactly match the release plan.

## Synchronization isolation proof

- Queue remains exactly 47,828 synced, nine zero-attempt unlocked pending, and seven one-attempt dead/superseded rows.
- Non-synced queue fingerprint remains `b7fc3352ffb10939cc6ca95a74d1ec9d069164ac7280477e5490552ad405a0ec`.
- Branch identity fingerprint remains `02b9d3f52166556c` and cloud URL remains `https://erp.withmoova.com`.
- Automatic cloud pull and reverse publish remain disabled.
- Existing unrelated plist and environment hashes remain exactly unchanged; no Focus House env/plist exists yet.
- Focused migration tests passed: 13 tests and 268 assertions.

No rollback was required. The staged sequence avoided broad SQL, mixed old/new schema sync, silent monetary conversion, queue claims and unrelated-service changes.
