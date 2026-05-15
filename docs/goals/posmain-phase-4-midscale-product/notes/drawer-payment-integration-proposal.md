# Drawer / Payment Integration Proposal

Date: 2026-05-13

Status: proposed only. Do not implement until the user confirms this target.

## Why This Is The Recommended Next Runtime Slice

Phase 4 requires:

- cash payment writes drawer movement
- cashier must open drawer/shift before selling if configured
- shift close calculates expected cash from drawer movements
- Z report reconciles with order payments and drawer movements

The foundations already exist:

- `PaymentMethodService`
- `DrawerSessionService`
- `payment_methods` schema
- `drawer_sessions` / `drawer_movements` schema
- endpoint route contract tests for table and split payments

The runtime files are high risk and currently dirty, so the first integration should be the smallest money-path slice.

## Smallest Safe Worker Slice

Task id suggestion: `T026`

Objective:

Wire cash drawer movement recording into table-payment and split-payment service flows for cash payment methods only, without changing endpoint response shapes, Z reports, close-shift behavior, cashier UI, or non-cash payment behavior.

## Proposed Allowed Files

- `classes/Pos/Service/PaymentService.php`
- `classes/Pos/Service/PosOrderMutationService.php`
- `tests/sync/phase4_drawer_payment_integration_test.php`
- `tests/sync/process_table_payment_endpoint_routing_test.php`
- `tests/sync/process_split_payment_endpoint_routing_test.php`
- `docs/goals/posmain-phase-4-midscale-product/state.yaml`
- `docs/goals/posmain-phase-4-midscale-product/notes/`

Do not edit in this slice:

- `ajax/process_table_payment.php`
- `ajax/process_split_payment.php`
- `close_shift.php`
- `do_close_shift_z.php`
- `z_report.php`
- POS UI / JS / print files

## Behavior Boundary

Allowed:

- Detect configured cash payment methods using `payment_methods.type = cash` when available.
- Default legacy method string `cash` to cash when `payment_methods` is missing or no matching configured row exists.
- If `POSMAIN_REQUIRE_OPEN_SHIFT=1`, require an open drawer session before recording a cash table/split payment.
- Record `drawer_movements.sale_cash` for the actual applied cash amount.
- Keep non-cash payments unchanged and no drawer movement.
- Return existing service/endpoint response keys unchanged, adding only service-internal metadata if tests require it.

Not allowed:

- Rewriting table payment endpoint responses.
- Rewriting split payment endpoint responses.
- Changing accounting receipt/journal logic.
- Changing Z report or close shift.
- Enforcing drawer for non-cash payments.
- Introducing UI prompts.
- Editing live report SQL.

## Required Focused Tests

New test: `tests/sync/phase4_drawer_payment_integration_test.php`

Should cover:

- full table cash payment records one `sale_cash` drawer movement
- partial cash payment records the applied amount, not requested overpay
- non-cash/card payment records no drawer movement
- `POSMAIN_REQUIRE_OPEN_SHIFT=1` rejects cash payment when no open session exists
- split cash payment records one drawer movement for the child paid order amount
- existing `order_payments` records remain present
- existing payment status / remaining amount behavior remains unchanged

Regression tests:

- `php -l classes/Pos/Service/PaymentService.php`
- `php -l classes/Pos/Service/PosOrderMutationService.php`
- `php -l tests/sync/phase4_drawer_payment_integration_test.php`
- `POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_REQUIRE_OPEN_SHIFT=1 php tests/sync/phase4_drawer_payment_integration_test.php`
- `POSMAIN_TEST_MYSQL_PORT=3307 php tests/sync/phase4_drawer_session_service_test.php`
- `POSMAIN_TEST_MYSQL_PORT=3307 php tests/sync/phase4_payment_method_service_test.php`
- `php tests/sync/process_table_payment_endpoint_routing_test.php`
- `php tests/sync/process_split_payment_endpoint_routing_test.php`
- `POSMAIN_DB_PORT=3307 php tools/run_migrations.php --dry-run | rg 'Dry run: 0 pending sync schema change\(s\)'`
- `git diff --check -- classes/Pos/Service/PaymentService.php classes/Pos/Service/PosOrderMutationService.php tests/sync/phase4_drawer_payment_integration_test.php`

## Stop Conditions

- Need to edit endpoint files before service behavior is proven.
- Need to change endpoint response JSON.
- Need to change accounting posting behavior.
- Need to change close shift, Z report, UI, print, or live report SQL.
- Cannot prove no duplicate drawer movement under idempotent replay.
- Cannot determine whether a payment method is cash without broad heuristics.

## Next Runtime Slice After This

If this passes, the next slice can safely target either:

- close shift / Z report reconciliation against drawer movements, or
- endpoint-level open-shift error wording/UI behavior.
