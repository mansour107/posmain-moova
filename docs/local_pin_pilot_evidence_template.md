# Local PIN pilot evidence template

Use this template for a branch/local PIN pilot. Copy to a dated evidence file (for example `docs/local_pin_pilot_evidence-YYYY-MM-DD.md`) and fill only after each check is actually performed.

Machine-readable twin: [`local_pin_pilot_evidence.template.json`](./local_pin_pilot_evidence.template.json).

Only replace `pending` with a real reviewed result. Do **not** mark pass for checks that were skipped, blocked, or not run.

## Header

| Field | Value |
|---|---|
| Generated at UTC | pending |
| Evidence completed at UTC | pending |
| Operator | pending |
| Shop / label | pending |
| POS tenant / branch | pending |
| Deployment role | `branch` (local PIN) |
| `POSMAIN_MAIN_AUTH_MODE` | `pin` |
| App base URL | pending |
| Git SHA | pending |
| Backup ref | pending |

## Markers

- Local PIN Pilot Evidence: pending
- Backup taken: pending
- Schema migrated or verified: pending
- Local PIN preflight OK: pending
- Bootstrap PIN change completed: pending
- Cashier open → sale → close reconciles: pending
- Lock/relogin resumes same drawer: pending
- Cross-register transfer audited: pending
- Hosted password login still works: pending
- RBAC denials verified: pending
- Rollback path documented: pending
- Release-gate CI green: pending

## Evidence details

- Backup evidence: pending
- Schema evidence: pending — `php tools/run_migrations.php --dry-run`
- Preflight evidence: pending — `php scripts/local_pin_auth_preflight.php --json`
- Bootstrap PIN change evidence: pending
- Cashier open/sale/close evidence: pending
- Lock/relogin drawer resume evidence: pending
- Cross-register transfer audit evidence: pending
- Hosted password login evidence: pending (cloud/router only; not local PIN mode)
- RBAC denial evidence: pending (cashier/kitchen dashboard widgets / KDS manage)
- Rollback evidence: pending — `POSMAIN_MAIN_AUTH_MODE=password`, restart PHP/container, do not drop PIN columns; keep `POSMAIN_PIN_SECRET` if override PIN flows remain
- Release-gate evidence: pending — `.github/workflows/local-pin-release-gate.yml` (`packs-and-security`, `phpstan-local-pin`, `e2e-local-pin`)

## Command hints (do not count as completed evidence)

```bash
php tools/run_migrations.php --dry-run
php scripts/local_pin_auth_preflight.php --json
bash scripts/run_local_pin_auth_pack.sh
bash scripts/run_security_contract_pack.sh
bash scripts/run_business_day_pack.sh
bash scripts/run_cash_flow_pack.sh
vendor/bin/phpunit --filter ProductionRbacPinTest --fail-on-skipped tests/security/ProductionRbacPinTest.php
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G
POSMAIN_TEST_HTTP_BASE=http://127.0.0.1:8010 npm run test:e2e:local-pin
# Hosted password campaign requires a real hosted fixture base + credentials:
POSMAIN_TEST_HTTP_BASE=<hosted-base> npm run test:e2e:hosted-password
# Immediate rollback (not a schema drop):
export POSMAIN_MAIN_AUTH_MODE=password
# then restart PHP / container
```

## Operator QA checklist

- [ ] Backup taken
- [ ] Preflight OK
- [ ] Bootstrap PIN change completed
- [ ] Cashier open → sale → close reconciles
- [ ] Lock/relogin resumes same drawer
- [ ] Cross-register transfer audited
- [ ] Hosted password login still works on cloud/router
- [ ] RBAC denials for cashier/kitchen on dashboard widgets / KDS manage
- [ ] Rollback rehearsed or documented
- [ ] Sign-off recorded (see [`local_pin_release_signoff_checklist.md`](./local_pin_release_signoff_checklist.md))

## Blockers

List any missing fixtures, failed packs, PHPStan findings, or CI skips here. An empty list is allowed only when every marker above is a real pass.

-
