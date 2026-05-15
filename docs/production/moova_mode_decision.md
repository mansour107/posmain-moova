# POSMAIN Phase 5 Moova Mode Decision

Generated: 2026-05-13

## Decision

Use `direct_widget` as the pilot Moova mode.

For the mid-scale pilot, cashier-reviewed Moova QR/online orders are applied through the local POS widget bridge:

```text
Moova widget -> local POS session -> ajax/moova_confirm_order.php / ajax/moova_change_order.php -> POS mutation service -> local DB
```

Queued worker apply remains disabled by default until worker supervision, status visibility, and cashier acceptance evidence are present.

## Flags

Pilot/default flags:

```dotenv
POSMAIN_MOOVA_MODE=direct_widget
POSMAIN_ENABLE_MOOVA_DIRECT_APPLY=1
POSMAIN_ENABLE_MOOVA_QUEUED_APPLY=0
POSMAIN_MOOVA_APPLY_ENABLED=0
```

Meaning:

- `POSMAIN_MOOVA_MODE=direct_widget`: the official pilot mode is direct widget apply.
- `POSMAIN_ENABLE_MOOVA_DIRECT_APPLY=1`: direct widget apply is intentionally allowed.
- `POSMAIN_ENABLE_MOOVA_QUEUED_APPLY=0`: queued apply is not part of the initial pilot mode.
- `POSMAIN_MOOVA_APPLY_ENABLED=0`: the branch worker must not automatically apply queued Moova events yet.

Future modes:

- `disabled`: Moova apply is not enabled.
- `queued_worker`: queued/poller apply is allowed only when `POSMAIN_MOOVA_APPLY_ENABLED=1`.
- `hybrid`: direct widget apply is allowed and queued apply can be enabled separately with `POSMAIN_MOOVA_APPLY_ENABLED=1`.

## Safety Checklist

Impacted surfaces:

- Config: `config/app_config.php`, `.env.example`, branch-worker env templates.
- Active Moova endpoints: `ajax/moova_confirm_order.php`, `ajax/moova_change_order.php`.
- Queued worker: `classes/Sync/BranchMoovaApplyWorker.php`.
- Readiness tooling: `tools/branch_go_live_readiness.php`.

Compatibility risks:

- Enabling queued worker apply before cashier acceptance can mutate POS orders without a cashier-visible review loop.
- Direct and queued delivery of the same provider event must share idempotency before hybrid mode is used.
- Cloud or Moova downtime must not block local cashier service.

Current controls:

- The direct widget path still requires an active POS session, a mapped device token, tenant/branch scope match, cashier confirmation for changes, and idempotency keys.
- The queued worker path stays off unless `POSMAIN_MOOVA_APPLY_ENABLED=1`.
- Existing readiness tooling requires cashier acceptance evidence when queued automatic apply is enabled.

## Phase 5 Follow-Up

Before enabling `queued_worker` or `hybrid` in a real branch:

1. Prove direct-vs-queued duplicate delivery creates one POS mutation.
2. Run the Moova cashier acceptance runner and keep the completed evidence file outside git.
3. Verify worker status and Moova pending apply counts from the branch worker dashboard/tooling.
4. Confirm token visibility is permission-gated and token views are audited.

This decision intentionally does not change active order mutation behavior. It documents and exposes the pilot mode so the later Phase 5 slices can unify hashes, strengthen token handling, and add delivery metadata without changing the rollout strategy midstream.
