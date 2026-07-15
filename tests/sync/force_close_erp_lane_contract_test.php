<?php

/**
 * ERP "إغلاق قسري" on closed_sessions.php must not require a POS unlock.
 * Classifying the route as POS lane caused require_pos_authenticated() →
 * pos_barcode.php?logout=1 → full PIN logout, and the drawer never closed.
 */

$manifest = require __DIR__ . '/../../config/rbac_route_manifest.php';
$entry = $manifest['do/do_force_close_drawer.php'] ?? null;

function forceCloseErpLaneAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

forceCloseErpLaneAssert(is_array($entry), 'force-close route must be in RBAC manifest');
forceCloseErpLaneAssert(
    ($entry['lane'] ?? '') === 'erp',
    'do_force_close_drawer.php must be ERP lane (used from closed_sessions.php without POS unlock)'
);
forceCloseErpLaneAssert(
    ($entry['permission'] ?? '') === 'pos.shift.force_close',
    'force-close must still require pos.shift.force_close'
);
forceCloseErpLaneAssert(
    ($entry['csrf'] ?? '') === 'shift_close',
    'force-close must keep shift_close CSRF'
);

echo "force-close-erp-lane-contract-ok\n";
