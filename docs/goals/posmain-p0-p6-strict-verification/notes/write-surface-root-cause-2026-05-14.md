# Write-Surface Inventory Root Cause - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T11:28:47Z

## Question

Why does `tests/sync/write_surface_inventory_test.php` fail for `ajax/moova_confirm_order.php` when `tools/audit_write_paths.php` appears to classify `moova_` paths as `moova_bridge`?

## Evidence

Command:

```bash
php tools/audit_write_paths.php --json | php -r '...print Moova-related surfaces...'
```

Observed Moova-related output included:

- `ajax/cofe_create_order.php`
- `classes/Pos/Service/PosOrderMutationService.php`
- Moova worker/test/service files
- `classes/Moova/MoovaNewOrderApplyService.php`
- `classes/Moova/MoovaChangeOrderApplyService.php`

But it did **not** include:

- `ajax/moova_confirm_order.php`
- `ajax/moova_change_order.php`

The tool flow explains why:

- `findWrites()` only includes files with direct SQL write statements.
- `findDelegatedWrites()` currently includes `ajax/moova_confirm_order.php` only if the endpoint source contains `MoovaNewOrderApplyService`.
- It includes `ajax/moova_change_order.php` only if the endpoint source contains `MoovaChangeOrderApplyService`.
- Current endpoints call `PosOrderMutationService`, and that service delegates to the Moova apply services.
- Because no direct SQL write or old direct apply-service string is present in the endpoint files, they never enter `$surfaces`.
- Therefore `classifySurface()` never gets a chance to add `moova_bridge` by path name.

## Classification

This is a stale delegated-write discovery rule, tied to the same facade-vs-direct-service contract decision recorded in the fix-scope note.

The failing test is not merely saying "wrong category." It is saying the Moova endpoints are absent from the audit surface list under the new `PosOrderMutationService` facade path.

## Follow-Up

If the facade path is the intended architecture, a fix tranche should update delegated write discovery to include:

- `ajax/moova_confirm_order.php` when it calls `confirmMoovaOrder` through `PosOrderMutationService`.
- `ajax/moova_change_order.php` when it calls `changeMoovaOrder` through `PosOrderMutationService`.

Then rerun:

```bash
php tools/audit_write_paths.php --json
php /tmp/posmain-phpunit-9.phar --bootstrap /tmp/posmain-phpunit-autoload.php --colors=never tests/sync/write_surface_inventory_test.php
```
