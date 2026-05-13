# POSMAIN Phase 2 POS API Contracts

Generated: 2026-05-12  
Scope: Phase 2 canonical POS mutation contract before runtime endpoint refactors.

## Purpose

This document defines the target request, response, idempotency, retry, and error contract for active POS mutation endpoints. It is based on the Phase 0 route map and write-surface classification, and should be treated as the contract that Phase 2 services and endpoint wrappers converge toward.

The current repo still has legacy endpoint-specific behavior. Phase 2 changes should preserve cashier behavior while moving active writes through canonical services one route at a time.

## Major-Change Safety Checklist

Impacted surfaces:

- API contracts: `do/doadd_invoice.php`, `ajax/save_order.php`, `ajax/process_table_payment.php`, `ajax/process_split_payment.php`, `ajax/delete_order.php`, `ajax/clear_table.php`, `ajax/clear_table_normal.php`, `ajax/update_table_status.php`, `ajax/moova_confirm_order.php`, `ajax/moova_change_order.php`.
- Shared utilities: `config/app_config.php`, `includes/db_bootstrap.php`, `includes/connect.php`, `includes/session_bootstrap.php`, `includes/production_guard.php`.
- Database state: `ot_head`, `fat_details`, `order_payments`, `tables`, `closed_orders`, `journal_heads`, `journal_entries`, `myitems`, `document_counters`, planned `pos_request_keys`, planned `order_events`, future UUID columns.
- UI flows: Arabic cashier sale, table save, table payment, split payment, cancel/clear table, Moova accept/edit/cancel, receipt/KOT printing.
- Integrations: direct Moova widget, queued Moova worker, sync outbox, legacy `ajax/cofe_create_order.php` convergence later.

Compatibility risks:

- Duplicate browser submits or retries must not duplicate orders, payments, stock movements, journals, or Moova acknowledgements.
- Table truth must come from active unpaid/partial order rows, not `tables.table_case` alone.
- Accounting and stock side effects must remain linked to the same business transaction that creates or completes an order.
- Sync/cloud failures must not roll back a local cashier mutation after the local DB transaction succeeds.
- Existing dirty worktree changes in active endpoints must be preserved when later implementation slices touch those files.

Test strategy:

- Syntax-check every changed PHP file in each implementation slice.
- Run migration dry-run before and after schema work.
- Add focused tests for idempotency, counters, table truth, payment status, split quantity preservation, cancel behavior, and Moova stale edit protection.
- Smoke-check login/POS/table flows after endpoint routing changes.

## Common Contract Rules

### Response Envelope

All JSON mutation endpoints should converge on:

```json
{
  "success": true,
  "code": "OK",
  "message": "human readable message",
  "data": {},
  "request_id": "optional idempotency key",
  "retryable": false
}
```

Errors should converge on:

```json
{
  "success": false,
  "code": "ERROR_CODE",
  "message": "human readable message",
  "data": {},
  "request_id": "optional idempotency key",
  "retryable": false
}
```

Legacy endpoints may temporarily return their current shape, such as `order_id` or `receipt_id` at top level. Service methods should return structured arrays that can populate both legacy and canonical envelopes during migration.

### Idempotency

Every active write must accept an idempotency key before final Phase 2 completion.

Accepted key locations:

- JSON body: `idempotency_key` or existing `idempotencyKey`.
- HTTP header: `Idempotency-Key`.
- Legacy HTML form: hidden `idempotency_key`.

Target storage:

- Table: `pos_request_keys`.
- Unique key: `(scope, idempotency_key)`.
- Hash: canonical request payload hash.
- Same scope + key + same hash + completed: return stored response.
- Same scope + key + different hash: return `IDEMPOTENCY_CONFLICT`.
- Stale processing row: may be reclaimed only with logged warning.

Idempotency scopes:

| Scope | Endpoint owner | Purpose |
|---|---|---|
| `pos.order.create.takeaway` | `do/doadd_invoice.php` | Main cashier takeaway/delivery POS sale for `pro_tybe=9`. |
| `pos.table.save` | `ajax/save_order.php` | Create or update an active table order. |
| `pos.payment.table` | `ajax/process_table_payment.php` | Add full or partial payment to a table order. |
| `pos.payment.split` | `ajax/process_split_payment.php` | Move selected item quantities to a paid child order. |
| `pos.order.cancel` | `ajax/delete_order.php`, `ajax/clear_table.php`, `ajax/clear_table_normal.php`, `ajax/update_table_status.php` | Cancel/clear an unpaid active table order. |
| `moova.order.confirm` | `ajax/moova_confirm_order.php` | Apply a new Moova order to local POS. |
| `moova.order.change` | `ajax/moova_change_order.php` | Apply confirmed Moova edit/cancel to local POS. |

### Retry Behavior

Retry is safe only when the same idempotency scope, key, and canonical request hash are reused.

- Network timeout before response: retry same request and key.
- HTTP 409 `IDEMPOTENCY_CONFLICT`: do not retry automatically; cashier/operator must refresh.
- HTTP 409 `POS_ORDER_LINES_CHANGED`: do not mutate; caller must reload current POS state.
- HTTP 422 validation errors: caller may fix payload and submit with a new idempotency key.
- HTTP 500 `DB_TRANSACTION_FAILED`: caller may retry same key after operator confirms no visible duplicate.

### Common Error Codes

| Code | HTTP | Retryable | Meaning |
|---|---:|---|---|
| `AUTH_REQUIRED` | 401 | false | POS user session is missing. |
| `CSRF_INVALID` | 403 | false | Browser-origin write failed CSRF validation. |
| `PERMISSION_DENIED` | 403 | false | User lacks required role/permission. |
| `MANAGER_APPROVAL_REQUIRED` | 403 | false | Void/cancel/refund requires manager approval. |
| `INVALID_PAYLOAD` | 400 | false | JSON/form shape is invalid. |
| `IDEMPOTENCY_REQUIRED` | 400 | false | Write request did not include a key. |
| `IDEMPOTENCY_CONFLICT` | 409 | false | Same key was reused for different payload. |
| `TABLE_NOT_FOUND` | 422 | false | Table id does not exist. |
| `TABLE_ALREADY_OCCUPIED` | 409 | false | Table already has an active unpaid/partial order. |
| `ORDER_NOT_ACTIVE` | 409 | false | Target order is paid, cancelled, voided, deleted, or not linked to the table. |
| `ORDER_ALREADY_PAID` | 409 | false | Attempted to mutate an already paid order without void/refund flow. |
| `PAYMENT_AMOUNT_INVALID` | 422 | false | Amount is zero, negative, over policy, or below selected split value. |
| `ITEM_NOT_FOUND` | 422 | false | Item id does not exist or does not belong to the order. |
| `ITEM_UNAVAILABLE` | 422 | false | Item cannot be sold under current stock/availability rules. |
| `POS_ORDER_LINES_CHANGED` | 409 | false | Moova edit/cancel is stale against current POS line hash. |
| `MOOVA_LINK_NOT_FOUND` | 403 | false | Device token or branch link is not mapped. |
| `DB_TRANSACTION_FAILED` | 500 | true | Local DB transaction failed before a confirmed commit. |

## Endpoint Contracts

### `do/doadd_invoice.php`

Owner: main cashier form submit.  
Target service scope: `pos.order.create.takeaway` for `pro_tybe=9`; non-POS document types remain legacy until separately classified.

Current request type: HTML form POST.  
Target idempotency: hidden `idempotency_key`; fallback may be generated server-side only for non-retryable legacy submits during transition.

Phase 2 routed form branches:

- New `age=1` takeaway POS submits with `submit=cash`/`submit_action=cash` and a positive cash/bank total call `PosOrderMutationService::createTakeawayOrder`.
- The routed takeaway branch covers cash-only, bank-only, and cash+bank split payments through the same mutation service and idempotency scope.
- Existing edit/selected-order form submits remain on the legacy branch until a dedicated edit/void contract exists.
- `age=2` table state, payment, split, clear, and cancel writes are owned by the active AJAX endpoints documented below; those endpoints call the canonical services and now require idempotency keys from their visible UI callers.
- `age=3` delivery form submits remain a documented legacy compatibility branch because Phase 2 has no delivery-specific canonical contract or acceptance fixture yet.

Required fields for POS sale:

- `pro_tybe=9`
- `store_id`
- `pro_date`
- `acc2_id`
- `emp_id`
- `fund_id` or split payment funds where applicable
- item arrays such as `itmname`, quantities, prices, discounts
- `submit_action` or `submit`
- `age` for order mode: takeaway, table, delivery
- `table_id` when table mode is selected

Target success data:

```json
{
  "order_id": 123,
  "pro_id": 246,
  "payment_status": "unpaid|partial|paid",
  "order_status": "active|completed",
  "receipt_id": 456
}
```

Critical invariants:

- Allocate `pro_id` through `document_counters`, not `MAX()+1`.
- Recalculate totals from accepted detail rows.
- Apply payment and accounting in the same transaction when payment is included.
- Record `order_events`.
- Record sync outbox snapshots only after local mutation is valid; outbox failure must not block local service when disabled.

### `ajax/save_order.php`

Owner: table save/add/update.  
Target service scope: `pos.table.save`.

Current request type: JSON POST.

Required fields:

- `table_id`
- `order_id` when updating existing active order
- `order_date`
- `store_id`
- `emp_id`
- `fund_id`
- `items[]`
- `total`
- `discount`
- `net`
- `idempotency_key` or `Idempotency-Key`

Item fields:

- `id`
- `qty`
- `price`
- optional future `notes`
- optional future `modifiers`

Success data:

```json
{
  "order_id": 123,
  "table_id": 5,
  "payment_status": "unpaid|partial|paid",
  "remaining_amount": "120.00"
}
```

Primary errors:

- `TABLE_NOT_FOUND`
- `TABLE_ALREADY_OCCUPIED`
- `ORDER_NOT_ACTIVE`
- `ITEM_NOT_FOUND`
- `ITEM_UNAVAILABLE`
- `IDEMPOTENCY_CONFLICT`

Critical invariants:

- Lock table row and active order rows before mutation.
- For new save, reject or explicitly merge if table already has an active unpaid/partial order.
- Existing payments must not be lost when updating lines.
- `tables.table_case` follows active-order truth after commit.

### `ajax/process_table_payment.php`

Owner: table full/partial payment.  
Target service scope: `pos.payment.table`.

Current request type: form/AJAX POST.

Required fields:

- `table_id`
- `order_id` or active order lookup by table
- `paid` or `amount_paid`
- `payment_method`
- optional `discount`
- optional `net`
- optional `notes`
- `idempotency_key` or `Idempotency-Key`

Success data:

```json
{
  "order_id": 123,
  "table_id": 5,
  "receipt_id": 456,
  "payment_status": "partial|paid",
  "remaining_amount": "0.00"
}
```

Primary errors:

- `ORDER_NOT_ACTIVE`
- `ORDER_ALREADY_PAID`
- `PAYMENT_AMOUNT_INVALID`
- `IDEMPOTENCY_CONFLICT`
- `DB_TRANSACTION_FAILED`

Critical invariants:

- Insert exactly one non-voided payment row for one completed idempotency key.
- Recompute paid and remaining amounts from payment rows.
- Complete order and free table only when no active unpaid/partial order remains.
- Accounting journal and receipt voucher must be linked to the payment/order.

### `ajax/process_split_payment.php`

Owner: split selected item quantities into a paid child order.  
Target service scope: `pos.payment.split`.

Current request type: JSON POST.

Required fields:

- `order_id`
- `table_id`
- `items[]` containing detail ids or `{detail_id, qty}`
- `paid_amount`
- `payment_method`
- `idempotency_key` or `Idempotency-Key`

Success data:

```json
{
  "original_order_id": 123,
  "new_invoice_id": 124,
  "split_group_id": "stable group id",
  "remaining_total": "80.00"
}
```

Primary errors:

- `ORDER_NOT_ACTIVE`
- `ITEM_NOT_FOUND`
- `PAYMENT_AMOUNT_INVALID`
- `IDEMPOTENCY_CONFLICT`

Critical invariants:

- Lock original order and selected `fat_details` rows.
- Selected quantity must not exceed remaining line quantity.
- Total quantity across original and child rows must be preserved.
- Child paid order gets a unique `pro_id` from `document_counters`.
- Original table remains occupied only if active unpaid/partial lines remain.

### `ajax/delete_order.php`

Owner: POS/table cancel from POS JavaScript.  
Target service scope: `pos.order.cancel`.

Current request type: POST.

Required fields:

- `order_id`
- optional `table_id`; service may resolve from order
- `reason`
- `idempotency_key` or `Idempotency-Key`

Success data:

```json
{
  "order_id": 123,
  "table_id": 5,
  "order_status": "cancelled"
}
```

Primary errors:

- `ORDER_NOT_ACTIVE`
- `ORDER_ALREADY_PAID`
- `MANAGER_APPROVAL_REQUIRED`
- `IDEMPOTENCY_CONFLICT`

Critical invariants:

- Unpaid active orders may be cancelled with reason.
- Paid orders require refund/void flow, not silent delete.
- Details should be status/soft-delete compatible with legacy reports.
- Recalculate table occupancy from active-order truth.

### `ajax/clear_table.php`

Owner: clear table from POS/table UI where used.  
Target service scope: `pos.order.cancel`.

Contract matches `ajax/delete_order.php`, except `table_id` is required and active order may be looked up if `order_id` is omitted.

Required fields:

- `table_id`
- optional `order_id`
- optional `reason`
- `idempotency_key` or `Idempotency-Key`

Success must include `order_id` when an order was cancelled. If no active order exists, target behavior is success with `active_order_id=null` only if table truth was refreshed without deleting anything.

### `ajax/clear_table_normal.php`

Owner: visible tables clear action.  
Target service scope: `pos.order.cancel`.

Contract matches `ajax/clear_table.php`.

Additional target success data:

```json
{
  "table_id": 5,
  "order_id": 123,
  "total": "0.00",
  "active_order_id": null
}
```

### `ajax/update_table_status.php`

Owner: table status update from tables screen.  
Target service scopes:

- `pos.order.cancel` when action is `clear`.
- `pos.table.save` or a future `pos.table.status` read/refresh operation when action is `activate`.

Current request type: POST.

Required fields:

- `table_id`
- `action=clear|activate` or legacy `is_occupied`
- optional `order_id`
- optional `reason`
- `idempotency_key` or `Idempotency-Key` for write actions

Primary errors:

- `TABLE_NOT_FOUND`
- `ORDER_NOT_ACTIVE`
- `TABLE_ALREADY_OCCUPIED`
- `IDEMPOTENCY_CONFLICT`

Critical invariant:

- `activate` cannot mark a table occupied unless an active unpaid/partial order exists.

### `ajax/moova_confirm_order.php`

Owner: direct Moova widget new-order confirm/apply.  
Target service scope: `moova.order.confirm`.

Current request type: JSON POST.  
Auth: POS session plus `X-Moova-Device-Token`.

Phase 2 routing: the direct widget endpoint calls `PosOrderMutationService::confirmMoovaOrder`, which delegates to `MoovaNewOrderApplyService::applyInTransaction`. The queued worker also uses the same Moova apply service with `response_mode=queued`, so direct and queued paths share idempotency/order-link behavior while keeping their response wrappers.

Required headers:

- `X-Moova-Device-Token`
- `Idempotency-Key` if body does not include `idempotencyKey`

Required body fields:

- `idempotencyKey`
- `branchId` or branch resolved from active device link
- Moova order id field as defined by Moova payload
- table/customer/menu item payload needed by `MoovaNewOrderApplyService`

Success data:

```json
{
  "moova_order_id": "external id",
  "pos_order_id": 123,
  "table_id": 5,
  "status": "accepted"
}
```

Primary errors:

- `AUTH_REQUIRED`
- `DEVICE_TOKEN_REQUIRED`
- `MOOVA_LINK_NOT_FOUND`
- `PERMISSION_DENIED`
- `TABLE_NOT_FOUND`
- `TABLE_ALREADY_OCCUPIED`
- `ITEM_NOT_FOUND`
- `IDEMPOTENCY_CONFLICT`

Critical invariants:

- Device token and POS user must map to the same allowed tenant/branch.
- Moova order id plus branch maps to one POS order link unless a documented split rule exists.
- Direct widget and queued worker apply must converge through the same local mutation contract.

### `ajax/moova_change_order.php`

Owner: direct Moova edit/cancel apply after cashier confirmation.  
Target service scope: `moova.order.change`.

Current request type: JSON POST.  
Auth: POS session plus `X-Moova-Device-Token`.

Phase 2 routing: the direct widget endpoint calls `PosOrderMutationService::changeMoovaOrder`, which delegates to `MoovaChangeOrderApplyService::applyInTransaction`. The queued worker also uses the same Moova change service with `response_mode=queued`, so direct and queued paths share stale-line decline and idempotency behavior while keeping their response wrappers.

Required headers:

- `X-Moova-Device-Token`
- `Idempotency-Key` if body does not include `idempotencyKey`

Required body fields:

- `idempotencyKey`
- `action=edit|cancel`
- `moovaOrderId` or existing `orderId`
- `cashierReviewed=true`
- `cashierAction=confirm`
- changed item payload for edits

Success data:

```json
{
  "moova_order_id": "external id",
  "pos_order_id": 123,
  "action": "edit|cancel",
  "status": "accepted"
}
```

Primary errors:

- `AUTH_REQUIRED`
- `DEVICE_TOKEN_REQUIRED`
- `MOOVA_LINK_NOT_FOUND`
- `CASHIER_REVIEW_REQUIRED`
- `IDEMPOTENCY_CONFLICT`
- `POS_ORDER_LINES_CHANGED`
- `ORDER_NOT_ACTIVE`

Critical invariants:

- Compare last stored POS state hash with current POS lines before mutating.
- Stale edits/cancels decline with `POS_ORDER_LINES_CHANGED`.
- Cancel uses the same local cancel contract as POS table cancel.
- Edit uses the same local replace/update contract as POS table save.

## Legacy/Deferred Contract

### `ajax/cofe_create_order.php`

Phase 0 class: B legacy/direct integration path.

Phase 2 rule:

- Do not expand this route as an independent mutation path.
- Either disable it for production use or route it through the Moova/local ingest contract after a dedicated Judge-approved slice.
- Preserve it until reachability is verified because it may still be integration-reachable.

## Event Contract

Target event table: `order_events`.

Events should be written inside the same local transaction as the business mutation where practical.

Required fields:

- `order_id`
- `event_type`
- `event_source`
- `actor_user_id`
- `tenant`
- `branch`
- optional `before_state_json`
- optional `after_state_json`
- optional `metadata_json`

Minimum event types for Phase 2:

- `order_created`
- `order_updated`
- `payment_added`
- `split_payment_created`
- `order_cancelled`
- `table_assigned`
- `table_freed`
- `moova_order_linked`
- `moova_order_edited`
- `moova_order_cancelled`

## Phase 2 Implementation Order From This Contract

1. Add `pos_request_keys` and `order_events` schema/service foundation.
2. Add nullable UUID columns/backfill for critical tables.
3. Finish `document_counters` adoption in active POS/table/payment/Moova paths.
4. Build canonical mutation services with wrappers around existing accounting, inventory, and table helpers.
5. Route one active endpoint at a time through the canonical service, preserving legacy response shape during transition.
6. Add focused verification for each route before moving to the next route.
