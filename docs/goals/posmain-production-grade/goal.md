# POSMAIN Production Grade

## Objective

Reach Foodics-class production reliability through three gated tranches: **Stabilize → Reconcile → Default/Certify**. Close known code/system gaps (P01–P14) without unsafe blast radius on the live ERP.

## Goal Kind

`open_ended`

## Current Tranche

**Tranche A:** Phase 0 discovery receipt + **Gate 1 Stabilize** (cloud health, recost guard, scope regression tests). Stop at Judge Gate 1 audit unless Gate 2 is explicitly approved.

**Queued:** Tranche B (Gate 2 per-shop reconcile), Tranche C (Gate 3 profile/certify/UX/automation).

## Non-Negotiable Constraints

- Do not flip global production profile defaults until Gate 2 cutover green on every shop DB.
- Per-shop data repair: backup → dry-run → review → rehearse → apply; one pilot shop before the next.
- Keep legacy env keys compatible until Gate 3 test migration passes.
- Preserve Arabic RTL cashier behavior; minimal diff per gate.
- No combined all-shops backfill apply until one shop proves clean.

## Stop Rule

Stop Tranche A at T013 Judge audit. Do not start Gate 2 without owner approval (user may waive by requesting full implementation in session).

## Canonical Board

Machine truth lives at:

`docs/goals/posmain-production-grade/state.yaml`

## Run Command

```text
/goal Follow docs/goals/posmain-production-grade/goal.md
```

## PM Loop

On every continuation: read charter → read state.yaml → work only active task → receipt → update board → next active task.
