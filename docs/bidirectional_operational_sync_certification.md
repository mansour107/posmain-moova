# Lean Offline/Cloud Sync Certification

This certification is the disposable release gate for POSMAIN's asymmetric offline-first sync contract:

- Automatic branch to hosted: committed branch events enter the durable outbox and the worker retries until the hosted inbox accepts and projects them.
- Manual guarded hosted to branch: an operator may restore into a blank recovery database only after a fresh dry-run, stopped-worker acknowledgement, readable backup evidence, exact manifest, expected count, and branch-scoped confirmation token.
- Automatic hosted-to-branch polling remains disabled.

## Run

Use the existing local Docker MySQL service. The command creates three PID-scoped databases, starts temporary local hosted HTTP processes, writes a JSON report, and drops the databases on every normal or failed exit.

```bash
php tools/e2e_bidirectional_operational_sync.php
```

For diagnosis only, `--keep-databases` preserves the three disposable databases named in the report. They must never be production database names and should be dropped after inspection.

Run the static wiring gate separately:

```bash
php tests/sync/e2e_bidirectional_operational_sync_contract_test.php
```

## What must pass

The report's `disposable_certification_pass` is true only when all of these are true:

1. Representative catalog, table, order, customer/delivery, configuration, Moova-link metadata, and shift-close data reach hosted storage.
2. A new change is delivered by the automatic worker.
3. A hosted outage leaves the event retryable; restart delivers it, clears its lease, and leaves no pending, syncing, failed, dead, or expired-lease rows.
4. Exact replay is idempotent, an older order revision is rejected, and changed content at the same revision is held as a conflict without replacing the newer projection.
5. Restore apply is refused on the active non-empty branch.
6. The same hosted ledger restores into an empty third database only through the manual safety contract.
7. Canonical branch, hosted, and recovery hashes agree for every selected fixture.
8. Passwords, token hashes, sessions, worker runtime state, cloud pairing rows, and other excluded data do not enter the recovery result.

The report also lists focused typed-family gates for money/refunds, customers, fulfillment, inventory accounting, counts, production, procurement, and shifts. Run those suites for a release; the disposable seed is representative transport/recovery proof, not a replacement for every typed aggregate test.

## Meaning of the result

A green disposable report proves local code behavior and cleanup. It does not certify live production by itself. `production_ready` intentionally remains false until operators separately prove hosted code/schema parity, backups, secret installation, service supervision, branch pairing, live authenticated smoke tests, monitoring, and staged rollback readiness.

A red report is a release blocker. Do not deploy, replay queues, enable reverse polling, or restore over an active branch to make it green. Fix the reported contract failure and rerun from fresh disposable databases.

The following remain explicit non-claims until separately designed or audited:

- cross-branch transfer document custody and acknowledgement;
- manual legacy journal writers that could duplicate typed money/inventory events;
- unevenly wired operational master writers;
- secret-free user/RBAC recovery;
- live Hetzner deployment and service evidence.

## Cleanup verification

Without `--keep-databases`, no database matching `posmain_e2e_bsync_(branch|cloud|recovery)_<pid>` should remain, and every temporary hosted process must be reaped. The harness only drops names matching that exact disposable pattern and never writes branch fixtures to the source schema used for cloning.
