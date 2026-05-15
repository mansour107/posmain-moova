# POS Disposable GUI Write Receipt - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T11:08:00Z

## Scope

This task tested previously safety-gated POS write flows using a disposable copy of the current MariaDB schema. It did not edit implementation files and did not mutate the normal `kody2` database used by `http://127.0.0.1:8010`.

## Disposable Runtime

- Cloned `kody2` into disposable schema `posmain_gui_write_20260514140156`.
- Started temporary POS server `posmain-php-disposable` on `http://127.0.0.1:8011`.
- The temporary server used:
  - `POSMAIN_DB_HOST=host.docker.internal`
  - `POSMAIN_DB_PORT=3307`
  - `POSMAIN_DB_NAME=posmain_gui_write_20260514140156`
- Logged in as `omar` and unlocked POS with barcode/password `1234`.

## GUI Save Order

Browser flow:

- Opened `http://127.0.0.1:8011/pos_barcode.php`.
- Added item `TEMP TEST مياه`.
- Clicked `حفظ الطلب`.

Database evidence in disposable schema:

- `ot_head` row `471` was created.
- `order_type=takeaway`.
- `fat_total=12`, `fat_net=12`.
- `paid_amount=0.00`, `remaining_amount=12.00`.
- `payment_status=unpaid`, `invoice_status=draft`.
- `fat_details` row `559` was created with `fatid=471`, `item_id=5`, `qty_out=1`, `price=12`, `det_value=12`.

## GUI Payment And Receipt

Browser flow:

- Added item `TEMP TEST شاي`.
- Opened payment modal with `دفع`.
- Submitted cash payment with `دفع وطباعة`.
- Browser navigated to `print/receipt.php?id=472`.

Database evidence in disposable schema:

- `ot_head` row `472` was created.
- `order_type=takeaway`.
- `fat_total=18`, `fat_net=18`.
- `paid_amount=18.00`, `remaining_amount=0.00`.
- `payment_status=paid`, `invoice_status=completed`.
- `payment_method=cash`, `payment_date=2026-05-14 11:04:57`.
- `fat_details` row `560` was created with `fatid=472`, `item_id=2`, `qty_out=1`, `price=18`, `det_value=18`.

Receipt evidence:

- `print/receipt.php?id=472` rendered without fatal/error text.
- Receipt text included `FOCUS HOUSE`, receipt number `0514266`, item `TEMP TEST شاي`, quantity `1.000`, total/net `18.00`, and print/back controls.

## GUI Table Save

Browser flow:

- Returned to `pos_barcode.php`.
- Selected table mode `طاولة`.
- Opened `اختر طاولة`.
- Selected `طاولة 5`.
- Added item `TEMP TEST كرواسون`.
- Clicked `حفظ الطلب`.

Database evidence in disposable schema:

- `ot_head` row `474` was created.
- `order_type=table`, `table_id=5`.
- `fat_total=55`, `fat_net=55`.
- `paid_amount=0.00`, `remaining_amount=55.00`.
- `payment_status=unpaid`, `invoice_status=draft`.
- `fat_details` row `561` was created with `fatid=474`, `item_id=4`, `qty_out=1`, `price=55`, `det_value=55`.
- `tables` row `5` had `tname=طاولة 5`, `table_case=1`.

## GUI Shift Close

Browser flow:

- Opened `إغلاق الشيفت`.
- Submitted shift close with cash `85.00`, expenses `0`, fund after `0`, and note `POSMAIN disposable GUI shift close test`.
- Browser redirected to `closed_sessions.php`.

Database/UI evidence in disposable schema:

- `closed_orders` row `8` was created.
- `shift=20260514_30`, `user=omar`, `date=2026-05-14`, `endtime=14:06:38`.
- `total_sales=85`, `expenses=0`, `cash=85`, `fund_after=0`.
- `info=POSMAIN disposable GUI shift close test`.
- Closed-sessions page displayed `تم إغلاق الشيفت بنجاح - إجمالي مبيعاتك: 85.00 ج.م (3 طلب)` and rendered without fatal/error text.

## Receipt Voucher Classification

Payment/receipt flow created expected paid order `472`. The disposable DB also showed an extra `ot_head` row `473` around the payment/receipt time:

- `id=473`
- `order_type=takeaway`
- `fat_total=NULL`
- `fat_net=0`
- `payment_status=unpaid`
- `invoice_status=completed`

Follow-up classification at `2026-05-14T11:12:32Z` indicates this is the expected cash receipt voucher row, not a duplicate POS order:

- `PosOrderMutationService::createTakeawayOrderInsideTransaction()` inserts the paid POS order as `pro_tybe=9`, then calls `insertTakeawayReceipt()` when `paid_cash > 0`.
- `insertTakeawayReceipt()` inserts a second `ot_head` accounting row with `pro_tybe=1`, `op2=<paid order id>`, and `pro_value=<cash amount>`.
- Current `kody2.ot_head` defaults explain the visible "empty" shape when a receipt voucher is inserted without invoice totals: `order_type='takeaway'`, `fat_total=NULL`, `fat_net=0`, `payment_status='unpaid'`, and `invoice_status='completed'`.
- Targeted script evidence passed: `POSMAIN_TEST_MYSQL_HOST=127.0.0.1 POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_TEST_MYSQL_USER=root POSMAIN_TEST_MYSQL_PASS='' php tests/sync/pos_takeaway_order_service_test.php`.
- Existing read-only DB evidence showed the same receipt-voucher pattern, for example `ot_head` row `467` with `pro_tybe=1`, `op2=466`, `pro_value=18`, and info `نوع الطلب: تيك أواي - دفع كاش`.

So this note no longer treats row `473` as a payment/redirect regression. The remaining blockers are the separate scripted, migration, Moova persistent-health, contract, and fixture-schema failures recorded in `state.yaml`.

## Cleanup

After evidence capture:

- Removed temporary container `posmain-php-disposable`.
- Dropped disposable schema `posmain_gui_write_20260514140156`.
- Confirmed normal POS baseline `http://127.0.0.1:8010/index.php` still returned HTTP `200`.
- Persistent Moova `/readyz` still returned `{"ok":false,"database":true,"redis":false}`.
