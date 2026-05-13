# T009 Regression Pass

Date: 2026-05-10

## Scope

Focused regression evidence for the current POS sync/Moova/counter foundation tranche. No implementation files were modified in this pass.

## Commands and Results

- PASS: `find classes/Sync -name '*.php' -print0 | xargs -0 -n1 php -l && php -l classes/PosOrderService.php && find tests/sync -name '*.php' -print0 | xargs -0 -n1 php -l && php -l tests/PosOrderServiceMoovaIsolationTest.php`
  - All `classes/Sync` files, `classes/PosOrderService.php`, `tests/sync` files, and `tests/PosOrderServiceMoovaIsolationTest.php` reported no syntax errors.
- PASS with notices: `POSMAIN_TEST_MYSQL_PORT=3307 php /tmp/phpunit-12.phar tests/sync/write_surface_inventory_test.php tests/sync/document_counter_tests.php tests/sync/sync_schema_migration_test.php tests/sync/outbox_worker_reclaim_test.php tests/sync/pos_order_service_counter_test.php tests/sync/cloud_auth_service_test.php tests/sync/sync_apply_response_test.php tests/sync/moova_branch_event_cursor_test.php tests/PosOrderServiceMoovaIsolationTest.php`
  - PHPUnit 12.5.24, PHP 8.5.5.
  - 20 tests, 356 assertions.
  - Result: OK, but with 2 deprecations and 1 PHPUnit deprecation.
- PASS: `POSMAIN_SYNC_DB_PORT=3307 php tools/run_migrations.php --dry-run`
  - `Dry run: 0 pending sync schema change(s).`
- PASS: `POSMAIN_SYNC_DB_PORT=3307 php tools/backfill_document_counters.php --dry-run`
  - `Dry run: 3 document counter seed(s).`
  - `pro_id:pro_tybe:1 tenant=0 branch=0 current_value=7`
  - `pro_id:pro_tybe:9 tenant=0 branch=0 current_value=68`
  - `journal_id:journal:default tenant=0 branch=0 current_value=54`
- PASS: `git diff --check -- classes/Sync classes/PosOrderService.php tests/sync tests/PosOrderServiceMoovaIsolationTest.php docs/goals/posmain-online-offline-sync/notes`
  - No whitespace errors in the focused tranche surfaces.
- FAIL, pre-existing dirty tree: `git diff --check`
  - `item_categories.php:85: trailing whitespace.`
  - This file is outside T009 allowed write scope and was not changed.
- INFO: `git diff --stat -- do/doadd_invoice.php ajax/cofe_create_order.php && git diff --name-status -- do/doadd_invoice.php ajax/cofe_create_order.php && git diff -- do/doadd_invoice.php ajax/cofe_create_order.php | sed -n '1,220p'`
  - `ajax/cofe_create_order.php` has no current diff.
  - `do/doadd_invoice.php` has a current 5-line dirty diff around `$is_save_only` and payment voucher creation. This appears to be existing cashier/table-save runtime work and was not modified by T009.
- INFO: `git diff --stat -- classes/Sync classes/PosOrderService.php tests/sync tests/PosOrderServiceMoovaIsolationTest.php`
  - Current visible diff in the focused tranche is `classes/PosOrderService.php` and `tests/PosOrderServiceMoovaIsolationTest.php`; `classes/Sync` and `tests/sync` are untracked additions in this dirty worktree, so they are not summarized by `git diff --stat` unless staged.

## Touched-Surface Conclusions

- Sync schema/counter/outbox/cloud-auth/Moova cursor surfaces are covered by focused lint plus the combined PHPUnit suite.
- Local DB dry-runs on port 3307 show the sync schema is already applied and document counter backfill would seed the expected three counters without applying changes.
- `PosOrderService` counter/Moova isolation coverage passes in the combined suite, giving focused evidence for the class touched by this tranche.
- The deferred legacy runtime files remain separate:
  - `ajax/cofe_create_order.php` was not changed in the current worktree diff.
  - `do/doadd_invoice.php` is dirty, but its visible diff is save-only/payment behavior, not the sync counter foundation changes verified here.

## Regression and Safety Notes

- This pass did not alter product behavior. It only added this note.
- The implementation surfaces under test avoid unexpected sync regressions by keeping coverage focused on schema compatibility, counter allocation behavior, outbox reclaim semantics, cloud auth validation, shadow/apply response handling, Moova event cursoring, and Moova isolation in `PosOrderService`.
- Adjacent evidence is enough for the foundation tranche only: lint, focused PHPUnit, migration dry-run, backfill dry-run, and targeted diff hygiene all pass for the sync/Moova/counter surfaces.

## Residual Risks

- Browser/UI cashier save-pay-edit, table POS flow, shift/reporting screens, and legacy Cofe order creation were not live-smoked in this Worker because the requested verification list was code/DB focused and implementation/runtime files are dirty outside allowed write scope.
- Global whitespace hygiene remains blocked by pre-existing `item_categories.php:85` trailing whitespace.
- `do/doadd_invoice.php` and `ajax/cofe_create_order.php` still contain deferred legacy counter/runtime concerns from the broader plan; T009 does not resolve them.
- PHPUnit deprecation notices remain and should be reviewed separately if they become CI-blocking.

## Recommendation

Treat this as a passing focused regression pass for the local sync/Moova/counter foundation tranche, with residual audit needed before claiming full POS runtime parity or whole-goal completion.
