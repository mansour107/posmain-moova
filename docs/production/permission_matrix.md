# POSMAIN Phase 3 Permission Matrix

Generated: 2026-05-13  
Scope: Phase 3 security hardening foundation.

## Purpose

This matrix defines named permissions for POSMAIN's restaurant/cafe rollout while preserving the existing `usr_pwrs` role flags. Phase 3 does not replace the role table yet. Instead, `includes/auth_guard.php` maps named permissions to legacy flags so route hardening can be applied in small, reviewable slices.

## Safety Rules

- Keep admin role `usr_pwrs.id = 1` as a compatibility superuser unless a later migration explicitly changes this.
- Do not require CSRF for machine-to-machine Moova/cloud endpoints that authenticate with device tokens or branch secrets.
- Apply route enforcement gradually: helper foundation first, low-risk admin routes second, active cashier/table writes only after token propagation is tested.
- Manager-only actions such as paid void, refund, and large discount should use named permissions even if the first bridge maps them to existing legacy flags.

## Roles

| Role | Description |
|---|---|
| owner/admin | Full operator control, including users, roles, tools, and emergency actions. |
| manager | Runs a branch during service, approves sensitive POS actions, sees reports. |
| cashier | Sells takeaway/table orders, takes payments, performs ordinary unpaid cancels. |
| waiter | Opens/updates table orders and sends them to cashier/kitchen where enabled. |
| kitchen | Views/prepares orders; no payment or admin control. |
| accountant | Views accounting and reports; no cashier mutation by default. |
| inventory manager | Maintains items, stock, and availability. |
| branch operator | Monitors health/sync and basic branch operations. |
| support/readonly | Diagnoses state without mutating sales, stock, users, or settings. |

## Named Permission Bridge

| Permission | Existing `usr_pwrs` bridge | Typical roles | Notes |
|---|---|---|---|
| `pos.open` | `show_sales`, `sid_sales` | admin, manager, cashier, waiter | Opens POS surface. |
| `pos.sell.takeaway` | `add_sales`, `show_sales`, `sid_sales` | admin, manager, cashier | Creates takeaway cashier sale. |
| `pos.table.open` | `add_sales`, `show_sales`, `sid_sales` | admin, manager, cashier, waiter | Creates or updates active table order. |
| `pos.table.move` | `edit_sales`, `show_sales`, `sid_sales` | admin, manager | Move table order. |
| `pos.table.merge` | `edit_sales`, `show_sales`, `sid_sales` | admin, manager | Merge table orders. |
| `pos.payment.take` | `add_payment`, `show_payment`, `add_sales` | admin, manager, cashier | Take table/takeaway payment. |
| `pos.discount.apply` | `edit_sales`, `add_sales` | admin, manager, cashier | Ordinary discount within policy. |
| `pos.discount.manager_override` | `edit_sales` | admin, manager | Discount beyond cashier policy. |
| `pos.recipe_stock_override` | `edit_sales`, `edit_stock` | admin, manager, inventory manager | Allow a recipe-backed item sale when computed ingredient availability is unavailable and strict stock is off. |
| `pos.cancel.unpaid` | `delete_sales`, `edit_sales` | admin, manager, cashier | Cancel unpaid active order with reason. |
| `pos.void.paid` | `delete_payment`, `edit_payment` | admin, manager | Paid void requires manager approval. |
| `pos.refund` | `delete_payment`, `edit_payment` | admin, manager | Refund path; must be audited. |
| `pos.split` | `add_payment`, `add_sales` | admin, manager, cashier | Split selected item payment. |
| `pos.shift.open` | `add_sales`, `sid_sales` | admin, manager, cashier | Open shift/drawer. |
| `pos.shift.close` | `edit_sales`, `sid_sales` | admin, manager, cashier | Close shift/drawer. |
| `pos.cashdrawer.count` | `edit_payment`, `show_payment` | admin, manager, cashier | Drawer count/close. |
| `menu.edit` | `add_items`, `edit_items`, `add_item_groups`, `edit_item_groups` | admin, manager, inventory manager | Edit menu/catalog. |
| `inventory.edit` | `add_stock`, `edit_stock`, `add_items`, `edit_items` | admin, inventory manager | Stock and item maintenance. |
| `inventory.approve` | `delete_stock` | admin, senior inventory manager | Approve sensitive stock actions such as stale count close, closed count reversal, negative adjustments, backdated adjustments, transfer variances, and existing stock-level policy edits. This is intentionally narrower than `inventory.edit`. |
| `reports.view` | `sid_reports`, `show_gl_reports`, `show_hr_report`, `show_payroll_report` | admin, manager, accountant, support | Reports. |
| `accounting.view` | `sid_accounts`, `show_gl_reports`, `show_journals` | admin, accountant | Accounting views. |
| `users.manage` | `add_users`, `edit_users`, `delete_users` | admin | User create/edit/delete. |
| `roles.manage` | `add_users`, `edit_users`, `delete_users` | admin | Temporary bridge until dedicated role flags exist. |
| `moova.manage` | `edit_sales`, `sid_sales` | admin, manager | Configure Moova device/widget link. |
| `moova.accept` | `add_sales`, `sid_sales` | admin, manager, cashier | Accept/decline Moova order in POS. |
| `system.health.view` | `sid_reports`, `sid_accounts` | admin, branch operator, support | Detailed health/status views. |
| `system.tools.run` | admin only | admin | Migration/backup/repair tools; keep production-gated. |

## CSRF Policy

Browser-origin writes must use CSRF:

- `do/*` browser form writes.
- `ajax/*` browser-origin writes.
- settings, user, role, password, Moova admin UI, and POS table/payment writes.

Machine endpoints are exempt when they authenticate with non-cookie credentials:

- `ajax/moova_confirm_order.php`
- `ajax/moova_change_order.php`
- `moova_pos_proxy.php`
- branch/cloud sync endpoints using branch secrets or status tokens

The exemption exists because CSRF protects browser cookie sessions. Device-token and branch-secret calls should reject forged browser requests by credential validation, not by session CSRF.

## Audit Requirements

The following actions must write `security_audit_log` once the Phase 3 audit service is introduced:

- login success/failure
- password changes
- user/role changes
- permission denied
- manager approvals
- void/refund/cancel
- Moova integration save/disconnect/token view
- production guard denied route
- migration run
- backup/restore run
