<?php

declare(strict_types=1);

/**
 * PHPStan symbol discovery for local PIN / security / shift surfaces.
 * Loads shared helpers and class files that are normally required at runtime.
 */

$root = __DIR__;

$files = [
    $root . '/config/app_config.php',
    $root . '/includes/db_bootstrap.php',
    $root . '/includes/db_transaction.php',
    $root . '/includes/csrf.php',
    $root . '/includes/auth_guard.php',
    $root . '/includes/auth_session_guards.php',
    $root . '/includes/page_guard.php',
    $root . '/includes/rbac_route_guard.php',
    $root . '/includes/pos_shift_guard.php',
    $root . '/includes/business_day.php',
    $root . '/includes/drawer_movement_signs.php',
    $root . '/includes/shift_handover_idempotency.php',
    $root . '/includes/pos_main_pin_entry.php',
    $root . '/classes/PasswordService.php',
    $root . '/classes/PaymentMethodService.php',
    $root . '/classes/ShiftReport.php',
    $root . '/classes/Security/PinService.php',
    $root . '/classes/Security/PermissionService.php',
    $root . '/classes/Security/LoginThrottleService.php',
    $root . '/classes/Security/MainAuthenticationService.php',
    $root . '/classes/Security/LocalSecurityBootstrapService.php',
    $root . '/classes/Security/PostLoginRouteService.php',
    $root . '/classes/Security/SecurityAuditLogger.php',
    $root . '/classes/Security/TeamHubService.php',
    $root . '/classes/Security/TeamHubMutationService.php',
    $root . '/classes/Security/RolePermissionSyncService.php',
    $root . '/classes/Pos/Service/BusinessDayService.php',
    $root . '/classes/Pos/Service/PosRegisterService.php',
    $root . '/classes/Pos/Service/DrawerSessionService.php',
    $root . '/classes/Pos/Service/DrawerFloatExpectationService.php',
    $root . '/classes/Pos/Service/DrawerBranchBlockedException.php',
    $root . '/classes/Pos/Service/ShiftDrawerReconciliationService.php',
    $root . '/classes/Pos/Service/ShiftSessionService.php',
    $root . '/classes/Pos/Service/ShiftCloseService.php',
    $root . '/classes/Pos/Service/ShiftCountService.php',
    $root . '/classes/Pos/Service/ShiftEntryService.php',
    $root . '/classes/Pos/Service/CashFlowPeriodService.php',
    $root . '/classes/Pos/Service/PaymentService.php',
    $root . '/classes/Pos/Service/ManagerApprovalService.php',
];

if (!isset($conn) || !($conn instanceof mysqli)) {
    /** @var mysqli $conn */
    $conn = new mysqli();
}

foreach ($files as $file) {
    if (is_file($file)) {
        require_once $file;
    }
}
