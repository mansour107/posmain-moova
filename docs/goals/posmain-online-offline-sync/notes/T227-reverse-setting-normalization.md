# T227 reverse-setting normalization

## Result

Pass. Both Focus House databases now store the two automatic reverse-direction settings as explicit non-secret zero values. The running shop-to-cloud service was not interrupted and forward queues/projections did not change.

## Exact mutation

`SyncRuntimeSettings::savePartial()` ran inside one transaction per database with only:

- `POSMAIN_CLOUD_PULL_ENABLED=0`
- `POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED=0`

The helper asserted database name `focushouse`, exact saved-key order, zero readback and non-secret storage before commit. No direct SQL mutation was used.

## Preservation evidence

- Local non-reverse settings: 8 rows, fingerprint unchanged at `509f1528f5be6b4f0e92b617884910dd1921783dcf5fed90dbc6492bb824b592`.
- Hosted non-reverse settings: 3 rows, fingerprint unchanged at `065447070c9767f90dbc00b64c1bdc4ce834958ecb3563ae44dd906b2c7a2e93`.
- Local queue remains 47,837 synced, zero pending/failed and seven dead/superseded.
- Hosted branch inbox count remains 47,837 and guarded table projection cursor count remains nine.
- Continuous service remains running at PID 60838, run two, with healthy empty cycle 60, zero failures, zero schema pending, zero warnings and resolved cloud pull false.
- Temporary local and hosted normalization helpers were deleted.

The system now has three aligned safety layers: raw runtime values off, isolated worker profile off, and production profile enforcement off.
