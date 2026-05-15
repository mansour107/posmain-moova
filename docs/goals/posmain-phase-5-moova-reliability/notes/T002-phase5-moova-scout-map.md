# T002 Phase 5 Moova Scout Map

## Current Evidence

Phase 5 scope is the Moova reliability tranche from `/Users/ab.mansour1agmail.com/Desktop/pos expansion plan.txt` lines 1943-2063: choose pilot mode, unify direct and queued mutation behavior, protect visible tokens, add delivery foundations, improve cashier UX, and prove the required scenarios.

The repo already has a Phase 5 Goal Maker board at `docs/goals/posmain-phase-5-moova-reliability/`. The checker passed with `active_task=T002`.

Phase 0-4 docs establish these constraints:

- `docs/production/write_surface_classification.md` classifies `ajax/moova_confirm_order.php` and `ajax/moova_change_order.php` as active A-class Moova write paths.
- `docs/production/active_route_map.md` maps the POS widget to those two endpoints and `moova_pos_proxy.php`.
- `docs/production/api_contracts_pos.md` says direct widget and queued worker apply must converge through one local mutation contract.
- `docs/production/permission_matrix.md` defines `moova.manage` for integration management and exempts device-token Moova machine endpoints from browser CSRF.
- `docs/production/credential_rotation.md` says Moova device tokens must not be committed and require private rotation handling.

## Current Direct And Queued Apply Map

Direct widget path:

- `elements/pos/cofe_widget.php` embeds the local widget, sends `cofe.init`, and posts confirmed orders to `ajax/moova_confirm_order.php`.
- `elements/pos/cofe_widget.php` posts edit/cancel requests to `ajax/moova_change_order.php` after cashier confirmation.
- `ajax/moova_confirm_order.php` validates POS session, `X-Moova-Device-Token`, idempotency key, and tenant/branch link, then calls `PosOrderMutationService::confirmMoovaOrder`.
- `ajax/moova_change_order.php` validates POS session, token, action, order id, cashier confirmation, request hash, and link, then calls `PosOrderMutationService::changeMoovaOrder`.
- `classes/Pos/Service/PosOrderMutationService.php` delegates to `MoovaNewOrderApplyService` and `MoovaChangeOrderApplyService`.

Queued path:

- `classes/Moova/MoovaLocalIngestService.php` normalizes provider payloads, event types, idempotency keys, hashes, and POS payloads.
- `classes/Sync/MoovaInboundQueueService.php` stores inbound events, dedupes by `(tenant, branch, idempotency_key)` or event uuid, and records conflicts.
- `classes/Sync/BranchMoovaApplyWorker.php` claims inbound events, normalizes them to POS payloads, and calls the same `MoovaNewOrderApplyService` or `MoovaChangeOrderApplyService` with `response_mode=queued`.
- `classes/Moova/MoovaNewOrderApplyService.php` owns `moova_pos_order_links`, duplicate replay, payload-hash conflicts, POS table order creation/merge, and state-hash storage.
- `classes/Moova/MoovaChangeOrderApplyService.php` owns `moova_pos_order_change_links`, duplicate replay, stale state decline, edit/cancel delegation, and order/table outbox snapshots.

Important gap: queued events pass through `MoovaLocalIngestService` normalization before apply, while direct widget endpoints currently accept widget-shaped payloads and direct endpoint hashes. This is mostly converged at apply-service level, but the same provider event can still have direct-vs-queued hash drift unless the direct endpoint uses the same normalizer or tests prove hash equivalence.

## Token Visibility And Security Map

Current implementation:

- `moova_integration.php` displays the full token when `MoovaPosIntegration::userCanManageIntegration` returns true.
- `ajax/moova_save_integration.php` checks CSRF and either `userCanManageIntegration` or `auth_guard_has_permission('moova.manage')`, then audits save success.
- `ajax/moova_disconnect_integration.php` uses the same management check and audits disconnect success.
- `MoovaPosIntegration::saveActiveLinkForScope` stores full token plus hash and last4.
- `moova_pos_proxy.php` uses the token server-side for the widget bridge.

Gaps:

- The page-level display gate uses `userCanManageIntegration`, not the Phase 3 named permission helper directly.
- A successful token view is not audited separately from page load.
- Token storage is not encrypted at rest. Phase 5 can document the risk and restrict access first; encryption is feasible later but is a larger migration.
- No token rotation runbook file exists yet beyond generic credential rotation guidance.

## Delivery Foundation Map

Current delivery evidence:

- `do/doadd_invoice.php` reads `delivery_customer_name`, `delivery_customer_phone`, and `delivery_customer_address` from POST.
- `includes/pos_content.php` has matching delivery UI fields.
- There is no `order_fulfillment` table or standard Moova delivery metadata service.
- `RestaurantReportContractService` already has report contract language for order-channel splits, but no durable Moova delivery metadata source was found.

Safe Phase 5 direction: create an auxiliary `order_fulfillment` table through `SyncSchemaManager`, plus a narrow service to upsert fulfillment metadata from Moova apply. Avoid risky `ot_head` churn for the first Phase 5 slice.

## Cashier UX And Test Coverage

Current UX evidence:

- `assets/moova-pos-widget/pos-widget.js` has Arabic and English reachability/session/link messages.
- `moova_pos_proxy.php` returns structured `MOOVA_UNREACHABLE` errors with `retryable=true`.
- `elements/pos/cofe_widget.php` carries direct/queued metadata fields back to the widget and preserves cashier review for changes.
- `deploy/branch-worker/moova-cashier-acceptance.md.example` exists, and `tools/moova_cashier_acceptance_runner.php` can generate local mock-backed acceptance evidence.

Current tests found:

- `tests/sync/moova_local_ingest_service_test.php`
- `tests/sync/moova_inbound_queue_test.php`
- `tests/sync/branch_moova_apply_worker_test.php`
- `tests/sync/moova_confirm_change_routing_test.php`
- `tests/sync/moova_pos_mutation_convergence_test.php`
- `tests/sync/moova_widget_reachability_messages_test.php`
- `tests/sync/moova_admin_security_test.php`
- `tests/sync/moova_cashier_acceptance_runner_test.php`

Some older tests still expect direct endpoints to instantiate `MoovaNewOrderApplyService` or `MoovaChangeOrderApplyService` directly, while newer tests expect routing through `PosOrderMutationService`. Treat these as stale test expectations that must be cleaned when the relevant worker slice touches them.

## AGENTS.md Pre-Change Checklist For Phase 5

Impacted surfaces:

- API contracts: `ajax/moova_confirm_order.php`, `ajax/moova_change_order.php`, `moova_pos_proxy.php`, widget postMessage result payloads.
- Shared utilities: `config/app_config.php`, `.env.example`, `includes/auth_guard.php`, `classes/Security/SecurityAuditLogger.php`.
- Database access: `moova_pos_shop_links`, `moova_pos_order_links`, `moova_pos_order_change_links`, `moova_pos_inbound_events`, possible new `order_fulfillment`.
- State shape: Moova order links, state hashes, fulfillment metadata, sync result metadata.
- UI flows: Arabic POS widget, Moova admin page, cashier accept/decline/edit/cancel.
- Auth/permissions: `moova.manage`, POS session, device token, token view auditing.
- Integrations: direct widget bridge, queued branch worker, Moova cloud ack, local proxy.

Compatibility risks:

- Same provider event delivered direct and queued must not conflict or create two POS mutations.
- Stale edit/cancel must decline, not mutate.
- Token protection must not violate the business requirement that authorized managers can view the full token.
- Delivery metadata must not disturb existing `ot_head` reports or cashier sale flows.
- Queued apply must remain disabled by default unless worker supervision is configured.

Focused tests to add or update:

- Direct-vs-queued idempotency/hash convergence test.
- Moova mode flag/default test.
- Token view permission and audit test.
- Delivery fulfillment schema/service test.
- Required Moova scenario matrix test or runner evidence contract.

Smallest safe increments:

1. Write `docs/production/moova_mode_decision.md` and align defaults without enabling queued apply.
2. Unify direct endpoint normalization with `MoovaLocalIngestService` and prove direct/queued duplicate safety.
3. Add token-view audit/permission guard and rotation documentation.
4. Add `order_fulfillment` schema/service and wire only Moova metadata.
5. Complete scenario tests and acceptance evidence docs.

Adjacent regression checks:

- Syntax check every changed PHP file.
- Run Moova focused tests after each slice.
- Run `git diff --check` on each slice's allowed files.
- Keep `git status --short` snapshots in receipts because the worktree contains substantial pre-existing Phase 3/4 dirty work.

## Recommended Worker Sequence

T005 first: P5-001 mode decision and flags. Allowed files should be limited to `docs/production/moova_mode_decision.md`, `.env.example`, `config/app_config.php`, and one focused config/mode test.

T006 second: P5-002 direct/queued convergence. Allowed files should include only the Moova confirm/change endpoints, `MoovaLocalIngestService` if needed, `PosOrderMutationService` if needed, stale tests that assert old routing, and focused convergence tests.

T007 third: P5-003 token protection. Allowed files should be the Moova admin page/endpoints, `MoovaPosIntegration`, security audit tests, and a token rotation doc.

T008 fourth: P5-004 delivery foundations. Allowed files should be `classes/Sync/SchemaManager.php`, a new fulfillment service, Moova apply service metadata wiring, and schema/service tests.

T009/T010 last: cashier UX evidence and full scenario matrix, including local mock acceptance output where live real-shop proof is not available.
