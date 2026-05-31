# Recipe Rollout Readiness

Use this gate before widening recipe/BOM flags beyond read-only/shadow mode.

The command is intentionally non-destructive. It does not apply migrations, change feature flags, expire reservations, requeue sync rows, or write stock/accounting rows. It reads the recipe schema, current recipe flags, runtime preflight state, the operational dashboard signals, and optional pilot evidence.

Before browser/operator QA on a local or hosted runtime, run the preflight:

```sh
php tools/recipe_runtime_preflight.php --json
```

The preflight checks whether the runtime is prepared for browser/operator QA: PHP recipe dependencies such as `bcmath`, recipe schema state, active mode/flag consistency, key recipe UI/report/endpoint files, source guards, report links, and operator tools. It does not apply migrations, change flags, expire reservations, refresh availability, write recipe rows, write stock, or post accounting. Rollout readiness now also runs this preflight internally and blocks if it is not ready, so active flags cannot pass on evidence markers while operator surfaces, guards, or required decimal-math dependencies are missing.

To prepare repeatable browser/operator QA data on a migrated local or staging runtime, run the pilot fixture in dry-run mode first:

```sh
php tools/recipe_pilot_fixture.php --json
```

Apply it only to a QA database after reviewing the dry-run plan:

```sh
php tools/recipe_pilot_fixture.php --apply --json
```

After applying fixture data, run the read-only completeness check before browser/operator QA:

```sh
php tools/recipe_pilot_fixture.php --verify --json
```

The fixture tool creates only named `Recipe QA` items, modifier rows, active/draft fixture recipes, one draft production batch, fixture balances, opening-balance ledger rows, cost snapshots, and availability cache rows. It does not apply migrations, change feature flags, create customer orders, take payments, post accounting journals, enqueue sync rows, or update router metadata. Use its reported `POSMAIN_RECIPE_PILOT_ITEM_IDS` suggestion when enabling a local/staging pilot scope for cashier-browser and operator smoke evidence.

Apply mode refuses `POSMAIN_ENV=production`, `POSMAIN_ENV=prod`, or `POSMAIN_PRODUCTION_MODE=1` before opening a DB connection. Cloud/router-shaped staging runtimes also require an explicit hosted-staging acknowledgement:

```sh
php tools/recipe_pilot_fixture.php --apply --allow-hosted-staging --json
```

To run the fixed isolated runtime proofs required by the pilot evidence template and produce paste-ready evidence lines:

```sh
php tools/recipe_runtime_proof_suite.php --json
```

To generate a draft evidence bundle with the safe machine-verifiable lines prefilled:

```sh
php tools/recipe_pilot_evidence_bundle.php --json \
  --output=/absolute/path/to/recipe-pilot-evidence.md \
  --pos-tenant=0 \
  --pos-branch=0 \
  --store-id=0
```

The bundle tool is draft-only and not valid for rollout by itself. It runs only fixed local commands (`tools/recipe_runtime_preflight.php --json`, `tools/recipe_pilot_fixture.php --verify --json`, `tools/recipe_rollout_readiness.php --json`, and `tools/recipe_runtime_proof_suite.php --json` or `--all`), inherits the current runtime environment such as `POSMAIN_DB_PORT`, and leaves `Recipe Pilot Evidence: pending`, `Evidence completed at UTC: pending`, browser/operator detail lines, and checklist items pending. It does not apply migrations, change feature flags, log in, submit browser forms, create customer orders, write recipe rows, write stock, post accounting, or enqueue sync. Use it to reduce manual copying, then complete the real browser/operator evidence and validate the file with `tools/recipe_pilot_evidence.php --validate`.

The base proof suite now includes `Isolated cashier browser fixture smoke proof`, which runs `php tests/sync/recipe_cashier_browser_fixture_smoke_test.php` and verifies that `tools/recipe_cashier_browser_fixture.php --smoke --json` can render the real POS cashier page against a temporary browser fixture database. The same fixture also seeds paid reversible refund/void orders, verifies `ajax/get_recent_orders.php` exposes `can_refund`/`can_void`, verifies `ajax/refund_order.php` still rejects GET with `METHOD_NOT_ALLOWED`, POSTs one refund against the temporary order with idempotency replay so the temp database has `payment_status=refunded`, one `order.refunded` event, and one completed `pos_request_keys` row, then POSTs one void against a temporary paid table order with idempotency replay so the order is hidden, one `order.voided` event is recorded, and the table is released. Use `--include-availability`, `--include-manager-override`, `--include-moova-sync`, or `--all` when the pilot evidence needs the optional availability, manager override, and Moova/Cofe proof lines before those flags are active in the current environment. The suite does not accept arbitrary commands, apply migrations, change flags, use live orders, post accounting, or enqueue sync. It only invokes the fixed proof scripts listed below; each proof creates and drops its own temporary test database.

Operator QA should include the Inventory module waste/adjustment screen `inventory_adjustments.php` before active rollout. That page is the guarded Arabic storekeeper entry point for waste, manual increases, and manual decreases; it writes through `InventoryAdjustmentService`, requires `inventory.edit`, uses the `inventory_adjustment` CSRF boundary and `ajax/inventory_adjustment.php`, audits each ledger movement, supports optional waste photo evidence, and posts accounting only when inventory accounting flags/accounts are enabled.

For endpoint-level recipe management substitution proof without touching live recipes, run the isolated runtime test:

```sh
php tests/sync/recipe_modifier_substitution_management_endpoint_runtime_test.php
```

This test creates a temporary database, seeds only the minimal settings/user/role/item/schema rows needed by `recipe_manage.php`, executes the real management page in child PHP processes with a prepared admin session and `recipe_editor` CSRF token, creates a draft latte recipe, adds regular-milk, substitution-remove, and substitution-add lines, approves and activates it, then verifies the shared explosion service removes regular milk and adds oat milk when the modifier option is selected. It forces recipe mode to `shadow`, keeps accounting and availability disabled, drops the temporary database, and does not use real recipe rows, operator stock, customer orders, hosted shops, accounting journals, or sync queues. It still does not replace the required browser/operator modifier substitution UI smoke against prepared test data on the migrated runtime.

For endpoint-level waste/stock-adjustment regression proof without touching live stock, run the isolated runtime test:

```sh
php tests/sync/inventory_adjustment_endpoint_runtime_test.php
```

This test creates a temporary database, seeds only the minimal settings/user/role/item/ledger/balance schema needed by the Inventory module endpoint, executes the real `ajax/inventory_adjustment.php` endpoint in child PHP processes with a prepared admin session and `inventory_adjustment` CSRF token, verifies waste, idempotency replay, stock adjustment, balance updates, and service JSON responses, then drops the temporary database. It forces inventory ledger mode to `bridge`, keeps inventory accounting disabled, and does not use real operator stock. It still does not replace the required browser/operator waste and stock-adjustment pilot against prepared test data on the migrated runtime.

For endpoint-level production batch regression proof without touching live production stock, run the isolated runtime test:

```sh
php tests/sync/recipe_production_endpoint_runtime_test.php
```

This test creates a temporary database, seeds one active batch recipe, one ingredient, one prepared output item, and minimal production/ledger/balance schema, executes the real `recipe_production.php` page in child PHP processes with a prepared admin session and `recipe_production` CSRF token, verifies draft creation, commit, production input/output movements, batch lines, balance updates, and no duplicate movement writes after a committed replay, then drops the temporary database. It forces recipe mode to `consume_pilot`, scopes consumption to one pilot item, keeps accounting and availability disabled, and does not use real production stock. It still does not replace the required browser/operator production batch pilot against prepared test data on the migrated runtime.

For endpoint-level POS grid recipe availability proof without touching live orders, run the isolated runtime test:

```sh
php tests/sync/recipe_pos_grid_availability_endpoint_runtime_test.php
```

This test creates a temporary database, seeds two sellable items, two active make-to-order recipes, ingredient balances, manual availability schema, and recipe availability cache schema, executes the real `ajax/get_category_items.php` category endpoint in a child PHP process, verifies one recipe-unavailable payload, one low-stock recipe payload, cashier-facing reasons/gates, cache refresh, and no recipe cost leakage, then drops the temporary database. It forces recipe mode to `availability_pilot`, scopes availability to two pilot items, keeps Moova/menu sync and public cost payloads disabled, and does not use real cashier orders. It still does not replace the required cashier-browser POS grid availability pilot against prepared test data on the migrated runtime.

For endpoint-level manager recipe stock override proof without touching live orders or live approval rows, run the isolated runtime test:

```sh
php tests/sync/recipe_manager_override_endpoint_runtime_test.php
```

This test creates a temporary database, seeds only the minimal settings/towns/role/manager-approval schema needed by `ajax/manager_approval.php`, executes the real endpoint in child PHP processes with a prepared POS session and `pos_browser` CSRF token, verifies a permitted manager creates one approved `recipe.stock_override` approval with POS-grid metadata, verifies invalid CSRF is rejected, verifies a role without `edit_sales`/`edit_stock` is denied, and drops the temporary database. It forces recipe mode to `availability_pilot` with negative-stock approval enabled and does not use real cashier orders, recipe rows, stock, hosted shops, accounting journals, or sync queues. It still does not replace the required cashier-browser manager override smoke against prepared unavailable recipe items on the migrated runtime.

For service-level Moova/Cofe recipe replay proof without touching live Moova orders, run the isolated runtime test:

```sh
php tests/sync/recipe_moova_replay_runtime_test.php
```

This test creates a temporary database, seeds minimal Moova, table-order, recipe, ledger, and external line identity schema, then applies real `MoovaNewOrderApplyService` and `MoovaChangeOrderApplyService` calls through `PosOrderService` and `RecipeOrderLifecycleService`. It verifies new-order replay, two Moova provider orders sharing one legacy `fat_details` row, cancellation of only one provider order, cancellation replay, and payment replay without duplicate reservation release or recipe consumption. It forces recipe mode to `consume_pilot`, enables reservations/consumption only for one pilot item, disables accounting and public Moova/menu sync, and does not use real Moova orders, local `kody2` orders, hosted shops, accounting journals, or sync queues. It still does not replace the required Moova/Cofe operator pilot against prepared test data on the migrated runtime.

For endpoint-level Moova menu recipe availability payload proof without touching live menu consumers, run the isolated runtime test:

```sh
php tests/sync/recipe_moova_menu_sync_payload_endpoint_runtime_test.php
```

This test creates a temporary database, seeds minimal settings/catalog/schema plus one active recipe, one scoped recipe availability cache row, and one active Moova device-token link, then executes the real `ajax/moova_menu_sync_payload.php` endpoint in child PHP processes. It verifies recipe Moova/menu sync decorates the menu item with safe recipe availability fields, marks unavailable delivery items not orderable, uses the Moova link scope rather than default branch config, removes sensitive cost keys from the public JSON, and falls back to the legacy menu shape when `POSMAIN_RECIPE_MOOVA_SYNC=0`. It forces recipe mode to `availability_pilot`, enables Moova menu sync only for one pilot item and branch, drops the temporary database, and does not use live Moova menu consumers, local `kody2` menu data, hosted shops, accounting journals, or sync queues. It still does not replace the required Moova/Cofe operator pilot against prepared test data on the migrated runtime.

For endpoint-level legacy Cofe order creation replay proof without touching live Cofe orders, run the isolated runtime test:

```sh
php tests/sync/recipe_cofe_create_order_endpoint_runtime_test.php
```

This test creates a temporary database, seeds the minimal legacy invoice/accounting/catalog tables plus one active recipe and ingredient balance, starts PHP's built-in server against the real checkout, posts the same JSON payload to `ajax/cofe_create_order.php` twice, and verifies the replay returns the first order while creating one sale order, one cash receipt, one `fat_details` row, one external line map row, one consumed recipe usage, one recipe consumption movement, and one ingredient stock deduction. It forces recipe mode to `consume_pilot`, disables accounting and public Moova/menu sync, and does not use real Cofe orders, local `kody2` orders, hosted shops, accounting journals, or sync queues. It still does not replace the required Moova/Cofe operator pilot against prepared test data on the migrated runtime.

```sh
php tools/recipe_rollout_readiness.php --json
```

For active reservation/consumption/accounting/availability pilot modes, pass fresh pilot evidence:

```sh
php tools/recipe_rollout_readiness.php --json \
  --pilot-evidence-file=/absolute/path/to/recipe-pilot-evidence.md \
  --pos-tenant=0 \
  --pos-branch=0 \
  --store-id=0
```

The evidence file must match the active rollout mode and scope. If `--pos-tenant` or `--pos-branch` is omitted, readiness falls back to the configured branch identity (`POSMAIN_POS_TENANT` / `POSMAIN_POS_BRANCH`) and then the recipe pilot branch (`POSMAIN_RECIPE_PILOT_POS_BRANCH`) where applicable, so evidence from one shop or branch cannot approve another configured pilot by accident.

Create a pending evidence template before runtime QA:

```sh
php tools/recipe_pilot_evidence.php --template \
  --output=/absolute/path/to/recipe-pilot-evidence.md \
  --pos-tenant=0 \
  --pos-branch=0 \
  --store-id=0
```

Validate the completed evidence file before passing it to rollout readiness:

```sh
php tools/recipe_pilot_evidence.php --validate \
  --file=/absolute/path/to/recipe-pilot-evidence.md
```

For repeatable read-only recipe operator surface evidence on a local or hosted runtime, use an authenticated POS session cookie from the operator doing QA:

```sh
php tools/recipe_operator_surface_smoke.php \
  --base-url=http://127.0.0.1:8010 \
  --cookie='PHPSESSID=...' \
  --expect-mode-off \
  --json
```

If the session is captured with curl or a browser export, pass the cookie jar instead:

```sh
php tools/recipe_operator_surface_smoke.php \
  --base-url=http://127.0.0.1:8010 \
  --cookie-file=/private/tmp/posmain-cookies.txt \
  --expect-mode-off \
  --json
```

This smoke only performs authenticated GET requests against recipe operator/report pages. It accepts either a raw cookie header or a curl/Netscape cookie jar, checks expected headings, login redirects, access-denied/fatal/SQL text, and optional mode-off disabled messaging. It does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, enqueue sync, inspect browser console logs, or capture screenshots. Use its JSON output as supporting evidence; it complements, but does not replace, the required browser/operator QA checklist.

For service-level POS takeaway cashier payment proof without touching live cashier orders, run the isolated runtime test:

```sh
php tests/sync/pos_takeaway_order_service_test.php
```

This test creates a temporary database, applies the shared sync/recipe schema, seeds one paid takeaway cashier order with a recipe-enabled pilot item and ingredient balance, calls `PosOrderMutationService::createTakeawayOrder()` twice with the same idempotency key, and verifies the replay returns the first order while creating one sale order, one cash receipt flow, one consumed recipe usage, one recipe consumption movement, and one ingredient stock deduction. It also verifies the mixed cash/bank path still works and deducts the ingredient only for the recipe pilot item. It forces recipe mode to `consume_pilot`, disables accounting/availability/Moova sync, drops the temporary database, and does not use real cashier orders, local `kody2` orders, hosted shops, accounting journals, Moova payloads, or sync queues. It still does not replace the required cashier-browser add/pay pilot against prepared test data on the migrated runtime.

For handler-level POS takeaway cashier payment proof through the legacy invoice endpoint without touching live cashier orders, run:

```sh
php tests/sync/pos_takeaway_invoice_handler_test.php
```

This test creates a temporary database, applies the shared sync/recipe schema, seeds one recipe-enabled pilot item and ingredient balance, executes the real `do/doadd_invoice.php` handler in child PHP processes with POS recipe flags scoped to the pilot item, replays the same idempotency key, and verifies the replay does not create a second POS order, process row, recipe usage, recipe consumption movement, or ingredient deduction. It also starts PHP's built-in server against the same temporary database, posts a real authenticated POS form request with `pos_browser` CSRF to `/do/doadd_invoice.php`, verifies the receipt redirect, verifies one recipe usage/movement/deduction, then replays the same HTTP idempotency key. It also verifies the mixed cash/bank handler path still works and consumes only the pilot recipe item. It disables accounting/availability/Moova sync, drops the temporary database, and does not use real cashier orders, local `kody2` orders, hosted shops, accounting journals, Moova payloads, or sync queues. It still does not replace the required cashier-browser add/pay pilot against prepared test data on the migrated runtime.

For endpoint-level table save reservation proof without touching live table orders, run:

```sh
php tests/sync/pos_table_save_recipe_endpoint_runtime_test.php
```

This test creates a temporary database, applies the shared sync/recipe schema, seeds one recipe-enabled pilot item and ingredient balance, starts PHP's built-in server against the real app, POSTs JSON to the real `ajax/save_order.php` endpoint with a prepared POS session and `pos_browser` CSRF token, replays the same table-save idempotency key, and verifies the replay does not create a second order detail, order event, sync outbox row, recipe usage, or reservation movement. It verifies the saved table order remains active/unpaid, occupies the table, creates one reserved `recipe_order_line_usage`, records a reservation movement but no `recipe_consumption`, keeps ingredient `qty_on_hand=10.000000`, and sets `qty_reserved=2.000000`. It forces recipe mode to `consume_pilot`, disables accounting/availability/Moova sync, drops the temporary database, and does not use real table orders, local `kody2` orders, hosted shops, accounting journals, Moova payloads, or sync queues. It still does not replace the required cashier/table browser pilot against prepared test data on the migrated runtime.

For endpoint-level unpaid table cancel/clear/status-clear reservation-release proof without touching live table orders, run:

```sh
php tests/sync/pos_table_cancel_recipe_endpoint_runtime_test.php
```

This test creates a temporary database, applies the shared sync/recipe schema, seeds one recipe-enabled pilot item and ingredient balance, starts PHP's built-in server against the real app, creates unpaid table orders through the real `ajax/save_order.php` endpoint, then cancels one order through the real `ajax/delete_order.php` endpoint, clears another active table through the real `ajax/clear_table.php` endpoint, and clears a third active table through the real `ajax/update_table_status.php` endpoint with `action=clear`, all with a prepared POS session and `pos_browser` CSRF token. It replays the same table-cancel, table-clear, and table-status-clear idempotency keys and verifies the replays do not create second cancellation events, sync outbox rows, reservation-release movements, or idempotency rows. It verifies each order is cancelled/voided, each table is released, each recipe usage is released, each original reservation movement is paired with `reservation_release`, no `recipe_consumption` is written, ingredient `qty_on_hand=10.000000` remains unchanged, and cancel clears `qty_reserved=0.000000`. It forces recipe mode to `consume_pilot`, disables accounting/availability/Moova sync, drops the temporary database, and does not use real table orders, local `kody2` orders, hosted shops, accounting journals, Moova payloads, or sync queues.

For endpoint-level table payment recipe lifecycle proof without touching live table orders, run:

```sh
php tests/sync/pos_table_payment_recipe_endpoint_runtime_test.php
```

This test creates a temporary database, applies the shared sync/recipe schema, seeds one recipe-enabled pilot item, one ingredient balance, and one active table order through `PosOrderMutationService::saveTableOrder()`, verifies the unpaid table order reserves ingredient stock without reducing `qty_on_hand`, executes the real `ajax/process_table_payment.php` endpoint in child PHP processes with a prepared POS session and `pos_browser` CSRF token, replays the same table-payment idempotency key, and verifies the replay does not create a second payment row, order event, recipe usage, recipe consumption movement, or ingredient deduction. It also verifies full payment releases the table, clears `qty_reserved`, and reduces ingredient item `12` from `10.000000` to `8.000000`. It forces recipe mode to `consume_pilot`, disables accounting/availability/Moova sync, drops the temporary database, and does not use real table orders, local `kody2` orders, hosted shops, accounting journals, Moova payloads, or sync queues. It still does not replace the required cashier/table browser pilot against prepared test data on the migrated runtime.

For endpoint-level split-payment recipe lifecycle proof without touching live table orders, run:

```sh
php tests/sync/pos_split_payment_recipe_endpoint_runtime_test.php
```

This test creates a temporary database, applies the shared sync/recipe schema, seeds one recipe-enabled pilot item and ingredient balance, starts PHP's built-in server against the real app, creates an unpaid three-unit table order through the real `ajax/save_order.php` endpoint, then pays one selected unit through the real `ajax/process_split_payment.php` endpoint with a prepared POS session and `pos_browser` CSRF token. It replays the same split-payment idempotency key and verifies the replay does not create a second child payment row, order outbox row, recipe usage, recipe consumption movement, or ingredient deduction. It verifies the original order stays active/unpaid, the paid child order is completed/paid, the original recipe usage releases the old three-unit reservation and rebuilds the remaining two-unit reservation, the child recipe usage is consumed once, ingredient stock drops from `10.000000` to `9.000000`, and the split leaves `qty_reserved=2.000000` so later original payment can consume only the paid split quantity plus the remaining reservation without double-counting. It forces recipe mode to `consume_pilot`, disables accounting/availability/Moova sync, drops the temporary database, and does not use real table orders, local `kody2` orders, hosted shops, accounting journals, Moova payloads, or sync queues.

For an isolated cashier-browser add/pay fixture that also avoids live cashier orders, start the temporary browser runtime:

```sh
php tools/recipe_cashier_browser_fixture.php --json
```

Use the reported `pos_url` in a browser, log in as `fixture-cashier` with password `1234`, unlock the POS with code `1234`, click the seeded `Coffee` item, and press the payment confirm button. The fixture starts PHP's built-in server against a temporary database, seeds the extra catalog/account/fund/table/session rows needed for `pos_barcode.php`, enables recipe `consume_pilot` only for item `10`, and drops the database on exit. Before opening a browser, the temp-database smoke check can be run with:

```sh
php tools/recipe_cashier_browser_fixture.php --smoke --json
```

Current local fixture evidence: browser login/unlock succeeded against the temporary runtime, clicking `Coffee` added one cart row, clicking the visible payment confirm button submitted the real `posForm`, the temporary database contained one paid POS sale plus one receipt operation, exactly one consumed `recipe_order_line_usage`, exactly one `recipe_consumption` movement with `qty_out=1.000000`, and ingredient item `12` moved from `10.000000` to `9.000000`. The same fixture smoke now POSTs `ajax/refund_order.php` against seeded paid refund and void orders, replays the same idempotency keys, verifies the temporary refund order has one `order.refunded` event and one completed idempotency row, and verifies the temporary void order has one `order.voided` event, one completed idempotency row, `isdeleted=1`, and a released table. This still does not replace the required operator pilot against a migrated local/staging runtime with the real prepared recipe fixture scope, because it intentionally uses a throwaway database and no real shop data.

For repeatable read-only recipe management and modifier-substitution surface evidence, run the management smoke with the same authenticated operator session. Pass a prepared fixture recipe id when available so selected-recipe substitution controls are checked instead of only warning that no recipe was selected:

```sh
php tools/recipe_management_surface_smoke.php \
  --base-url=http://127.0.0.1:8010 \
  --cookie-file=/private/tmp/posmain-cookies.txt \
  --recipe-id=123 \
  --json
```

This smoke only performs authenticated GET requests against `recipe_manage.php` and `ajax/recipe_editor_lookup.php`. It checks the management page shell, optional selected-recipe modifier behavior/substitution group controls, lookup JSON shape, and lookup cost-key masking. If `--recipe-id` is omitted and selected-recipe substitution controls are not rendered, it records a fixture-selection warning instead of pretending the modifier-substitution UI was inspected. It does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, enqueue sync, approve recipes, activate recipes, inspect browser console logs, or capture screenshots. Use its JSON output as supporting evidence for management/substitution readiness; it still complements, but does not replace, the required browser/operator modifier substitution UI smoke against prepared test data.

For repeatable read-only production and waste/stock-adjustment surface evidence, run the stock-operations smoke with the same authenticated operator session. Pass a draft batch id when available so selected-batch commit/cancel controls are checked instead of only warning that no draft batch was selected:

```sh
php tools/recipe_stock_operations_surface_smoke.php \
  --base-url=http://127.0.0.1:8010 \
  --cookie-file=/private/tmp/posmain-cookies.txt \
  --batch-id=123 \
  --json
```

This smoke only performs authenticated GET requests against `recipe_production.php` and the Inventory module waste/adjustment screen `inventory_adjustments.php`. It checks production draft controls, optional selected-batch commit/cancel controls, the Arabic waste/adjustment controls, mode-off messaging when present, and fatal/SQL/access-denied text. If `--batch-id` is omitted and selected-batch controls are not rendered, it records a fixture-selection warning instead of pretending production commit UI was inspected. It does not log in, submit forms, create batches, commit batches, cancel batches, record waste, record adjustments, apply migrations, change feature flags, write recipe rows, write stock, post accounting, enqueue sync, inspect browser console logs, or capture screenshots. Use its JSON output as supporting evidence for production and waste surface readiness; it still complements, but does not replace, required browser/operator production batch and waste/adjustment pilots against prepared test data.

For repeatable read-only recipe report export evidence, run the CSV export smoke with the same authenticated session:

```sh
php tools/recipe_report_export_smoke.php \
  --base-url=http://127.0.0.1:8010 \
  --cookie-file=/private/tmp/posmain-cookies.txt \
  --json
```

This smoke only performs authenticated GET requests against recipe CSV exports. It checks CSV response headers, expected columns, login redirects, access-denied/fatal/SQL text, and spreadsheet-formula-safe exported cells. It does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, enqueue sync, inspect browser console logs, or capture screenshots.

For repeatable read-only POS grid recipe availability surface evidence, run the POS grid availability smoke with the same authenticated cashier/operator session:

```sh
php tools/recipe_pos_grid_availability_surface_smoke.php \
  --base-url=http://127.0.0.1:8010 \
  --cookie-file=/private/tmp/posmain-cookies.txt \
  --category-id=7 \
  --json
```

This smoke only performs authenticated GET requests against the POS grid recipe availability surface. It checks that the unlocked POS page exposes item-card availability data attributes, that `js/pos_barcode.js` contains the add-gate/unavailable-message flow, and that an optional `ajax/get_category_items.php` category payload includes recipe availability fields without leaking internal cost keys. If the POS barcode gate is shown, it records an operator-unlock warning instead of pretending the cashier UI was inspected. It does not log in, submit forms, add items, apply migrations, change feature flags, write recipe rows, write stock, post accounting, request manager approvals, enqueue sync, click items, create orders, inspect browser console logs, or capture screenshots. A runtime with no prepared recipe item in the selected category may pass with a warning; the full pilot evidence still needs a real cashier-browser POS grid availability scenario against prepared recipe items before active rollout.

For repeatable read-only paid refund/void POS surface evidence, run the paid reversal smoke with the same authenticated cashier/operator session:

```sh
php tools/recipe_paid_reversal_surface_smoke.php \
  --base-url=http://127.0.0.1:8010 \
  --cookie-file=/private/tmp/posmain-cookies.txt \
  --json
```

This smoke only performs authenticated GET requests against the paid refund/void POS surface. It checks that the POS page exposes the recent-orders loader and paid reversal controls when the cashier page is already unlocked, that `ajax/get_recent_orders.php` returns the `can_refund`/`can_void` capability shape when recent orders exist, and that `ajax/refund_order.php` rejects a plain GET with `METHOD_NOT_ALLOWED` before any mutation path. If the POS barcode gate is shown, it records an operator-unlock warning instead of pretending the cashier UI was inspected. It does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, enqueue sync, click buttons, confirm dialogs, issue mutations, inspect browser console logs, or capture screenshots. A runtime with no recent paid reversible order may pass with a warning; the full pilot evidence still needs a real cashier-browser refund/void scenario against prepared test data before active rollout.

For repeatable read-only manager recipe stock override POS surface evidence, run the manager override smoke with the same authenticated cashier/operator session:

```sh
php tools/recipe_manager_override_surface_smoke.php \
  --base-url=http://127.0.0.1:8010 \
  --cookie-file=/private/tmp/posmain-cookies.txt \
  --category-id=7 \
  --json
```

This smoke only performs authenticated GET requests against the manager recipe stock override POS surface. It checks that the POS page exposes the override permission bootstrap when the cashier page is already unlocked, that `js/pos_barcode.js` contains the `requestRecipeStockOverride` flow and posts approval ids back through `itmmanagerapproval[]`, that an optional `ajax/get_category_items.php` category payload exposes manager-override availability fields, and that `ajax/manager_approval.php` rejects a plain GET with `METHOD_NOT_ALLOWED` before any approval path. If the POS barcode gate is shown, it records an operator-unlock warning instead of pretending the cashier UI was inspected. It does not log in, submit forms, request approvals, apply migrations, change feature flags, write recipe rows, write stock, post accounting, enqueue sync, click buttons, approve prompts, issue mutations, inspect browser console logs, or capture screenshots. A runtime with no prepared manager-override item in the selected category may pass with a warning; the full pilot evidence still needs a real cashier-browser manager override scenario against prepared unavailable recipe items before active rollout.

For endpoint-level paid reversal regression proof without touching live orders, run the isolated runtime test:

```sh
php tests/sync/recipe_paid_reversal_endpoint_runtime_test.php
```

This test creates a temporary database, seeds paid refund/void orders, executes the real `ajax/refund_order.php` endpoint in a child PHP process with a prepared session and `pos_browser` CSRF token, verifies idempotency replay and order-event writes, then drops the temporary database. It intentionally disables sync outbox for the isolated child run and does not use real operator orders. It still does not replace the required cashier-browser refund/void pilot against prepared test data on the migrated runtime.

Template generation is intentionally not enough to pass readiness. Generated markers, detail lines, and isolated runtime proof lines start as `pending`, `Evidence completed at UTC` starts as `pending`, and operator QA checklist items start unchecked. Operators should only replace a marker with the readiness success word after that check was actually performed and reviewed. Each required detail line must also include a real command result, URL/export name, order id, review note, or operator sign-off; `pending`, `pass`, `todo`, `tbd`, and blank details are rejected. Each isolated runtime proof line must include both the relevant test command path and its success marker from the current run. Each required checklist item must be checked as `- [x] ...` or recorded as `...: pass`. The completed-at timestamp is validated from the evidence content itself, so touching an old file does not make stale evidence fresh.

Generated templates and `--list-markers --json` include evidence command hints for the current recipe flags. These hints are only operator guidance; they do not complete a detail line, mark a checklist item, or satisfy rollout readiness by themselves. The operator still has to paste the actual reviewed command result, browser/order id, export review, movement/batch id, or sign-off into the matching evidence line.

Full rollout mode cannot pass accidentally:

```sh
php tools/recipe_rollout_readiness.php --json \
  --allow-full-mode \
  --pilot-evidence-file=/absolute/path/to/recipe-pilot-evidence.md
```

Public cost payloads are blocked unless an operator explicitly adds `--allow-cost-public-payloads`.

## Required Evidence Markers

The pilot evidence file must contain exact markers for the active recipe mode being checked.

For `reserve_only`, the required markers are:

- `Recipe mode: reserve_only`
- `Evidence completed at UTC: <YYYY-MM-DDTHH:MM:SSZ>` within the allowed evidence age.
- `POS tenant: <tenant>`, `POS branch: <branch>`, and `Store: <store>` matching any scope filters passed to rollout readiness.
- `Recipe Pilot Evidence: pass`
- `Recipe schema migrated or verified: pass`
- `Recipe runtime preflight reviewed: pass`
- `Recipe operational dashboard reviewed: pass`
- `Recipe stock reconciliation reviewed: pass`
- `Recipe reservation lifecycle smoke passed: pass`
- `Recipe rollback flags documented: pass`

For active recipe consumption/accounting/availability/full modes, the required markers are:

- `Recipe mode: <current mode>` matching the active rollout mode.
- `Evidence completed at UTC: <YYYY-MM-DDTHH:MM:SSZ>` within the allowed evidence age.
- `POS tenant: <tenant>`, `POS branch: <branch>`, and `Store: <store>` matching any scope filters passed to rollout readiness.
- `Recipe Pilot Evidence: pass`
- `Recipe schema migrated or verified: pass`
- `Recipe runtime preflight reviewed: pass`
- `Recipe operational dashboard reviewed: pass`
- `Recipe stock reconciliation reviewed: pass`
- `POS/table recipe smoke passed: pass`
- `Recipe rollback flags documented: pass`

When recipe accounting is enabled, it must also contain:

- `Recipe COGS accountant review: pass`

When recipe availability is enabled, it must also contain:

- `Recipe availability and menu sync smoke passed: pass`

## Required Evidence Details

The same file must include non-placeholder detail lines for:

- `Recipe schema evidence`
- `Recipe runtime preflight evidence`
- `Pilot fixture verification evidence`
- `Recipe operational dashboard evidence`
- `Recipe stock reconciliation evidence`
- `POS/table smoke evidence`
- `Migrated runtime write smoke evidence`
- `Recipe report export and role QA evidence`
- `Modifier substitution recipe evidence`
- `Production batch evidence`
- `Waste and stock adjustment evidence`
- `Paid refund/void evidence`
- `Recipe rollback evidence`

For `reserve_only`, the detail list is intentionally narrower and must include:

- `Recipe schema evidence`
- `Recipe runtime preflight evidence`
- `Pilot fixture verification evidence`
- `Recipe operational dashboard evidence`
- `Recipe stock reconciliation evidence`
- `Recipe reservation evidence`
- `Recipe rollback evidence`

`Recipe reservation evidence` must prove that the pilot reservation lifecycle was reviewed, including `stock_reservations` and `qty_reserved`, or include the isolated proof command result from `tests/sync/recipe_reservation_lifecycle_runtime_test.php` with `recipe-reservation-lifecycle-runtime-ok`.

`Pilot fixture verification evidence` must include the `tools/recipe_pilot_fixture.php --verify --json` command result or the `fixture_ready_for_operator_qa` field from that read-only verification output.

`Migrated runtime write smoke evidence` must include `tools/recipe_migrated_write_smoke.php` output from a local/staging QA DB and prove `idempotency_replayed`, `recipe_consumption` movements, positive movement cost, and a passing stock preflight for the selected QA fixture store.

High-risk detail lines such as production batches, waste/stock adjustments, paid refund/void, migrated runtime write smoke, Moova/Cofe replay, manager override, hosted schema, report export, stock reconciliation, and runtime preflight must include recognizable command, endpoint, report, generated result token, order/movement/batch reference, or tool output tied to that check. Combined checks must prove the relevant parts together, for example POS/table evidence must name both a POS order and a table order, and waste/adjustment evidence must name both a waste movement and a stock adjustment. Generic `reviewed`, `pass`, or sign-off-only text is not enough for those detail lines.

To see the exact accepted token groups for the current recipe mode, run:

```sh
php tools/recipe_pilot_evidence.php --list-markers --json
```

The JSON `detail_token_requirements` object lists each high-risk detail and the token groups that can satisfy it.

When recipe accounting is enabled, it must also include:

- `Recipe COGS accountant evidence`

When recipe availability is enabled, it must also include:

- `Recipe availability and menu sync evidence`

When recipe Moova/menu sync is enabled, it must also include:

- `Moova/Cofe recipe replay evidence`

When negative recipe stock is allowed with manager approval, it must also include:

- `Manager recipe stock override evidence`

When the runtime is hosted/cloud (`POSMAIN_ROLE=cloud` or `POSMAIN_ROLE=fake_cloud`) or the single-domain router is enabled, it must also include:

- `Hosted/cloud runtime schema evidence`

Generate supporting hosted/router schema evidence with:

```sh
php tools/recipe_hosted_schema_preflight.php --json
```

With `POSMAIN_ROUTER_ENABLED=1`, the command checks active routed shop databases unless `--shop-id=...` or `--current-db-only` is supplied. With router mode off, it checks the current configured DB. It is read-only: it does not install router tables, validate shops, apply migrations, change feature flags, write recipe rows, write stock, post accounting, update router metadata, or enqueue sync.

For regression proof that routed shop databases are actually walked, run:

```sh
php tests/sync/recipe_hosted_schema_preflight_router_runtime_test.php
```

This test creates temporary router/shop databases, applies the normal schema to two temporary shop DBs, registers both shops through the router CLI, runs `tools/recipe_hosted_schema_preflight.php --json` in router mode, verifies both routed shop targets are ready with zero pending schema and no missing recipe tables, then drops the temporary databases.

## Required Operator QA Checklist

The same file must include checked QA lines for:

- `Recipe management UI smoke`
- `Modifier substitution recipe UI smoke`
- `Recipe report export and role QA smoke`
- `Production batch UI smoke`
- `Waste and stock adjustment UI smoke`
- `POS/table lifecycle smoke`
- `Migrated runtime write smoke`
- `Paid refund/void smoke`

For `reserve_only`, the required checklist is intentionally narrower:

- `Recipe reservation lifecycle smoke`

When recipe accounting is enabled, it must also include:

- `Recipe accounting journal review`

When recipe availability is enabled, it must also include:

- `Recipe availability POS and menu sync smoke`

When recipe Moova/menu sync is enabled, it must also include:

- `Moova/Cofe recipe replay smoke`

When negative recipe stock is allowed with manager approval, it must also include:

- `Manager recipe stock override smoke`

## Required Isolated Runtime Proofs

The same file must include non-placeholder isolated runtime proof command results for:

- `Isolated cashier browser fixture smoke proof`: `php tests/sync/recipe_cashier_browser_fixture_smoke_test.php`
- `Modifier substitution management endpoint runtime proof`: `php tests/sync/recipe_modifier_substitution_management_endpoint_runtime_test.php`
- `Production endpoint runtime proof`: `php tests/sync/recipe_production_endpoint_runtime_test.php`
- `Waste and stock adjustment endpoint runtime proof`: `php tests/sync/inventory_adjustment_endpoint_runtime_test.php`
- `Paid refund/void endpoint runtime proof`: `php tests/sync/recipe_paid_reversal_endpoint_runtime_test.php`

For `reserve_only`, the required isolated proof is intentionally narrower:

- `Recipe reservation lifecycle runtime proof`: `php tests/sync/recipe_reservation_lifecycle_runtime_test.php`

When recipe availability is enabled, it must also include:

- `POS grid availability endpoint runtime proof`: `php tests/sync/recipe_pos_grid_availability_endpoint_runtime_test.php`

When negative recipe stock is allowed with manager approval, it must also include:

- `Manager recipe stock override endpoint runtime proof`: `php tests/sync/recipe_manager_override_endpoint_runtime_test.php`

When recipe Moova/menu sync is enabled, it must also include:

- `Moova menu sync payload endpoint runtime proof`: `php tests/sync/recipe_moova_menu_sync_payload_endpoint_runtime_test.php`
- `Moova/Cofe replay runtime proof`: `php tests/sync/recipe_moova_replay_runtime_test.php`
- `Legacy Cofe endpoint runtime proof`: `php tests/sync/recipe_cofe_create_order_endpoint_runtime_test.php`

The proof suite prints these lines in the accepted evidence format:

```sh
php tools/recipe_runtime_proof_suite.php --all
```

For a migrated local/staging runtime, the controlled write-capable pilot proof is:

```sh
POSMAIN_ENABLE_RECIPES=1 \
POSMAIN_RECIPE_MODE=consume_pilot \
POSMAIN_RECIPE_CONSUMPTION=1 \
POSMAIN_RECIPE_ACCOUNTING=0 \
POSMAIN_RECIPE_AVAILABILITY=0 \
POSMAIN_RECIPE_MOOVA_SYNC=0 \
POSMAIN_RECIPE_PILOT_POS_BRANCH=0 \
POSMAIN_RECIPE_PILOT_ITEM_IDS=987672,987676 \
php tools/recipe_migrated_write_smoke.php --json --apply --run-id=<unique-local-run-id>
```

The command is dry-run by default without `--apply`. Apply mode must only be used on local or staging QA databases. It verifies the Recipe QA fixture for the selected positive POS store, preflights required recipe ingredients against `inventory_item_balances`, creates one named paid takeaway order through `PosOrderMutationService`, replays the same idempotency key, proves no duplicate order/recipe consumption, verifies positive recipe costs, and disables sync outbox recording for the smoke request.

If the selected Recipe QA fixture stock has already been consumed by earlier proof runs, replenish only the named QA fixture item through the stock-adjustment service before rerunning the migrated write smoke:

```sh
POSMAIN_ENABLE_RECIPES=1 \
POSMAIN_RECIPE_MODE=consume_pilot \
POSMAIN_RECIPE_CONSUMPTION=1 \
POSMAIN_RECIPE_ACCOUNTING=0 \
POSMAIN_INVENTORY_LEDGER_MODE=bridge \
php tools/recipe_fixture_stock_adjustment.php --json --apply --run-id=<unique-local-run-id> --barcode=RQA-CUP --qty=3 --store-id=<pilot-store-id>
```

This tool is dry-run by default, refuses production/hosted runtimes unless explicitly allowed, refuses non-Recipe-QA items, requires a writable inventory ledger mode, writes only through `InventoryAdjustmentService`, replays the same adjustment UUID to prove idempotency, and does not update balances directly.

## Blocks Rollout

The readiness tool blocks rollout for:

- Missing recipe schema tables, including `external_order_line_map` for Moova/Cofe replay identity.
- Runtime preflight not ready, including pending migrations, missing recipe runtime tables, missing operator pages/tools, missing source guards, or missing report links.
- Non-off recipe mode while `POSMAIN_ENABLE_RECIPES` is off.
- `full` mode without `--allow-full-mode`.
- Public cost payloads without `--allow-cost-public-payloads`.
- Active recipe modes whose matching runtime flags are disabled, such as `reserve_only` without reservations, `consume_pilot` without consumption, `accounting_pilot` without accounting, or `availability_pilot`/`full` without availability.
- Strict stock without recipe availability.
- Strict stock with recipe availability configured in a mode where computed availability is not effective, such as `read_only`, `shadow`, `reserve_only`, or `consume_pilot`.
- Negative recipe stock manager approval without recipe availability.
- Strict stock enabled together with negative recipe stock manager approval.
- Pilot modes, including `reserve_only`, without an explicit pilot branch, pilot item, or pilot category scope.
- Category-only pilot scopes that cannot identify an item's category at runtime do not activate recipe consumption, accounting, availability, or menu-sync behavior for that item.
- Reserve-only and other pilot reservation modes use the same branch/item/category scope gate before creating usage rows, stock reservations, or reservation movement rows.
- Missing COGS, raw inventory, prepared inventory, packaging inventory, waste expense, or production variance accounts when recipe accounting is enabled.
- Stale reservations.
- Negative recipe inventory balances.
- Invalid inventory movement rows, including impossible in/out quantities, negative persisted cost/quantity fields, invalid unit conversion, blank idempotency keys, or blank movement/source enums.
- Active recipe setup issues.
- Consumed recipe usage rows without linked ingredient movements.
- Active recipes missing cost snapshots in active consumption/accounting/availability modes.
- Recipe availability cache gaps when availability is enabled.
- Failed/dead menu availability sync rows when recipe Moova sync is enabled.
- Pending/syncing menu availability sync rows when recipe Moova sync is enabled.
- Recipe Moova/menu sync enabled while recipe availability is off.
- Recipe Moova/menu sync enabled while `POSMAIN_MENU_SYNC_ENABLED` is off.
- Recipe Moova/menu sync enabled while neither sync outbox nor hosted cloud-to-branch publishing is enabled.
- Recipe Moova/menu sync using branch outbox without branch role, branch UUID, cloud base URL, branch sync secret, branch sync enablement, or sync worker enablement.
- `POSMAIN_RECIPE_PRODUCTION_VARIANCE_POLICY=post_variance` without active recipe accounting.
- Missing, unreadable, stale, or incomplete pilot evidence for active modes.

If schema is missing or pending, readiness reports the schema/preflight blockers and skips operational dashboard queries until the schema is ready. This avoids hiding a real migration mismatch behind a generic database/query failure.

## Rollback Reminder

Rollback remains flag-based:

```env
POSMAIN_RECIPE_MODE=off
POSMAIN_RECIPE_CONSUMPTION=0
POSMAIN_RECIPE_ACCOUNTING=0
POSMAIN_RECIPE_AVAILABILITY=0
POSMAIN_RECIPE_MOOVA_SYNC=0
POSMAIN_RECIPE_PRODUCTION_VARIANCE_POLICY=adjust_unit_cost
```
