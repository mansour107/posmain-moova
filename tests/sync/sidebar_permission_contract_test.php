<?php

$sidebar = file_get_contents(__DIR__ . '/../../includes/sidebar.php');
$navbar = file_get_contents(__DIR__ . '/../../includes/navbar.php');

sidebarPermAssert(strpos($sidebar, 'auth_guard_has_permission') !== false, 'sidebar should use auth_guard_has_permission');
sidebarPermAssert(strpos($sidebar, 'posmainCanViewKds') !== false, 'sidebar should gate KDS via capabilities');
sidebarPermAssert(strpos($sidebar, 'posmainCanViewAccounting') !== false, 'sidebar should gate accounting via capabilities');
sidebarPermAssert(strpos($sidebar, 'posmainCanEditMenu || $posmainCanEditInventory') !== false, 'sidebar stock section should require menu or inventory permission');
sidebarPermAssert(strpos($sidebar, 'posmainCanCloseShift') !== false, 'sidebar should gate closed sessions via pos.shift.close');
sidebarPermAssert(strpos($sidebar, 'posmainCanViewCashFlow') !== false, 'sidebar should gate cash flow via reports.cash_flow');
sidebarPermAssert(strpos($navbar, 'auth_guard_has_permission') !== false, 'navbar should use auth_guard_has_permission');
sidebarPermAssert(strpos($navbar, 'app-identity-badge') !== false, 'navbar should show logged-in user identity badge');
sidebarPermAssert(strpos($navbar, 'display_name') !== false, 'navbar identity should prefer display_name');

echo "sidebar-permission-contract-ok\n";

function sidebarPermAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
