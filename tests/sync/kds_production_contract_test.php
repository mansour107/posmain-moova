<?php

/**
 * Static wiring contract for the production KDS system. Verifies that all
 * the moving parts are present and connected without requiring a database,
 * so it always runs in CI.
 */

require_once __DIR__ . '/../../includes/auth_guard.php';

function kdsContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function kdsContractRead(string $relativePath): string
{
    $path = __DIR__ . '/../../' . $relativePath;
    kdsContractAssert(is_file($path), 'missing file ' . $relativePath);
    $contents = file_get_contents($path);
    kdsContractAssert(is_string($contents) && $contents !== '', 'empty file ' . $relativePath);

    return $contents;
}

// 1. Schema tables are registered.
$schema = kdsContractRead('classes/Sync/SchemaManager.php');
foreach ([
    'kds_stations',
    'kds_station_categories',
    'kds_station_users',
    'kds_tickets',
    'kds_ticket_lines',
    'kds_changes',
    'kds_order_events',
    'order_line_kitchen_snapshots',
] as $table) {
    kdsContractAssert(strpos($schema, "'" . $table . "' =>") !== false, 'schema missing planned table ' . $table);
}
kdsContractAssert(strpos($schema, 'kitchen_status') !== false, 'schema missing ot_head.kitchen_status');
kdsContractAssert(strpos($schema, 'applyKdsSchema') !== false, 'schema missing applyKdsSchema helper');
kdsContractAssert(strpos($schema, "ADD COLUMN sid_kds") !== false, 'schema missing usr_pwrs.sid_kds');
$kdsGuard = kdsContractRead('classes/Sync/KdsSchemaReadinessGuard.php');
kdsContractAssert(strpos($kdsGuard, 'pendingKdsStatements') !== false, 'KDS guard must inspect only KDS migrations');
kdsContractAssert(strpos($kdsGuard, 'pendingStatements(') === false, 'KDS guard must not inspect global migrations');
$kdsBootstrap = kdsContractRead('includes/kds_bootstrap.php');
kdsContractAssert(strpos($kdsBootstrap, 'KdsSchemaReadinessGuard') !== false, 'KDS bootstrap must use scoped readiness');

// 2. Permissions are mapped and kitchen access is isolated to sid_kds.
$map = auth_guard_permission_map();
foreach (['kds.view', 'kds.complete', 'kds.manage'] as $permission) {
    kdsContractAssert(array_key_exists($permission, $map), 'permission map missing ' . $permission);
}
kdsContractAssert($map['kds.view'] === ['sid_kds'], 'kds.view must bridge to sid_kds only (no sales leak)');
kdsContractAssert($map['kds.complete'] === ['sid_kds'], 'kds.complete must bridge to sid_kds only');
kdsContractAssert($map['kds.manage'] === ['__admin_only'], 'kds.manage must be admin only');

// 3. Core services exist with their key methods.
$ticketService = kdsContractRead('classes/Pos/Service/KdsTicketService.php');
foreach (['syncForOrder', 'completeTicket', 'recallTicket', 'changesSince', 'recomputeOrderKitchenStatus', 'reconcile'] as $method) {
    kdsContractAssert(strpos($ticketService, 'function ' . $method) !== false, 'KdsTicketService missing ' . $method);
}
kdsContractAssert(strpos($ticketService, 'kitchen.order.completed') !== false, 'KdsTicketService must emit kitchen.order.completed');
kdsContractRead('classes/Pos/Service/KdsStationService.php');
kdsContractRead('classes/Pos/Service/KdsRoutingService.php');
$eventService = kdsContractRead('classes/Pos/Service/KdsOrderEventService.php');
kdsContractAssert(strpos($eventService, 'function acknowledge') !== false, 'KDS order event service must expose acknowledgement');
kdsContractAssert(strpos($eventService, "status = 'acknowledged'") !== false, 'KDS order event acknowledgement must be durable');

// 4. Integration: side-effects chokepoint persists KDS tickets and the gate is open.
$sideEffects = kdsContractRead('classes/Pos/Service/OrderMutationSideEffectsService.php');
kdsContractAssert(strpos($sideEffects, 'KdsTicketService') !== false, 'side effects must use KdsTicketService');
kdsContractAssert(strpos($sideEffects, 'syncKitchenDisplay') !== false, 'side effects must call syncKitchenDisplay');
$publisher = kdsContractRead('classes/Pos/Service/KitchenEventPublisher.php');
kdsContractAssert(strpos($publisher, "!empty(\$features['kds'])") === false, 'features.kds gate must be removed');
kdsContractAssert(strpos($publisher, 'payload build skipped') === false, 'KDS payload failures must not be swallowed');
$apiDispatch = kdsContractRead('includes/pos_api_dispatch.php');
kdsContractAssert(strpos($apiDispatch, 'KITCHEN_TICKET_INCOMPLETE') !== false, 'cashier API must expose an actionable incomplete-ticket error');
$cashierApi = kdsContractRead('js/pos_order_api.js');
kdsContractAssert(strpos($cashierApi, 'KITCHEN_TICKET_INCOMPLETE') !== false, 'cashier UI must explain incomplete kitchen ticket failures');
$legacyInvoice = kdsContractRead('do/doadd_invoice.php');
kdsContractAssert(strpos($legacyInvoice, 'KdsTicketService') !== false, 'legacy invoice path must sync KDS');

// 5. Endpoints and pages exist and are guarded.
$pollEndpoint = kdsContractRead('ajax/kds_tickets_list.php');
kdsContractAssert(strpos($pollEndpoint, "require_permission('kds.view'") !== false, 'poll endpoint must require kds.view');
$action = kdsContractRead('do/kds_ticket_action.php');
kdsContractAssert(strpos($action, "require_permission('kds.complete'") !== false, 'action endpoint must require kds.complete');
kdsContractAssert(strpos($action, "require_csrf('kds')") !== false, 'action endpoint must require csrf');
kdsContractAssert(strpos($action, 'SecurityAuditLogger') !== false, 'action endpoint must audit');
kdsContractAssert(strpos($action, 'acknowledge_event') !== false, 'action endpoint must acknowledge exact kitchen events');
kdsContractAssert(strpos($action, 'kds_require_station_id_access') !== false, 'event acknowledgement must enforce station access');
$snapshotBuilder = kdsContractRead('classes/Sync/PosOrderSnapshotBuilder.php');
kdsContractAssert(strpos($snapshotBuilder, "'kitchen_events' =>") !== false, 'order sync snapshot must expose durable kitchen events');
$settings = kdsContractRead('kds_settings.php');
kdsContractAssert(strpos($settings, "require_permission('kds.manage'") !== false, 'settings page must require kds.manage');
foreach ([
    'do/kds_station_save.php',
    'do/kds_station_delete.php',
    'do/kds_category_map_save.php',
    'do/kds_worker_assign.php',
] as $handler) {
    $body = kdsContractRead($handler);
    kdsContractAssert(strpos($body, "require_permission('kds.manage'") !== false, $handler . ' must require kds.manage');
    kdsContractAssert(strpos($body, "require_csrf('kds_manage')") !== false, $handler . ' must require csrf');
}
kdsContractRead('kds.php');
kdsContractRead('kds_station.php');
$kdsBoard = kdsContractRead('js/kds_board.js');
kdsContractAssert(strpos($kdsBoard, 'showActionError') !== false, 'failed acknowledgement must remain visibly actionable on KDS');
kdsContractRead('dist/css/kds.css');

echo "kds-production-contract-ok\n";
