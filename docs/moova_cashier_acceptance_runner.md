# Moova Cashier Acceptance Runner

`tools/moova_cashier_acceptance_runner.php` creates repeatable local Moova acceptance evidence for branch go-live rehearsal.

It runs:

- the live local topology check against the configured POS and Moova URLs
- the two-mock-server Moova/POS reachability smoke, including queued new/edit/cancel markers, POS drop/recovery, and Moova drop/recovery

## Command

```bash
php tools/moova_cashier_acceptance_runner.php \
  --output=/absolute/path/to/moova-cashier-acceptance.md \
  --pos-url=http://127.0.0.1:8010/index.php \
  --moova-url=http://127.0.0.1:3001 \
  --branch-uuid=00000000-0000-4000-8000-000000000000 \
  --operator="cashier name" \
  --backup-file=/absolute/path/to/posmain-backup.sql
```

JSON mode:

```bash
php tools/moova_cashier_acceptance_runner.php --output=/tmp/posmain-moova-acceptance.md --json
```

For CI or isolated local smoke rehearsal without live POS/Moova ports:

```bash
php tools/moova_cashier_acceptance_runner.php --skip-live-topology --output=/tmp/posmain-moova-acceptance.md --json
```

## Evidence Markers

When all simulated scenarios pass, the generated file includes the markers required by `tools/branch_go_live_readiness.php`:

```text
queued_new_order=pass
queued_edit_order=pass
queued_cancel_order=pass
pos_drop_recovery=pass
moova_drop_recovery=pass
```

If a scenario fails, that marker is written as `=fail`, so readiness will not pass accidentally.

## Boundary

The generated evidence is `local_mock_backed_acceptance`. It is useful before go-live and for regression rehearsal, but it does not replace final real-shop hosted cashier acceptance.

The runner does not mutate POS data, queue rows, service files, live Moova state, or cashier UI.
