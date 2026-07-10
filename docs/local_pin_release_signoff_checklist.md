# Local PIN release — rollback and sign-off checklist

Use with [`local_pin_pilot_evidence_template.md`](./local_pin_pilot_evidence_template.md) and [`local_pin_pilot_evidence.template.json`](./local_pin_pilot_evidence.template.json). Do not sign off while any release-gate job is red, skipped for missing DB, or blocked on absent hosted fixtures.

## Pre-release gate (CI / local equivalent)

- [ ] `.github/workflows/local-pin-release-gate.yml` required jobs green for this SHA: `packs-and-security`, `phpstan-local-pin`, `e2e-local-pin` (hosted-password only when fixtures exist; skipped ≠ pass)
- [ ] Schema import + `php tools/run_migrations.php --apply --confirm-no-backup` (CI) / dry-run clean on target (`php tools/run_migrations.php --dry-run` → `0 pending sync schema change(s)`)
- [ ] `php scripts/local_pin_auth_preflight.php --json` → `ok: true`
- [ ] `bash scripts/run_security_contract_pack.sh`
- [ ] `bash scripts/run_local_pin_auth_pack.sh`
- [ ] `bash scripts/run_business_day_pack.sh`
- [ ] `bash scripts/run_cash_flow_pack.sh`
- [ ] `vendor/bin/phpunit --filter ProductionRbacPinTest --fail-on-skipped tests/security/ProductionRbacPinTest.php` (zero silent DB skips)
- [ ] PHPStan on expanded `phpstan.neon.dist` paths (no invented baseline / ignoreErrors): `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G`
- [ ] `POSMAIN_TEST_HTTP_BASE=http://127.0.0.1:8010 npm run test:e2e:local-pin` against a live local HTTP base (script fails closed if the base URL is unset)
- [ ] Hosted-password campaign: either ran via `POSMAIN_TEST_HTTP_BASE=<hosted-base> npm run test:e2e:hosted-password` (or `POSMAIN_HOSTED_E2E_BASE`) with real admin credentials, **or** explicitly deferred with blocker noted (do not claim pass)

## Rollback (immediate)

Schema stays backward-compatible. Preferred rollback:

```bash
# Force password main auth (hosted/cloud/router already force password).
export POSMAIN_MAIN_AUTH_MODE=password
# Restart PHP / container so config is re-read.
```

Additional rollback notes:

- [ ] Confirm login UI shows username/password (not main PIN pad)
- [ ] Confirm existing sessions are acceptable or force re-login / lock
- [ ] Confirm `POSMAIN_PIN_SECRET` is still present if PIN override flows remain in use
- [ ] Do **not** drop PIN columns as part of emergency rollback
- [ ] Record rollback time, operator, and git SHA in the evidence file

Owner PIN recovery (console only, not a substitute for mode rollback):

```bash
php scripts/recover_owner_pin.php --pin=<temporary-4-digit>
```

## Pilot sign-off

| Item | Status | Initials / date |
|---|---|---|
| Backup ref recorded | | |
| Evidence markers completed (no pending pass claims) | | |
| Operator QA checklist complete | | |
| Rollback path rehearsed or documented | | |
| Release-gate CI green for this SHA | | |
| Known blockers listed (or none) | | |
| Ready for controlled pilot | ☐ yes ☐ no | |

**Signed by:** ______________________  
**Role:** ______________________  
**UTC datetime:** ______________________  
**Git SHA:** ______________________  

## Hosted-password fixture prerequisites (explicit)

The hosted-password Playwright job / `npm run test:e2e:hosted-password` must **not** be treated as green when fixtures are absent.

Required:

- Repository variable `POSMAIN_HOSTED_E2E_BASE` (or env `POSMAIN_TEST_HTTP_BASE` / `POSMAIN_HOSTED_E2E_BASE`)
- Secrets/env for at least admin: `POSMAIN_E2E_USER_ADMIN` and `POSMAIN_E2E_PASS_ADMIN` (or `POSMAIN_E2E_DEMO_PASSWORD`)
- Optional persona secrets for manager/cashier as needed by the selected specs
- `POSMAIN_E2E_SKIP_IF_DOWN=0` so a down host fails instead of skipping

If any prerequisite is missing: record a blocker and skip the campaign — do not claim pass.
