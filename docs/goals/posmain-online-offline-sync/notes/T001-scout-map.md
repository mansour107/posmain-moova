# T001 Scout Map

## Result

Done.

## Summary

Read-only Scout mapping completed. The regenerated plan is a large, high-risk local-first sync change and must start with a write-surface inventory before implementation. Current repo has Moova create/edit/cancel scaffolding and MariaDB/PHP test infrastructure, but no current Sync/Cloud implementation files, no `sync_outbox` or `document_counters` schema, and no focused coverage for the five blocking findings yet.

## Dirty Worktree

- Branch: `main`
- HEAD: `68eca7dfcb360a7da1d17ce851e949e826914a12`
- Pre-existing dirty files: `ajax/get_tables.php`, `close_shift.php`, `db/DB.sql`, `dist/css/pos_barcode.css`, `do/doadd_group.php`, `do/doadd_invoice.php`, `do/doadd_user.php`, `do/dodel_group.php`, `do/doedit_group.php`, `do/get_shift_preview.php`, `do_close_shift_z.php`, `elements/pos/cofe_widget.php`, `includes/pos_content.php`, `includes/pos_lock_system.php`, `includes/pos_login_screen.php`, `item_categories.php`, `js/pos_barcode.js`, `pos_barcode.php`, `setting.php`, `shift_sales_report.php`, `tables.php`, `update.php`.
- Goal files are newly untracked under `docs/goals/`.

Future Worker tasks must not overwrite the dirty POS/runtime files unless the active task explicitly allows them.

## Impacted Surfaces

- API contracts: plan adds `/api/sync/receive_branch_events.php`, `/api/sync/status.php`, `/api/moova/branch_events.php`, `/api/moova/ack_branch_events.php`, and `/api/moova/receive_external_event.php` around plan lines 114-129. Existing Moova widget endpoints are `ajax/moova_confirm_order.php` and `ajax/moova_change_order.php`.
- Shared utilities: `classes/PosOrderService.php` owns Moova order helpers and still uses `MAX()` counters around lines 1425-1448. `classes/MoovaPosIntegration.php` owns Moova schema/token/idempotency helpers and auth scope.
- Database access: plan requires `document_counters`, `sync_outbox`, `sync_inbox`, `sync_checkpoints`, `sync_conflicts`, `sync_worker_logs`, and `moova_pos_inbound_events` around plan lines 690-930. Current Moova SQL only has Moova POS link/order/change tables in `db/moova_pos_integration.sql`.
- State shape: plan adds UUID/sync columns to `ot_head` and related tables around plan lines 1142-1165. Current Moova state-hash guard exists in `moova_pos_order_links.last_pos_state_hash` and `ajax/moova_change_order.php`.
- UI flows: plan names `pos_barcode.php -> includes/pos_content.php -> js/pos_barcode.js -> do/doadd_invoice.php` as the direct cashier path. Dirty UI/POS files overlap this path.
- Auth/permissions: current Moova endpoints require POS session and device token. Plan requires HMAC branch/cloud sync validation.
- Integrations: Docker test topology uses MariaDB 10.11 and PHP built-in server mapped to 8010.

## Five Findings

- Expired `syncing` row reclaim: plan `sync_outbox` has `status`, `locked_by`, and `locked_until`, but the claim query only selects `pending` and `failed`. Expired `syncing` rows need a reclaim path and tests.
- Document counters: `do/doadd_invoice.php`, `ajax/cofe_create_order.php`, and `classes/PosOrderService.php` still use `MAX(pro_id)` or `MAX(journal_id)`. Counter migration must be two-step and must keep allocation inside business transactions.
- HMAC secret storage: plan requires cloud HMAC validation using branch secret but also says cloud stores only `sync_secret_hash`. Judge must resolve a protected-secret design before implementation.
- Shadow/apply semantics: plan starts with `POSMAIN_SYNC_SHADOW_MODE=1`, says apply-disabled returns `accepted_shadow`, and branch response handling does not include `accepted_shadow`. Need clear distinction between shadow apply and receive-only.
- Moova cursor schema: `cloud_moova_branch_events.cursor_value` is `NOT NULL`, but the plan says use `cursor_value=id`; this needs an insert-safe schema/cursor strategy.

## Verification

Commands inspected or run by Scout:

- `git status --short`
- `git branch --show-current`
- `git rev-parse HEAD`
- `rg` over write-path/counter/sync/HMAC/Moova patterns
- `php -l classes/PosOrderService.php`
- `php -l classes/MoovaPosIntegration.php`
- `php -l ajax/moova_confirm_order.php`
- `php -l ajax/moova_change_order.php`
- `php -l do/doadd_invoice.php`
- `php -l ajax/cofe_create_order.php`

All listed PHP lint checks passed.

Existing test infra:

- `composer.json` has `vendor/bin/phpunit`
- `phpunit.xml` runs `./tests`
- `docker-compose.posmain-test.yml` provides MariaDB/PHP topology
- Current tests include Moova payload hash and Moova isolation tests

Missing focused coverage:

- Expired `syncing` outbox reclaim
- Concurrent document counter allocation
- HMAC validation with protected secret design
- `accepted_shadow` worker response handling
- Distinct shadow/apply/receive-only behavior
- `cloud_moova_branch_events` cursor insert/poll/ack behavior
- Write-surface inventory required by the plan

## Recommended Next Tasks

- Add a Worker task for read-only/auditable write-surface inventory tooling and docs before sync implementation.
- Add a Judge task to resolve HMAC protected secret, shadow/apply semantics, and Moova cursor schema.
- Add a Worker task for migration scaffolding for `document_counters` and `sync_outbox` without POS behavior changes.
- Add a Worker task for focused outbox claim/reclaim tests and minimal claim service.

## Ambiguity For Judge

- No `AGENTS.md` file was found on disk, but the user supplied the Global Major Change Safety Rule and it governs this goal.
- Current dirty files may be user changes, prior work, or unrelated local work.
- HMAC verification cannot be implemented from only `sync_secret_hash`.
- Shadow mode and receive-only mode need exact semantics before Worker implementation.
- `cursor_value=id` needs an approved schema strategy.
- `ajax/cofe_create_order.php` legacy role needs confirmation before production behavior changes.
