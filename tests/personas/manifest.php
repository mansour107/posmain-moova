<?php

declare(strict_types=1);

/**
 * Persona-based test registry for POSMAIN.
 *
 * Run:
 *   php tools/run_persona_tests.php --list
 *   php tools/run_persona_tests.php --persona=cashier --non-gui
 *   php tools/run_persona_tests.php --persona=manager --gui
 *   php tools/run_persona_tests.php --all --both
 *
 * GUI specs map to Playwright projects in playwright.config.ts (same persona id).
 */
return [
    'version' => 1,
    'personas' => [
        'shared' => [
            'label' => 'Shared / security foundation',
            'description' => 'Login, CSRF, permissions, and cross-persona guards.',
            'non_gui' => [
                ['id' => 'persona_manifest_contract', 'path' => 'tests/personas/persona_manifest_contract_test.php', 'description' => 'Persona manifest paths exist'],
                ['id' => 'login_security', 'path' => 'tests/sync/login_security_integration_test.php', 'description' => 'Login throttle and security integration', 'requires' => ['mysql']],
                ['id' => 'permission_matrix', 'path' => 'tests/sync/phase3_permission_matrix_test.php', 'description' => 'Named permission bridge contracts'],
                ['id' => 'pos_browser_csrf', 'path' => 'tests/sync/pos_browser_write_csrf_test.php', 'description' => 'POS browser CSRF namespace'],
                ['id' => 'pos_form_write_security', 'path' => 'tests/sync/pos_form_write_security_test.php', 'description' => 'POS form write hardening'],
                ['id' => 'password_change_security', 'path' => 'tests/sync/password_change_security_test.php', 'description' => 'Password change security'],
                ['id' => 'session_lifetime_contract', 'path' => 'tests/sync/session_lifetime_contract_test.php', 'description' => 'Session lifetime contracts', 'optional' => true],
            ],
            'gui' => [
                ['id' => 'login_page', 'spec' => 'tests/e2e/shared/login-page.spec.ts', 'description' => 'Login page renders'],
                ['id' => 'auth_redirects', 'spec' => 'tests/e2e/shared/auth-redirects.spec.ts', 'description' => 'Protected routes redirect to login'],
            ],
        ],

        'cashier' => [
            'label' => 'Cashier',
            'description' => 'Counter sales: takeaway, delivery, pay, print, cart, availability.',
            'non_gui' => [
                ['id' => 'takeaway_order_service', 'path' => 'tests/sync/pos_takeaway_order_service_test.php', 'description' => 'Takeaway create/pay/idempotency', 'requires' => ['mysql']],
                ['id' => 'takeaway_invoice_handler', 'path' => 'tests/sync/pos_takeaway_invoice_handler_test.php', 'description' => 'Takeaway invoice HTTP handler', 'requires' => ['mysql']],
                ['id' => 'takeaway_idempotency', 'path' => 'tests/sync/pos_takeaway_order_idempotency_test.php', 'description' => 'Takeaway idempotency keys', 'requires' => ['mysql']],
                ['id' => 'split_payment_service', 'path' => 'tests/sync/pos_split_payment_service_test.php', 'description' => 'Split payment service', 'requires' => ['mysql']],
                ['id' => 'paid_reversal_service', 'path' => 'tests/sync/pos_paid_reversal_service_test.php', 'description' => 'Refund/void service', 'requires' => ['mysql']],
                ['id' => 'cashier_discount_contract', 'path' => 'tests/sync/pos_cashier_discount_payment_contract_test.php', 'description' => 'Discount/payment UI contracts'],
                ['id' => 'cashier_split_contract', 'path' => 'tests/sync/pos_cashier_split_payment_contract_test.php', 'description' => 'Split payment UI contracts'],
                ['id' => 'cashier_action_visibility', 'path' => 'tests/sync/pos_cashier_action_visibility_contract_test.php', 'description' => 'Cashier action visibility'],
                ['id' => 'delivery_production', 'path' => 'tests/sync/delivery_production_integration_test.php', 'description' => 'Cashier delivery order service', 'requires' => ['mysql']],
                ['id' => 'delivery_http_smoke', 'path' => 'tests/sync/delivery_http_smoke_test.php', 'description' => 'Delivery customer HTTP smoke', 'requires' => ['http']],
                ['id' => 'recipe_pos_grid_contract', 'path' => 'tests/sync/recipe_pos_grid_availability_contract_test.php', 'description' => 'POS grid availability contract'],
                ['id' => 'cashier_browser_fixture', 'path' => 'tests/sync/recipe_cashier_browser_fixture_smoke_test.php', 'description' => 'Isolated cashier browser fixture smoke', 'requires' => ['mysql']],
            ],
            'tools' => [
                ['id' => 'phase6_load_concurrency', 'path' => 'tools/phase6_load_concurrency_check.php', 'description' => 'Concurrent sales/table/payment load', 'requires' => ['mysql'], 'args' => ['--json', '--allow-current-db'], 'optional' => true],
                ['id' => 'pos_grid_availability_smoke', 'path' => 'tools/recipe_pos_grid_availability_surface_smoke.php', 'description' => 'Read-only POS availability GET smoke', 'requires' => ['http'], 'args' => ['--json'], 'optional' => true],
                ['id' => 'paid_reversal_surface_smoke', 'path' => 'tools/recipe_paid_reversal_surface_smoke.php', 'description' => 'Read-only refund/void surface smoke', 'requires' => ['http'], 'args' => ['--json'], 'optional' => true],
            ],
            'gui' => [
                ['id' => 'session_login', 'spec' => 'tests/e2e/cashier/session-login.spec.ts', 'description' => 'Login as cashier'],
                ['id' => 'pos_unlock', 'spec' => 'tests/e2e/cashier/pos-unlock.spec.ts', 'description' => 'POS barcode unlock'],
                ['id' => 'takeaway_cart', 'spec' => 'tests/e2e/cashier/takeaway-cart.spec.ts', 'description' => 'Add item, qty, totals'],
                ['id' => 'takeaway_pay', 'spec' => 'tests/e2e/cashier/takeaway-pay.spec.ts', 'description' => 'Takeaway cash payment'],
                ['id' => 'delivery_mode', 'spec' => 'tests/e2e/cashier/delivery-mode.spec.ts', 'description' => 'Delivery mode UI'],
                ['id' => 'table_flow', 'spec' => 'tests/e2e/cashier/table-flow.spec.ts', 'description' => 'Table select, add item, save'],
                ['id' => 'print_receipt', 'spec' => 'tests/e2e/cashier/print-receipt.spec.ts', 'description' => 'Print receipt action'],
                ['id' => 'recent_orders_surface', 'spec' => 'tests/e2e/cashier/recent-orders.spec.ts', 'description' => 'Recent orders panel'],
            ],
        ],

        'waiter' => [
            'label' => 'Waiter',
            'description' => 'Table-only order capture without payment ownership.',
            'non_gui' => [
                ['id' => 'table_save_service', 'path' => 'tests/sync/pos_table_save_service_test.php', 'description' => 'Table save/add/update service', 'requires' => ['mysql']],
                ['id' => 'save_order_routing', 'path' => 'tests/sync/save_order_endpoint_routing_test.php', 'description' => 'save_order.php routing'],
                ['id' => 'table_order_payment_contract', 'path' => 'tests/sync/table_order_state_payment_contract_test.php', 'description' => 'Table order state contracts', 'requires' => ['mysql']],
                ['id' => 'table_merge_ui_contract', 'path' => 'tests/sync/phase4_table_merge_ui_contract_test.php', 'description' => 'Table merge UI contract'],
                ['id' => 'table_move_ui_contract', 'path' => 'tests/sync/phase4_table_move_ui_contract_test.php', 'description' => 'Table move UI contract'],
                ['id' => 'cashier_line_note_contract', 'path' => 'tests/sync/phase4_cashier_line_note_contract_test.php', 'description' => 'Line note contract'],
            ],
            'gui' => [
                ['id' => 'waiter_login', 'spec' => 'tests/e2e/waiter/waiter-login.spec.ts', 'description' => 'Waiter login'],
                ['id' => 'table_save', 'spec' => 'tests/e2e/waiter/table-save.spec.ts', 'description' => 'Table order save on POS'],
                ['id' => 'pos_tables_surface', 'spec' => 'tests/e2e/waiter/pos-tables.spec.ts', 'description' => 'pos_tables.php surface'],
            ],
        ],

        'manager' => [
            'label' => 'Manager',
            'description' => 'Approvals, paid reversals, shift/drawer, table merge/move.',
            'non_gui' => [
                ['id' => 'manager_approval_service', 'path' => 'tests/sync/phase4_manager_approval_service_test.php', 'description' => 'Manager approval service', 'requires' => ['mysql']],
                ['id' => 'manager_approval_integration', 'path' => 'tests/sync/phase4_manager_approval_integration_contract_test.php', 'description' => 'Manager approval integration'],
                ['id' => 'paid_reversal_endpoint', 'path' => 'tests/sync/recipe_paid_reversal_endpoint_runtime_test.php', 'description' => 'Refund/void endpoint runtime', 'requires' => ['mysql']],
                ['id' => 'paid_reversal_contract', 'path' => 'tests/sync/recipe_paid_reversal_endpoint_contract_test.php', 'description' => 'Refund/void endpoint contract'],
                ['id' => 'table_payment_endpoint', 'path' => 'tests/sync/process_table_payment_endpoint_routing_test.php', 'description' => 'Table payment endpoint routing'],
                ['id' => 'split_payment_endpoint', 'path' => 'tests/sync/process_split_payment_endpoint_routing_test.php', 'description' => 'Split payment endpoint routing'],
                ['id' => 'table_cancel_recipe', 'path' => 'tests/sync/pos_table_cancel_recipe_endpoint_runtime_test.php', 'description' => 'Table cancel endpoint', 'requires' => ['mysql']],
                ['id' => 'drawer_session_service', 'path' => 'tests/sync/phase4_drawer_session_service_test.php', 'description' => 'Drawer session service', 'requires' => ['mysql']],
                ['id' => 'drawer_payment_integration', 'path' => 'tests/sync/phase4_drawer_payment_integration_test.php', 'description' => 'Drawer payment integration', 'requires' => ['mysql']],
                ['id' => 'z_report_drawer_contract', 'path' => 'tests/sync/phase4_z_report_drawer_contract_test.php', 'description' => 'Z report drawer contract'],
                ['id' => 'merge_table_endpoint', 'path' => 'tests/sync/phase4_merge_table_endpoint_contract_test.php', 'description' => 'Merge table endpoint contract'],
                ['id' => 'table_merge_service', 'path' => 'tests/sync/phase4_table_merge_service_test.php', 'description' => 'Table merge service', 'requires' => ['mysql']],
            ],
            'tools' => [
                ['id' => 'manager_override_smoke', 'path' => 'tools/recipe_manager_override_surface_smoke.php', 'description' => 'Manager stock override GET smoke', 'requires' => ['http'], 'args' => ['--json'], 'optional' => true],
            ],
            'gui' => [
                ['id' => 'manager_login', 'spec' => 'tests/e2e/manager/manager-login.spec.ts', 'description' => 'Login as manager'],
                ['id' => 'shift_close_surface', 'spec' => 'tests/e2e/manager/shift-close.spec.ts', 'description' => 'Shift close modal surface'],
                ['id' => 'recent_orders_reversal', 'spec' => 'tests/e2e/manager/recent-orders-reversal.spec.ts', 'description' => 'Recent orders reversal UI'],
                ['id' => 'table_merge_surface', 'spec' => 'tests/e2e/manager/table-merge.spec.ts', 'description' => 'Table transfer/merge surface'],
            ],
        ],

        'owner' => [
            'label' => 'Owner / admin',
            'description' => 'Catalog, recipes, inventory, users, Moova/sync admin, reports.',
            'non_gui' => [
                ['id' => 'recipe_lifecycle_wiring', 'path' => 'tests/sync/recipe_lifecycle_wiring_contract_test.php', 'description' => 'Recipe lifecycle wiring contract'],
                ['id' => 'recipe_editor_readonly', 'path' => 'tests/sync/recipe_editor_readonly_contract_test.php', 'description' => 'Recipe editor read-only contract'],
                ['id' => 'recipe_availability_contract', 'path' => 'tests/sync/recipe_availability_refresh_contract_test.php', 'description' => 'Recipe availability refresh contract'],
                ['id' => 'recipe_management_ui_contract', 'path' => 'tests/sync/recipe_management_ui_contract_test.php', 'description' => 'Recipe management UI contract'],
                ['id' => 'recipe_production_runtime', 'path' => 'tests/sync/recipe_production_endpoint_runtime_test.php', 'description' => 'Production batch endpoint', 'requires' => ['mysql']],
                ['id' => 'inventory_adjustment_runtime', 'path' => 'tests/sync/inventory_adjustment_endpoint_runtime_test.php', 'description' => 'Inventory adjustment endpoint', 'requires' => ['mysql']],
                ['id' => 'inventory_quick_item', 'path' => 'tests/sync/inventory_quick_item_create_service_test.php', 'description' => 'Quick item create', 'requires' => ['mysql']],
                ['id' => 'inventory_stock_level_service', 'path' => 'tests/sync/inventory_phase18_stock_level_service_test.php', 'description' => 'Stock level service', 'requires' => ['mysql']],
                ['id' => 'item_variant_service', 'path' => 'tests/sync/item_variant_service_test.php', 'description' => 'Item variants service', 'requires' => ['mysql']],
                ['id' => 'rollout_readiness_contract', 'path' => 'tests/sync/recipe_rollout_readiness_contract_test.php', 'description' => 'Rollout readiness contract'],
                ['id' => 'runtime_preflight_contract', 'path' => 'tests/sync/recipe_runtime_preflight_contract_test.php', 'description' => 'Runtime preflight contract'],
            ],
            'tools' => [
                ['id' => 'recipe_management_smoke', 'path' => 'tools/recipe_management_surface_smoke.php', 'description' => 'Recipe management GET smoke', 'requires' => ['http'], 'args' => ['--json'], 'optional' => true],
                ['id' => 'recipe_operator_smoke', 'path' => 'tools/recipe_operator_surface_smoke.php', 'description' => 'Recipe operator reports GET smoke', 'requires' => ['http'], 'args' => ['--json'], 'optional' => true],
                ['id' => 'recipe_stock_ops_smoke', 'path' => 'tools/recipe_stock_operations_surface_smoke.php', 'description' => 'Production/waste surface smoke', 'requires' => ['http'], 'args' => ['--json'], 'optional' => true],
                ['id' => 'recipe_report_export_smoke', 'path' => 'tools/recipe_report_export_smoke.php', 'description' => 'Recipe CSV export smoke', 'requires' => ['http'], 'args' => ['--json'], 'optional' => true],
            ],
            'gui' => [
                ['id' => 'admin_login', 'spec' => 'tests/e2e/owner/admin-login.spec.ts', 'description' => 'Login as admin'],
                ['id' => 'users_page', 'spec' => 'tests/e2e/owner/users-page.spec.ts', 'description' => 'Users management page'],
                ['id' => 'recipe_manage', 'spec' => 'tests/e2e/owner/recipe-manage.spec.ts', 'description' => 'Recipe management page'],
                ['id' => 'inventory_stock_levels', 'spec' => 'tests/e2e/owner/inventory-stock-levels.spec.ts', 'description' => 'Inventory stock levels page'],
                ['id' => 'moova_integration', 'spec' => 'tests/e2e/owner/moova-integration.spec.ts', 'description' => 'Moova integration admin page'],
                ['id' => 'add_item_surface', 'spec' => 'tests/e2e/owner/add-item.spec.ts', 'description' => 'Add item page surface'],
            ],
        ],

        'sync_ops' => [
            'label' => 'Branch / sync operator',
            'description' => 'Hosted ↔ branch sync, Moova reliability, worker health (non-GUI heavy).',
            'non_gui' => [
                ['id' => 'e2e_mock_sync_contract', 'path' => 'tests/sync/e2e_mock_online_offline_sync_contract_test.php', 'description' => 'Mock online/offline E2E contract'],
                ['id' => 'branch_cloud_runtime', 'path' => 'tests/sync/branch_cloud_runtime_test.php', 'description' => 'Branch cloud runtime', 'requires' => ['mysql']],
                ['id' => 'outbox_worker_reclaim', 'path' => 'tests/sync/outbox_worker_reclaim_test.php', 'description' => 'Outbox worker reclaim', 'requires' => ['mysql']],
                ['id' => 'cloud_branch_sync_publisher', 'path' => 'tests/sync/cloud_branch_sync_publisher_test.php', 'description' => 'Cloud branch sync publisher', 'requires' => ['mysql']],
                ['id' => 'moova_widget_bridge', 'path' => 'tests/sync/moova_widget_bridge_contract_test.php', 'description' => 'Moova widget bridge contract'],
                ['id' => 'moova_direct_queued', 'path' => 'tests/sync/moova_direct_queued_convergence_test.php', 'description' => 'Moova direct/queued convergence', 'requires' => ['mysql']],
                ['id' => 'moova_reliability', 'path' => 'tests/sync/moova_reliability_scenarios_test.php', 'description' => 'Moova reliability scenarios'],
                ['id' => 'branch_worker_daemon', 'path' => 'tests/sync/branch_worker_daemon_contract_test.php', 'description' => 'Branch worker daemon contract'],
                ['id' => 'sync_recovery_tool', 'path' => 'tests/sync/sync_recovery_tool_test.php', 'description' => 'Sync recovery tool', 'requires' => ['mysql']],
                ['id' => 'sync_conflict_tool', 'path' => 'tests/sync/sync_conflict_tool_test.php', 'description' => 'Sync conflict tool', 'requires' => ['mysql']],
                ['id' => 'moova_cashier_acceptance', 'path' => 'tests/sync/moova_cashier_acceptance_runner_test.php', 'description' => 'Moova cashier acceptance runner'],
            ],
            'tools' => [
                ['id' => 'mock_online_offline_e2e', 'path' => 'tools/e2e_mock_online_offline_sync.php', 'description' => 'Mock hosted/branch sync E2E', 'requires' => ['mysql'], 'optional' => true],
                ['id' => 'moova_reachability', 'path' => 'tools/moova_reachability_smoke.php', 'description' => 'Moova reachability smoke', 'args' => ['--self-test']],
                ['id' => 'moova_topology', 'path' => 'tools/moova_local_topology_check.php', 'description' => 'Local Moova topology check'],
                ['id' => 'branch_worker_status', 'path' => 'tools/branch_worker_status.php', 'description' => 'Branch worker status report', 'requires' => ['mysql'], 'args' => ['--json']],
            ],
            'gui' => [
                ['id' => 'moova_widget_unreachable', 'spec' => 'tests/e2e/sync_ops/moova-widget.spec.ts', 'description' => 'Moova widget surface on POS', 'requires' => ['http']],
                ['id' => 'sync_credentials_admin', 'spec' => 'tests/e2e/sync_ops/sync-credentials.spec.ts', 'description' => 'Sync credentials admin form'],
            ],
        ],
    ],
];
