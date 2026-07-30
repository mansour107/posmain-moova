# Step 3 — Atomic mutations

## What shipped

- `mutation_version` is required and asserted under row lock on pay, split, cancel (table + delivery), refund/void, table move, and table merge (both orders, lock-ordered).
- Cancel paths gained service-level idempotency; AJAX wrappers pass `skip_idempotency` + `mutation_version` to avoid nested key conflicts.
- Controller pay/split forward `mutation_version`; move/merge endpoints and POS/tables UI send versions.
- Successful mutations bump version; stale/missing versions fail closed (`STALE_ORDER_VERSION` / `MUTATION_VERSION_REQUIRED`).

## Proof

- `php tools/commercial_v1_step3_gate.php` — green (runs Step 3 contract/runtime + idempotency + move/merge tests + Step 1 + Step 2 gates).
- Evidence: `docs/goals/posmain-commercial-v1/evidence/step3-bundle-latest.json`
