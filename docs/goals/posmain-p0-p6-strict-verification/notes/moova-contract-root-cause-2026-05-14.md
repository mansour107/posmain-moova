# Moova Widget Contract Root Cause - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T11:29:51Z

## Question

Does `tests/sync/moova_widget_bridge_contract_test.php` fail because Moova direct-widget behavior is broken, or because the source-level contract still expects the pre-facade endpoint shape?

## Evidence

The failing test expects endpoint-level service imports and instantiation:

- `ajax/moova_confirm_order.php` must contain `require_once('../classes/Moova/MoovaNewOrderApplyService.php')`.
- It must contain `new MoovaNewOrderApplyService()`.
- `ajax/moova_change_order.php` must contain `require_once('../classes/Moova/MoovaChangeOrderApplyService.php')`.
- It must contain `new MoovaChangeOrderApplyService()`.

Current endpoint shape:

- `ajax/moova_confirm_order.php` imports `MoovaLocalIngestService` and `PosOrderMutationService`.
- It normalizes idempotency key, payload hash, request JSON, and POS payload through `MoovaLocalIngestService`.
- It calls `(new PosOrderMutationService())->confirmMoovaOrder(...)` with `'response_mode' => 'direct'`.
- It responds with `moova_json_response(200, $result['response'])`.
- `ajax/moova_change_order.php` follows the same pattern with `changeMoovaOrder(...)`, cashier review guard, and `'response_mode' => 'direct'`.

Facade implementation:

- `PosOrderMutationService.php` requires both `MoovaNewOrderApplyService.php` and `MoovaChangeOrderApplyService.php`.
- `confirmMoovaOrder()` delegates to `(new MoovaNewOrderApplyService())->applyInTransaction(...)`.
- `changeMoovaOrder()` delegates to `(new MoovaChangeOrderApplyService())->applyInTransaction(...)`.

Direct-widget metadata:

- `MoovaNewOrderApplyService` uses `$options['response_mode']` and calls `MoovaApplyResponse::directWidget(...)` for direct responses.
- `MoovaChangeOrderApplyService` uses `$options['response_mode']` and calls `MoovaApplyResponse::directWidgetChange(...)` for direct change responses.
- `elements/pos/cofe_widget.php` still advertises/uses bridge metadata such as `syncEventType` and `syncStatus`.

Prior phase evidence:

- Phase 2 task T044 explicitly says direct Moova confirm/change endpoints now call `PosOrderMutationService` wrappers, which delegate to the existing Moova apply services.
- Phase 2 final audit marked this canonical mutation-service entrypoint as intentional.

## Classification

The contract test is stale with respect to the intentional facade architecture.

The current source still appears to preserve the behavioral path that matters:

`endpoint -> PosOrderMutationService facade -> Moova*ApplyService -> MoovaApplyResponse direct-widget metadata`

So the reproduced PHPUnit failure is valid as a stale-test/tooling blocker, but it should not be interpreted as proof that live direct-widget metadata stamping is missing. The strict verdict remains blocked until the contract is updated or the architecture is deliberately changed back and reverified.

## Follow-Up

If the facade path remains canonical, update the contract test to assert:

- endpoint imports and calls `PosOrderMutationService`;
- endpoint passes `'response_mode' => 'direct'`;
- `PosOrderMutationService` requires and delegates to both `Moova*ApplyService` classes;
- the apply services still call `MoovaApplyResponse::directWidget(...)` / `directWidgetChange(...)`;
- the parent widget still advertises and forwards `syncStatus` / `syncEventType`.

Then rerun:

```bash
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/moova_widget_bridge_contract_test.php
```
