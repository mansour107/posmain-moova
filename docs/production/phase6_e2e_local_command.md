# Phase 6 Local E2E Command And Browser Matrix

Phase 6 accepts either automated browser tests or a documented local command. This repo does not currently declare a browser E2E dependency in `package.json`, so the controlled pilot path is a documented local command plus a browser evidence matrix. Do not mark the matrix passed from source review alone; it requires a running local stack seeded with Phase 6 demo data.

## Local Command

Run from the repo root on a non-production machine:

```bash
docker start posmain-mysql posmain-php
POSMAIN_DB_PORT=3307 php tools/run_migrations.php --dry-run
POSMAIN_ENV=test POSMAIN_PRODUCTION_MODE=0 POSMAIN_DB_PORT=3307 php tools/seed_demo_restaurant.php --apply --reset-demo --with-moova-dummy
POSMAIN_ENV=test POSMAIN_PRODUCTION_MODE=0 POSMAIN_DB_PORT=3307 php tools/phase6_load_concurrency_check.php --json --allow-current-db
open http://127.0.0.1:8010/index.php
```

If the app is not using the local Docker stack, replace the host, port, and DB environment variables with the approved staging branch values. Do not run the seed or load commands against production.

## Test Accounts

The demo seed tool creates these local-only accounts:

| Role | Username | Password |
| --- | --- | --- |
| Admin | `p6_admin` | `P6demo123!` |
| Manager | `p6_manager` | `P6demo123!` |
| Cashier | `p6_cashier` | `P6demo123!` |
| Waiter | `p6_waiter` | `P6demo123!` |

## Browser Scenario Matrix

Record the tester, timestamp, browser/device, and result for every row. Attach screenshots or short notes for failures.

| Scenario | Start URL or screen | User | Expected evidence |
| --- | --- | --- | --- |
| Login | `http://127.0.0.1:8010/index.php` | cashier, manager, waiter | User reaches the intended POS or table screen without debug output |
| POS lock/unlock | POS screen | cashier | Lock hides active order controls; unlock restores the same cart/session |
| Takeaway sale paid cash | `pos_barcode.php` or active POS screen | cashier | One paid takeaway order, one payment, matching receipt total |
| Table order save | table/POS flow using P6-DEMO table | waiter/cashier | Table becomes occupied and order remains visible after refresh |
| Add item to table | same table order | waiter/cashier | Added item appears once; table total increases correctly |
| Partial payment | table payment flow | cashier | Remaining amount stays positive and not negative |
| Split payment | split payment flow | cashier | Split items/payment settle the intended lines only |
| Cancel unpaid table | table order then cancel/delete | manager/cashier with approval when required | Order is cancelled and table returns to clear |
| Manager approval for void | void/cancel requiring approval | manager | Approval is recorded and unauthorized cashier-only attempt is rejected |
| Open shift | drawer/shift UI | cashier/manager | Drawer session opens with expected starting cash |
| Close shift | close shift / Z close UI | cashier/manager | Close shift or Z close produces totals matching test sales |
| Print receipt view | receipt print action | cashier | Receipt view opens and total matches the paid order |
| Print KOT view | kitchen/KOT print action | cashier/waiter | KOT view opens with correct table/items and no payment details leak |
| Moova accept if enabled | Moova widget/direct bridge | cashier | Representative pending order can be accepted once |
| Moova decline if enabled | Moova widget/direct bridge | cashier | Decline requires a reason and does not create a POS order |

## Active Backend Owners

Use `docs/production/active_route_map.md` to diagnose failures:

- Main cashier sale: `do/doadd_invoice.php`
- Table save/add: `ajax/save_order.php`
- Table payment: `ajax/process_table_payment.php`
- Split payment: `ajax/process_split_payment.php`
- Cancel/delete: `ajax/delete_order.php`
- Table clear/status: `ajax/clear_table_normal.php`, `ajax/clear_table.php`, `ajax/update_table_status.php`
- Close shift and Z close: `close_shift.php`, `do_close_shift_z.php`
- Moova accept/change: `ajax/moova_confirm_order.php`, `ajax/moova_change_order.php`

## Pass/Fail Rule

The local E2E command passes only when:

- The seed command succeeds on the same database the browser is using.
- The load/concurrency command succeeds.
- Every required browser scenario above is recorded as pass, or a disabled integration such as Moova is explicitly marked not enabled.
- No duplicate `pro_id`, negative remaining amount, or table stuck occupied after paid/cancelled order appears during the run.
- Failures are filed as pilot blockers before go-live.

## Evidence Record

| Field | Value |
| --- | --- |
| Tester |
| Date/time |
| Machine/branch |
| Release commit |
| DB name |
| Seed command result |
| Load command result |
| Browser/device |
| Failed rows |
| Blocker owner |
