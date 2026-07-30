# POSMAIN Commercial V1

## Objective

Execute the commercial V1 production-readiness remediation in gated steps.
Steps 1–3 establish the release spine, money kernel, and atomic mutation kernel.
The active tranche establishes reconciled inventory, recipe, and COGS truth
without weakening the already-proven money and order invariants.

## Status model

Package status is evidence-linked only:

- `pending` — not started
- `in_progress` — actively implementing
- `blocked` — waiting on owner/hardware/accounting input
- `evidence_pass` — focused tests and gate receipt are green
- `done` — exit gate satisfied and receipt retained

Do not mark packages completed from implementation claims alone.
