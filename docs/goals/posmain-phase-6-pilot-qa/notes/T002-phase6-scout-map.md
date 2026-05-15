# T002 Phase 6 Scout Map

Task: `T002`
Kind: `scout`
Status: `current`

## Summary

Phase 6 is not starting from zero: Phase 0-5 already provide route maps, production guard, backup/restore runbook, migration tooling, POS mutation/idempotency tests, mid-scale schema/services, and Moova reliability scenarios. The remaining local Phase 6 work is to add a safe CLI demo restaurant reset/seed harness, pilot QA scenario commands, load/concurrency checks, and pilot operating documents. Live printer proof, real Moova credentials, real cashier acceptance, and seven real service days remain external pilot evidence, not local implementation proof.

## Current Phase 6 Acceptance Items

Source: `/Users/ab.mansour1agmail.com/Desktop/pos expansion plan.txt` Phase 6 requires:

- P6-001: `tools/seed_demo_restaurant.php` with 3 categories, 50+ items, 10 modifier options, 20 tables across 2 areas, admin/manager/cashier/waiter users, cash/card/wallet methods, defaults/settings, and dummy Moova link in test mode.
- P6-002: browser E2E or documented local command for login, POS lock/unlock, takeaway sale, table save/add/payment/split/cancel, manager approval, shift open/close, receipt/KOT, and Moova accept/decline if enabled.
- P6-003: load/concurrency tests for simultaneous cashier sales, waiter table saves, same-table conflicts, duplicate payment submit, and 100 item searches.
- P6-004: pilot go-live checklist.
- P6-005: `docs/production/pilot_daily_review_template.md`.
- P6-006: pilot exit criteria.

## Evidence From Prior Phases

Useful completed artifacts:

- `docs/production/active_route_map.md` maps active login/POS/table/shift/Moova owners. It confirms `do/doadd_invoice.php`, `ajax/save_order.php`, `ajax/process_table_payment.php`, `ajax/process_split_payment.php`, clear/cancel/status endpoints, shift close handlers, and Moova confirm/change are the core Phase 6 E2E surfaces.
- `docs/production/backup_restore_runbook.md` already requires private-shell backups, staging/disposable restore, and smoke checks for login, POS, orders, payments, stock, journals, tables, and Moova mappings.
- `tools/branch_go_live_readiness.php` is already a non-destructive readiness gate requiring backup evidence, config checks, DB preflight unless skipped, and Moova acceptance evidence when automatic apply is enabled.
- `docs/production/moova_mode_decision.md` keeps the mid-scale pilot Moova mode as `direct_widget`; queued automatic apply remains off by default.
- `docs/production/moova_reliability_scenarios.md` already lists local Moova checks and explicitly names live Moova credentials, real cashier acceptance, hosted queued-worker rollout, and operational monitoring as pilot blockers outside local simulation.
- `classes/Sync/SchemaManager.php` already plans Phase 4/5 tables needed by the demo dataset: `order_fulfillment`, `item_availability`, modifier tables, line notes, `table_areas`, `payment_methods`, `manager_approvals`, drawer sessions/movements, printers, print jobs, nutrition profiles, sync, Moova inbound, and cloud snapshot tables.
- Phase 4 schema tests prove `table_areas`, `payment_methods`, modifier tables, drawer/print tables, and related legacy columns are intended to be created through `SyncSchemaManager`.
- Existing service tests show reusable patterns for temporary MariaDB test databases and fixture seeding: `tests/sync/pos_takeaway_order_service_test.php`, `tests/sync/pos_table_save_service_test.php`, payment/split/cancel tests, Phase 4 tests, and Moova tests.

## Current Demo Data Gap

There is an old browser route `setup_demo_data.php`, but it is not enough for Phase 6:

- It is a web route guarded by `production_guard_deny_route('setup_demo_data.php')`, not a CLI pilot seed tool.
- It inserts only five category labels and around 27 items, below the 50+ item Phase 6 target.
- It only checks table count; it does not seed 20 tables across two `table_areas`.
- It does not seed modifiers, payment methods, drawer/print defaults, users/roles, Moova dummy links, or realistic restaurant stock.
- It uses direct interpolated SQL strings in the web route. Phase 6 should not extend this route; create a CLI-only tool instead.

Safe direction:

- Add `tools/seed_demo_restaurant.php`.
- Require `PHP_SAPI === 'cli'`.
- Default to `--dry-run`.
- Require an explicit `--apply` for insert/upsert and an additional explicit reset flag before deleting/replacing existing demo rows.
- Refuse to run when `POSMAIN_PRODUCTION_MODE=1`.
- Use prefixes such as `P6-DEMO-` for barcodes/usernames/markers so reset can target only demo-owned rows.
- Use prepared statements and schema/column detection so old and migrated schemas both survive.
- Prefer additive upserts to destructive truncate/delete.

## Browser E2E Gap

The repo does not currently declare Playwright as a dependency in `package.json`; it only has Tailwind/PostCSS. `composer.json` is barcode-library focused with PHPUnit/phpstan as dev requirements. There are many PHP contract/integration tests, but no clear browser E2E harness for the Phase 6 cashier scenarios.

Safe direction:

- Start with a documented local E2E scenario runner or checklist before adding a new JS browser dependency.
- If automation is added, keep it isolated under `tools/` or `tests/phase6/` and make it optional when browser tooling is unavailable.
- Require the local Docker stack to be running before any browser claim. Current containers `posmain-php` and `posmain-mysql` exist but were stopped during this Scout pass.
- Use the in-app Browser or Playwright only after the stack and demo seed are verified.

## Load And Concurrency Gap

Existing tests already prove pieces of idempotency and state:

- `pos_takeaway_order_service_test.php` proves idempotent paid takeaway creation, same-key replay, conflict on changed payload, distinct counters, journals, process rows, and outbox.
- `pos_table_save_service_test.php` proves table save, occupied-table rejection, paid/partial remaining amount behavior, and document counter use.
- Table payment, split payment, cancel, Phase 4 move/merge/drawer tests, and Moova convergence tests exist as focused test surfaces.

Missing Phase 6 proof:

- A single load/concurrency runner that executes the Phase 6 scenarios together and reports duplicate `pro_id`, negative remaining amounts, stuck table state, duplicate payment behavior, and search response timing.
- A 100-search request check against `ajax/search_items.php` or `ajax/load_items_lazy.php`.
- A same-table race/conflict check with two simulated devices.

Safe direction:

- Add a CLI `tools/phase6_load_concurrency_check.php` or focused `tests/sync/phase6_load_concurrency_test.php` using disposable test DBs first.
- Reuse service-level calls for concurrency where possible; do not hit browser sessions until the local stack is intentionally running.
- Keep destructive or high-volume writes scoped to throwaway databases or `P6-DEMO-` prefixed rows.

## Pilot Documentation Gaps

Missing or not yet Phase 6-specific:

- `docs/production/pilot_go_live_checklist.md`
- `docs/production/pilot_daily_review_template.md`
- `docs/production/pilot_exit_criteria.md`
- A short command matrix tying seed, migrations, backup, readiness, E2E, load/concurrency, and Moova checks together.

These docs can reference existing artifacts rather than duplicating them:

- `docs/production/backup_restore_runbook.md`
- `docs/production/deployment_profile.md`
- `docs/production/moova_reliability_scenarios.md`
- `tools/branch_go_live_readiness.php`
- `tools/moova_cashier_acceptance_runner.php`

## AGENTS.md Pre-Change Checklist For Phase 6

Impacted surfaces:

- API contracts: login, POS invoice submit, table save/payment/split/cancel, shift close/Z close, receipt/KOT views, Moova confirm/change.
- Shared utilities: DB bootstrap/config, schema manager, password/security helpers, existing POS mutation services.
- Database access: `item_group`, `myitems`, `tables`, `table_areas`, `users`, `usr_pwrs`, `settings`, `acc_head`, `payment_methods`, modifier tables, Moova link tables, order/payment/test fixture tables.
- State shape: demo-owned categories/items/tables/users, table active state, order/payment counters, idempotency rows, drawer sessions, print jobs, Moova fulfillment metadata.
- UI flows: Arabic cashier POS, tables page, lock/unlock, manager approval, shift close, receipt/KOT, Moova widget review.
- Auth/permissions: seeded admin/manager/cashier/waiter roles, `moova.manage`, `pos.sell.takeaway`, `pos.payment.take`, manager approval permissions.
- Integrations: local Docker DB/PHP stack, optional browser automation, direct-widget Moova mode, backup/readiness tooling.

Compatibility risks:

- Demo reset must not delete real shop data.
- Seeded users/passwords must not become production credentials.
- Seeding default accounts/settings must not overwrite real accounting defaults.
- High-volume/concurrency checks must not run against production or stale real data.
- Browser E2E must not claim pass when it only checks static source or stopped containers.
- Moova E2E should stay in direct-widget pilot mode unless queued-worker blockers are cleared.

Focused tests to add/update:

- Seed tool dry-run/contract test.
- Seed tool apply test against a disposable DB.
- Phase 6 command matrix/doc test.
- Concurrency/load runner test or a test that validates the runner is non-production-safe and covers required assertions.
- Optional local E2E command/checklist test until a real browser harness exists.

Smallest safe increments:

1. Build and verify the CLI demo restaurant seed tool with dry-run and disposable-DB tests.
2. Add pilot go-live/daily-review/exit-criteria docs referencing existing readiness and Moova evidence.
3. Add a load/concurrency check using service-level throwaway DB fixtures.
4. Add documented E2E scenario command/checklist, then optionally automate browser checks after the local stack is running and seeded.
5. Run final Phase 6 verification and audit.

## Recommended Worker Sequence

T004 first: implement P6-001 seed tool because E2E/load/pilot checks need realistic data. Allowed files should be limited to:

- `tools/seed_demo_restaurant.php`
- `tests/sync/phase6_seed_demo_restaurant_test.php`
- `docs/goals/posmain-phase-6-pilot-qa/state.yaml`
- `docs/goals/posmain-phase-6-pilot-qa/notes/`

Verification:

- `php -l tools/seed_demo_restaurant.php`
- `php -l tests/sync/phase6_seed_demo_restaurant_test.php`
- `POSMAIN_TEST_MYSQL_PORT=3307 php tests/sync/phase6_seed_demo_restaurant_test.php`
- `git diff --check -- tools/seed_demo_restaurant.php tests/sync/phase6_seed_demo_restaurant_test.php`

T005 second: implement pilot docs. Allowed files:

- `docs/production/pilot_go_live_checklist.md`
- `docs/production/pilot_daily_review_template.md`
- `docs/production/pilot_exit_criteria.md`
- `tests/sync/phase6_pilot_docs_contract_test.php`

T006 third: implement load/concurrency runner. Allowed files:

- `tools/phase6_load_concurrency_check.php`
- `tests/sync/phase6_load_concurrency_check_test.php`

T007 fourth: implement E2E command matrix/checklist first, then browser automation only if tooling/runtime is ready. Allowed files should be decided after T004-T006 verification.

T999 final: Judge/PM audit against Phase 6 acceptance and current verification.

## Board Receipt Snippet

```yaml
receipt:
  result: done
  note: notes/T002-phase6-scout-map.md
  summary: "Mapped Phase 6 gaps. Prior phases provide route maps, backup/readiness, schema, POS/Moova service tests, and Moova scenario docs; remaining local work is a safe CLI demo seed tool, pilot docs, load/concurrency checks, and documented/automated E2E proof. First recommended Worker slice is P6-001 seed tooling with dry-run and disposable-DB tests."
```
