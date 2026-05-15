# Phase 4 Runtime Integration Pause Checklist

Date: 2026-05-13

## Objective Restatement

Implement POSMAIN Phase 4 mid-scale restaurant/cafe product completion using Goal Maker board discipline, based on completed Phase 0-3 artifacts, with scoped edits and focused tests after each significant change.

## Completed Foundations

- Additive Phase 4 schema: done, applied to local/dev DB, migration dry-run reports 0 pending.
- Item availability: service done; search endpoints decorate response items with availability metadata only.
- Modifiers and line notes: service done behind `POSMAIN_ENABLE_MODIFIERS`; no UI/totals/print wiring.
- Payment methods: service done; no payment endpoint/report wiring.
- Drawer sessions: service done; no payment endpoint/shift/Z wiring.
- Print jobs/printers: service done; no print template/queue worker/browser printer wiring.
- Table transfer: service-only move done; no UI/AJAX route or merge wiring.
- Restaurant report contracts: non-runtime registry done; no report SQL/page rewrites.
- Nutrition profiles: service done behind `POSMAIN_ENABLE_NUTRITION`; no POS/admin/UI/report wiring.

## Remaining Phase 4 Acceptance Gaps

- Cashier UX simplification: not started.
- Large menu DOM rendering and client category cache: not completed; search metadata foundation exists only.
- Unavailable item sale blocking / manager override: not wired.
- Modifier POS modal/admin editor/price integration/KOT display/reporting: not wired.
- Table move UI/AJAX route: not wired.
- Table merge transaction/UI: not implemented.
- Payment endpoint integration with `payment_methods`: not wired.
- Manager approvals for discounts/void/refund/drawer adjustment: not wired.
- Open shift requirement and cash payment drawer movements: not wired.
- Shift/Z close reconciliation with drawer movements and payment methods: not wired.
- Receipt/KOT template integration with notes/modifiers and print jobs: not wired.
- Live report rewrites and payment-method report replacement: not wired.
- Decimal stock runtime hardening in POS sale path: not wired.

## Confirmation Choices

Recommended next target:

1. Drawer/payment integration

Other valid choices:

2. Table move UI/AJAX
3. Print/KOT integration
4. Availability sale blocking/UI
5. Live report rewrites

## Why Confirmation Is Required

The remaining work touches dirty high-risk runtime surfaces, including active cashier, payment, table, shift, report, and print paths. The Phase 4 board is intentionally paused at `T025` until the user confirms which surface to wire first.

Relevant active/high-risk files include:

- `ajax/save_order.php`
- `ajax/process_table_payment.php`
- `ajax/process_split_payment.php`
- `close_shift.php`
- `do_close_shift_z.php`
- `z_report.php`
- `pos_barcode.php`
- `includes/pos_content.php`
- `tables.php`
- `pos_tables.php`
- `js/pos_tables.js`

## Verification Required After Confirmation

Each runtime slice should include:

- A focused contract or service test for the changed behavior.
- Existing adjacent route contract tests for the touched surface.
- Migration dry-run check remains 0 pending.
- Goal Maker board validation.
- `git diff --check` scoped to changed files.
- Browser/live POS verification if the slice changes cashier-visible UI.
