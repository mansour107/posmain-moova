# T203 reversible re-staging authorization

## Decision

T202 passes the T201 correction gate. Authorize one pre-promotion hosted Worker limited to a fresh isolated artifact, a disposable restore rehearsal, and all-tenant additive discovery plus labels-explicit dry-runs. Do not apply migrations or promote code.

## Evidence

- Additive apply without labels is rejected before database connection; additive dry-run without labels remains available.
- The labels-explicit disposable test applies only its selected statement and leaves deferred schema absent.
- UPDATE again requires explicit destructive opt-in while INSERT/REPLACE and rewrite DDL retain the backup gate.
- All selected checksums remain preflighted before selected business-schema writes.
- Focused runtime proof and the adjacent 11-test/243-assertion schema suite pass.
- Production automatic reverse flags remain forced false and manual restore remains independent.

## Hosted Worker boundary

Permitted:

- create one new timestamped directory under `/var/www/posmain/staging`;
- build and verify a new exact manifest-bound artifact from current local state;
- restore only the small protected `kody2` T198 backup into a uniquely named disposable database, compare exact table/row evidence with the live source read-only, run integrity checks, then drop only that disposable database;
- run read-only additive discovery for the router and all active tenants;
- derive an explicit prerequisite label set from runtime code/schema dependencies, then run labels-explicit additive dry-runs for every active tenant;
- leave protected evidence only inside the new staging directory.

Forbidden:

- migration apply on any active database;
- writes to `/var/www/posmain/current` or live `.env`;
- symlink, nginx, PHP-FPM, MariaDB, worker, queue, credential, or runtime-setting changes;
- reuse/promotion of T198 staging;
- any live HTTP mutation.

The Worker must stop with evidence for a later Judge.
