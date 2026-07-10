# Local PIN Authentication — Operations Runbook

## Modes

| Deployment | `POSMAIN_MAIN_AUTH_MODE` | Main login |
|---|---|---|
| Branch / local Docker | `pin` | 4-digit PIN only |
| Cloud / router | `password` (forced) | Username + password |

Never infer mode from hostname. Unsafe `pin` + cloud/router must fail startup.

Required secret: `POSMAIN_PIN_SECRET` (HMAC lookup). Never store it in the database.
Back it up in the deployment secret store. Rotating or losing it invalidates every
`pin_lookup`; perform a controlled PIN reset/re-enrollment campaign before changing it.

## First-run bootstrap

1. Fresh local schema seeds owner bootstrap PIN `0000` while `security_bootstrap_state.status = pending`.
2. Login with `0000` opens a **restricted** session: only change PIN / lock / logout.
3. Completing `change_pin.php` sets the real owner PIN, marks bootstrap complete, bumps `auth_version`, and routes by role.
4. `0000` cannot be assigned after bootstrap.

## Daily cashier flow (local PIN)

1. Enter 4-digit PIN on `index.php`.
2. Cashier is routed into POS (`pos_barcode.php`) **without a second PIN**.
3. `ShiftEntryService` decides:
   - resume own current-business-day shift on this register
   - opening count
   - stale previous-business-day shift → must close first
   - cross-register open shift → manager-approved transfer
   - another cashier on register → blocked / takeover
4. Lock / logout clears identity but **does not** close the durable drawer.

## Register pairing

- Browser holds HttpOnly `posmain_register_token`.
- First local terminal can create/pair `REG1`.
- Re-pairing an existing register requires manager/owner PIN on `register_pair.php`.
- Lost/revoked pairing returns to the pairing screen (never trust a submitted register id).

## Manager recovery

- **Handover / force-close:** existing shift takeover + manager override PIN.
- **Cross-register transfer:** `do/do_transfer_drawer_register.php` after `ajax/pos_override_auth.php` approval for `pos.shift.force_close`.
- **PIN reset (Team Hub):** generates temporary PIN, shows once, forces change, bumps `auth_version` (revokes sessions).
- **Owner recovery CLI (console only):**

```bash
php scripts/recover_owner_pin.php --pin=<temporary-4-digit>
# Optional: --user-id=<id>  |  --force (when MAIN_AUTH_MODE is not pin)
```

## Preflight / rollback

Preflight and the local PIN pack (same commands the release gate runs):

```bash
php scripts/local_pin_auth_preflight.php --json
bash scripts/run_local_pin_auth_pack.sh
```

Expect preflight JSON `ok: true`. The pack includes `tests/sync/local_pin_auth_contract_test.php` plus related auth/shift contracts — do not treat a single contract file as a full gate substitute.

Local release-gate equivalents (see sign-off checklist for the full list):

```bash
bash scripts/run_security_contract_pack.sh
bash scripts/run_business_day_pack.sh
bash scripts/run_cash_flow_pack.sh
vendor/bin/phpunit --filter ProductionRbacPinTest --fail-on-skipped tests/security/ProductionRbacPinTest.php
POSMAIN_TEST_HTTP_BASE=http://127.0.0.1:8010 npm run test:e2e:local-pin
```

### Immediate rollback

Schema stays backward-compatible. Preferred rollback:

```bash
# Force password main auth (hosted/cloud/router already force password).
export POSMAIN_MAIN_AUTH_MODE=password
# Restart PHP / container so config is re-read.
```

Then confirm:

- Login UI shows username/password (not the main PIN pad).
- Existing sessions are acceptable, or force re-login / lock.
- `POSMAIN_PIN_SECRET` remains present if PIN override / Team Hub PIN flows are still used.
- Do **not** drop PIN columns as part of emergency rollback.
- Record rollback time, operator, and git SHA in the evidence file.

Owner PIN recovery (`php scripts/recover_owner_pin.php --pin=<temporary-4-digit>`) is console-only and is **not** a substitute for mode rollback.

Full rollback/sign-off checklist: [`docs/local_pin_release_signoff_checklist.md`](./local_pin_release_signoff_checklist.md).

## Pilot evidence checklist

Full templates (manual + machine-readable) and rollback/sign-off:

- [`docs/local_pin_pilot_evidence_template.md`](./local_pin_pilot_evidence_template.md)
- [`docs/local_pin_pilot_evidence.template.json`](./local_pin_pilot_evidence.template.json)
- [`docs/local_pin_release_signoff_checklist.md`](./local_pin_release_signoff_checklist.md)

Release-gate CI: `.github/workflows/local-pin-release-gate.yml` (`packs-and-security`, `phpstan-local-pin`, `e2e-local-pin`; hosted-password only when fixtures are configured).

- [ ] Backup taken
- [ ] Preflight OK (`php scripts/local_pin_auth_preflight.php --json` → `ok: true`)
- [ ] Bootstrap PIN change completed
- [ ] Cashier open → sale → close reconciles
- [ ] Lock/relogin resumes same drawer
- [ ] Cross-register transfer audited
- [ ] Hosted password login still works on cloud/router
- [ ] RBAC denials for cashier/kitchen on dashboard widgets / KDS manage
- [ ] Rollback rehearsed or documented (mode flip + restart; do not drop PIN columns)
- [ ] Sign-off recorded
