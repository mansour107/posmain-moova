# Financial certification gate

This gate proves POSMAIN financial invariants against a newly created local
database. It is product-code evidence only; it does not certify or repair a
standing shop's historical records.

Run:

```bash
POSMAIN_FINANCIAL_TEST_DB_HOST=127.0.0.1 \
POSMAIN_FINANCIAL_TEST_DB_PORT=3307 \
scripts/run_financial_certification_gate.sh
```

The runner generates a `posmain_financial_gate_<pid>_<random>` database, applies
the current schema, seeds only certification accounts and active tenders, keeps
tax disabled, runs the financial reconciliation preflight, and drops the whole
fixture on success or failure.

The command fails if the host is not explicitly local, a check is skipped or
not green, reconciliation reports any blocker, or the disposable database
cannot be cleaned up. A separate standing-shop launch requires a sanitized
upgrade-clone run and owner-reviewed treatment of its historical differences.
