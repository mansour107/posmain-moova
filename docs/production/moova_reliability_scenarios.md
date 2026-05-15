# Moova Reliability Scenarios

This Phase 5 matrix is local and simulated. No live Moova credentials, real customer orders, or production branch data are required for these checks.

## Required Matrix

| Scenario | Evidence | Local check |
| --- | --- | --- |
| Pilot mode is direct-widget first, with queued worker apply disabled unless explicitly enabled | `docs/production/moova_mode_decision.md`, `config/app_config.php` | `php tests/sync/moova_mode_config_test.php` |
| Same provider event delivered by direct widget and queued poller converges to the same idempotency key and payload hash | `MoovaLocalIngestService`, direct endpoints, queued worker | `php tests/sync/moova_direct_queued_convergence_test.php` |
| New/edit/cancel apply responses keep delivery path, apply path, sync event type, and sync status stable | `MoovaApplyResponse` | PHPUnit `tests/sync/moova_apply_response_contract_test.php` when PHPUnit is available |
| Stale edit/cancel is declined instead of corrupting the POS order | `MoovaChangeOrderApplyService`, mutation convergence tests | `php tests/sync/moova_pos_mutation_convergence_test.php` |
| Token viewing is permission-gated and audited, while authorized managers can still see the full token | `moova_integration.php`, `MoovaPosIntegration`, rotation doc | `php tests/sync/moova_token_visibility_security_test.php` |
| Moova QR/table and future delivery orders have structured channel, fulfillment, customer, and delivery fields | `order_fulfillment`, `OrderFulfillmentService` | `php tests/sync/moova_delivery_foundation_test.php` and `POSMAIN_TEST_MYSQL_PORT=3307 php tests/sync/phase5_order_fulfillment_service_test.php` |
| Cashier sees pending badge, mute state, customer info, required decline reason, stale conflict, invalid token/link, and unreachable-service messages | widget JS and parent bridge | `php tests/sync/moova_cashier_ux_contract_test.php` |
| Mock POS/Moova reachability drop and recovery can be exercised without default local topology ports | `tools/moova_reachability_smoke.php` | PHPUnit `tests/sync/moova_reachability_smoke_contract_test.php` when PHPUnit is available |

## Pilot Blockers Still Outside Local Simulation

- Real Moova account credentials.
- Real shop cashier acceptance with representative orders.
- Hosted production rollout of queued worker mode.
- Operational monitoring/dashboard for queued worker apply.

For the mid-scale pilot, keep `direct_widget` mode unless those blockers are cleared and a fresh cashier acceptance artifact is attached to the branch go-live checklist.
