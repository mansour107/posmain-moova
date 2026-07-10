<?php

$root = realpath(__DIR__ . '/../..');
$command = 'php ' . escapeshellarg($root . '/tools/audit_write_paths.php') . ' --json';
exec($command, $lines, $code);

writeSurfaceAuditAssert($code === 0, 'audit_write_paths should exit 0: ' . implode("\n", $lines));
$payload = json_decode(implode("\n", $lines), true);
writeSurfaceAuditAssert(is_array($payload), 'audit payload should be json');
writeSurfaceAuditAssert(isset($payload['surfaces']), 'audit payload should include surfaces');

$byPath = [];
foreach ($payload['surfaces'] as $surface) {
    $byPath[$surface['path']] = $surface;
}

foreach ([
    'do/doadd_invoice.php',
    'classes/PosOrderService.php',
    'ajax/moova_confirm_order.php',
    'ajax/moova_change_order.php',
] as $path) {
    writeSurfaceAuditAssert(isset($byPath[$path]), 'audit should include ' . $path);
}

$saveOrder = file_get_contents($root . '/ajax/save_order.php');
$cofe = file_get_contents($root . '/ajax/cofe_create_order.php');
$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');
$waiter = file_get_contents($root . '/do/doadd_invoice_waiter.php');
$canonicalInvoice = file_get_contents($root . '/do/doadd_invoice.php');

writeSurfaceAuditAssert(strpos($saveOrder, 'pos_api_dispatch') !== false, 'save_order should delegate without direct SQL writes');
writeSurfaceAuditAssert(strpos($cofe, 'pos_api_dispatch') !== false, 'cofe_create_order should delegate without direct SQL writes');
writeSurfaceAuditAssert(strpos($controller, 'INSERT INTO ot_head') === false, 'controller should not keep direct ot_head inserts');

// Canonical cashier handler remains the live write path.
writeSurfaceAuditAssert(
    strpos($canonicalInvoice, 'PosOrderMutationService') !== false
        || strpos($canonicalInvoice, 'INSERT INTO ot_head') !== false,
    'canonical doadd_invoice.php must remain an active order write surface'
);

// Legacy waiter handler is intentionally quarantined (not a live write delegate).
writeSurfaceAuditAssert(
    strpos($waiter, 'http_response_code(410)') !== false
        && strpos($waiter, 'LEGACY_WAITER_AUTH_DISABLED') !== false,
    'waiter handler should be quarantined with HTTP 410 / LEGACY_WAITER_AUTH_DISABLED'
);
writeSurfaceAuditAssert(
    strpos($waiter, 'INSERT INTO ot_head') === false
        && strpos($waiter, 'PosOrderMutationService') === false,
    'quarantined waiter handler must not perform order writes'
);

echo "write-surface-audit-contract-ok\n";

function writeSurfaceAuditAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
