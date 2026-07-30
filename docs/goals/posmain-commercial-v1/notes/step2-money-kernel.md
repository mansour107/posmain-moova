# Step 2 receipt notes

Date: 2026-07-27

## Done

- `FinancialMoneyInput` rejects PHP floats at certified boundaries.
- `PosRequest` rejects JSON float payloads.
- `PosOrderMutationService` / `PaymentService` / `OrderPricingService` / `PosOrderController` use strict decimal money (no active float coercion on write paths covered).
- Cash settlement facts: `tendered_amount`, `applied_amount`, `change_due` on `order_payments` (+ takeaway response fields).
- Only cash may exceed outstanding balance (`NON_CASH_TENDER_EXCEEDS_REMAINING`).
- Invoice posting fails closed on nonzero tax (`TAX_DISABLED_NONZERO_TAX_REJECTED`).

## Proof

- `php tests/sync/commercial_v1_step2_money_contract_test.php`
- `php tests/sync/commercial_v1_step2_money_runtime_test.php`
- `php tests/sync/order_pricing_service_test.php`
- `php tools/commercial_v1_step2_gate.php` → green

## Step 1 gap closures included in this tranche

- PHP built-in `router.php` denies tools/docs/scripts/prohibited routes
- Docker PHP CMD uses the router
- Secrets/session/CSRF/uploads checks in Step 1 gate
- Write-surface classification receipt
- Evidence bundle with git commit + composer.lock hash
- `posmain-production-grade` marked `historical_not_v1_authority`
