# Current GUI Smoke - 2026-05-14

## Purpose

Fresh browser-level POS smoke after the completion audit, to add current GUI evidence without implementation edits, schema changes, saving an order, or confirming payment.

## Scope

Target: `http://127.0.0.1:8010/pos_barcode.php`

Browser surface: Codex in-app browser.

Session state: already authenticated as cashier `omar`; no login form was submitted during this smoke.

## Results

The POS cashier screen loaded in the browser with these visible signals:

- Title: `نظام الإدارة`
- Cashier: `omar`
- Shift status: `الشيفت مفتوح`
- Current order panel
- Customer selector
- Table selector
- Advanced settings button
- Item catalog
- Moova POS widget iframe
- Payment controls

Safe interaction checks:

1. Clicked item `TEMP TEST شاي`.
   - Cart count changed to `1`.
   - Cart showed `TEMP TEST شاي`.
   - Total/net became `18.00 ج.م`.
2. Clicked `+`.
   - Total/net recalculated to `36.00 ج.م`.
3. Clicked `-`.
   - Total/net returned to `18.00 ج.م`.
4. Opened payment modal by clicking `دفع 18.00 ج.م`.
   - Modal `الدفع والإجماليات` opened.
   - Cash field showed `18.00`.
   - Cash box selector appeared.
   - Card/bank payment section appeared.
5. Closed the payment modal with `Close`.
   - Dialog closed.
6. Browser console errors for the tab:
   - `[]`

## Important Boundary

This was deliberately non-saving:

- Did not click `حفظ الطلب`.
- Did not confirm or submit payment.
- Did not close shift.
- Did not modify runtime configuration.
- Did not apply database migrations.
- Did not edit code or tests.

## Verdict

Current core POS GUI smoke passed for loading, cart item add, quantity recalculation, payment modal open/close, and console-error cleanliness.

This does not clear the overall goal because current script/runtime/database blockers remain:

- `js/pos_auto_lock.js` syntax failure.
- Pending `order_fulfillment` migration on `kody2`.
- Persistent Moova `/readyz` returns `503` with `redis=false`.
- Moova widget contract test failure.
- Write-surface inventory test failure.
- Minimal schema fixture smoke failures.
