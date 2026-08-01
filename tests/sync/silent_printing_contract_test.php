<?php

$root = dirname(__DIR__, 2);

silentPrintingContractAssert(
    strpos(file_get_contents($root . '/config/app_config.php'), "POSMAIN_PRINT_MODE") !== false
    && strpos(file_get_contents($root . '/config/app_config.php'), "'legacy', 'silent'") !== false,
    'printing must retain a deployment-only legacy/silent switch'
);
silentPrintingContractAssert(
    strpos(file_get_contents($root . '/includes/header.php'), 'posmain_render_print_client_bootstrap') !== false
    && strpos(file_get_contents($root . '/print/includes/header.php'), 'posmain_render_print_client_bootstrap') !== false,
    'main and print layouts must load the shared print client'
);

$client = file_get_contents($root . '/js/posmain_print.js');
silentPrintingContractAssert(
    strpos($client, "config.mode !== 'silent'") !== false
    && strpos($client, 'nativePrint()') !== false,
    'legacy mode must preserve native browser printing'
);
silentPrintingContractAssert(
    strpos($client, 'if (activePromise) return activePromise') !== false
    && strpos($client, 'pendingRequestKey') !== false
    && strpos($client, 'Keep the same request key') !== false,
    'client must collapse double-clicks and retain idempotency after response loss'
);

$routing = file_get_contents($root . '/classes/Pos/Service/PrinterRoutingService.php');
silentPrintingContractAssert(
    strpos($routing, 'PRINT_KOT_LINE_UNROUTED') !== false
    && strpos($routing, "'all_categories'") !== false
    && strpos($routing, "'category_ids'") !== false
    && strpos($routing, "['network', 'usb']") !== false,
    'kitchen routing must fail closed and silent routing must exclude legacy browser printers'
);

$transport = file_get_contents($root . '/classes/Pos/Service/PrinterTransportService.php');
$bridgeClient = file_get_contents($root . '/classes/Pos/Service/PrintBridgeClient.php');
$networkTransport = file_get_contents($root . '/classes/Pos/Service/LocalNetworkPrinterService.php');
silentPrintingContractAssert(
    strpos($networkTransport, 'PRINT_NETWORK_DELIVERY_UNCERTAIN') !== false
    && strpos($transport, 'false);') !== false
    && strpos($bridgeClient, 'PRINT_BRIDGE_DELIVERY_UNCERTAIN') !== false,
    'transport must distinguish uncertain network and cable delivery'
);
silentPrintingContractAssert(
    strpos($transport, "['network', 'usb']") !== false
    && strpos(file_get_contents($root . '/printer_management.php'), 'simulator_key') === false,
    'cable printing must use the host bridge and production settings must not expose a simulator transport'
);

$worker = file_get_contents($root . '/classes/Pos/Service/PrintWorkerService.php');
silentPrintingContractAssert(
    strpos($worker, 'failClaimWithoutRetry') !== false
    && strpos($worker, '$exception->isRetrySafe()') !== false,
    'uncertain or permanent delivery failures must not auto-reprint'
);
$jobService = file_get_contents($root . '/classes/Pos/Service/PrintJobService.php');
silentPrintingContractAssert(
    strpos($jobService, "'PRINT_JOB_NOT_QUEUED'") !== false
    && strpos($jobService, 'normal contention') !== false
    && strpos($jobService, "printer.connection_type IN ('network', 'usb')") !== false,
    'worker claims must handle contention and exclude legacy browser-only jobs'
);

$permissionMap = file_get_contents($root . '/includes/auth_guard.php');
$roleSync = file_get_contents($root . '/classes/Security/RolePermissionSyncService.php');
$sidebar = file_get_contents($root . '/includes/sidebar.php');
require_once $root . '/classes/Security/RolePermissionSyncService.php';
$rolePresets = RolePermissionSyncService::presetRoleDefinitions();
silentPrintingContractAssert(
    strpos($permissionMap, "'printers.manage'") !== false
    && strpos($roleSync, "'printers.manage'") !== false
    && strpos($sidebar, 'printer_management.php') !== false,
    'printer management must be permissioned and present in the sidebar'
);
silentPrintingContractAssert(
    in_array('printers.manage', $rolePresets['manager']['permissions'] ?? [], true)
    && !in_array('printers.manage', $rolePresets['cashier']['permissions'] ?? [], true),
    'manager must receive printer management by default while cashier remains denied'
);

$page = file_get_contents($root . '/printer_management.php');
silentPrintingContractAssert(
    strpos($page, "require_permission('printers.manage'") !== false
    && strpos($page, "require_csrf('printer_manage')") !== false
    && strpos($page, 'وضع التشغيل الحالي') !== false
    && strpos($page, 'physical_output_checked') !== false,
    'printer page must enforce permission/CSRF, expose the mode read-only, and guard uncertain retries'
);

$preparation = file_get_contents($root . '/print/preparation.php');
silentPrintingContractAssert(
    strpos($preparation, 'Promise.resolve(window.print())') !== false
    && strpos($preparation, 'window.close();') !== false,
    'auto-KOT page must wait for dispatch before closing'
);
silentPrintingContractAssert(
    strpos($preparation, '$stmt = null;') !== false,
    'auto-KOT page must not reuse a closed statement when the authoritative payload succeeds'
);

foreach ([
    'drawer_session.php',
    'shift_sales_report.php',
    'summary.php',
    'waiter_barcode.php',
] as $mainLayoutPage) {
    silentPrintingContractAssert(
        strpos(file_get_contents($root . '/' . $mainLayoutPage), "include('includes/header.php')") !== false,
        $mainLayoutPage . ' must inherit the shared print client'
    );
}

foreach ([
    'print/presc_print.php',
    'print/preparation.php',
    'print/daily_sales_receipt.php',
    'print/br2538.php',
    'print/closed_session_receipt.php',
    'print/receipt_waiter.php',
    'print/shift_sales_receipt.php',
    'print/freebarcode.php',
    'print/closed_session_items.php',
] as $standalonePrintPage) {
    silentPrintingContractAssert(
        strpos(file_get_contents($root . '/' . $standalonePrintPage), 'posmain_render_print_client_bootstrap') !== false,
        $standalonePrintPage . ' must load the shared print client'
    );
}

foreach (['print/receipt.php', 'print/print_sales.php'] as $printLayoutPage) {
    silentPrintingContractAssert(
        strpos(file_get_contents($root . '/' . $printLayoutPage), "include('includes/header.php')") !== false,
        $printLayoutPage . ' must inherit the shared print client'
    );
}

echo "silent-printing-contract-ok\n";

function silentPrintingContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
