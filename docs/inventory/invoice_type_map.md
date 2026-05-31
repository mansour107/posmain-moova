# Inventory Invoice Type Map

Generated: 2026-05-29

This map records how inventory-affecting invoice types are currently interpreted. It highlights conflicts that must be fixed before a production inventory cutover.

## Code Constants

`do/doadd_invoice.php` defines:

| Constant | Value | Meaning in code | Current stock effect |
| --- | ---: | --- | --- |
| `PURCHASE` | 4 | مشتريات | `qty_in = qty * u_val` |
| `SALES` | 3 | مبيعات | `qty_out = qty * u_val` |
| `POS` | 9 | كاشير | `qty_out = qty * u_val`; may trigger recipe consumption |
| `PURCHASE_RETURN` | 10 | مردود مشتريات | `qty_out = qty * u_val` |
| `SALES_RETURN` | 11 | مردود مبيعات | `qty_in = qty * u_val` |
| `PURCHASE_ORDER` | 12 | أمر شراء | no stock quantity |
| `SALES_ORDER` | 13 | أمر بيع | no stock quantity |
| `OFFER` | 14 | عرض سعر | no stock quantity in invoice add/edit |

## Seeded `pro_tybes`

`db/DB.sql` seeds:

| ID | Seeded Arabic name | Inventory meaning |
| ---: | --- | --- |
| 1 | سند قبض | Payment/accounting only |
| 2 | سند دفع | Payment/accounting only |
| 3 | فاتورة مبيعات | Outgoing stock |
| 4 | فاتورة مشتريات | Incoming stock |
| 9 | فاتورة كاشير | Outgoing POS stock |
| 10 | فاتورة مردود مبيعات | Name says sales return, but code treats value `10` as purchase return/outgoing |
| 11 | فاتورة مردود مشتريات | Name says purchase return, but code treats value `11` as sales return/incoming |
| 12 | أمر شراء | No stock quantity |
| 13 | أمر بيع | No stock quantity |
| 14 | رصيد افتتاحي مخازن | Opening balance in seed data, but invoice code also uses `14` as offer |
| 15 | رصيد افتتاحي حسابات | Accounting opening balance |

## UI Routing

`sales.php` and `elements/sales/fat_footer.php` map query strings in a confusing way:

| Query | Posted type | Display name in `sales.php` |
| --- | ---: | --- |
| `sale` | 4 | فاتورة مشتريات |
| `buy` | 3 | فاتورة مبيعات |
| `resale` | 10 | فاتورة مردود مشتريات / or seeded as sales return |
| `rebuy` | 11 | فاتورة مردود مبيعات / or seeded as purchase return |
| `po` | 12 | أمر شراء |
| `so` | 13 | أمر بيع |
| `offer` | 14 | عرض سعر |

The UI labels and query names appear reversed for sale/buy and inconsistent for return names. This increases operator error risk and makes automated migration harder.

## High-Risk Conflicts

1. Type `14` collision:
   - `OFFER` in invoice code and UI routing.
   - `رصيد افتتاحي مخازن` in seeded `pro_tybes`.
   - `POSMAIN_OPENING_BALANCE_PRO_TYPE = 14` in `save_start_balance.php`.

2. Return naming collision:
   - Code treats `10` as purchase return/outgoing stock.
   - Seed data names `10` as sales return.
   - Code treats `11` as sales return/incoming stock.
   - Seed data names `11` as purchase return.

3. Route naming collision:
   - Query `sale` maps to purchase type `4`.
   - Query `buy` maps to sales type `3`.

## Recommendation For Restructure

Before the final ledger becomes authoritative, create one canonical invoice type registry with:

- numeric ID,
- Arabic label,
- English semantic key,
- stock direction (`in`, `out`, `none`),
- accounting direction,
- whether the type can be used by POS, purchase UI, opening balance, or quotation UI.

Then migrate UI labels and handlers to the registry. Do not let opening balance and offer share the same type in the final system.

