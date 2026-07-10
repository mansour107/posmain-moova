<?php

/**
 * Static release checks for the exact-money financial core.
 * Fails closed: any violation exits non-zero.
 */
$root = dirname(__DIR__, 2);
$failures = [];

$scan = [
    $root . '/classes/Financial',
    $root . '/classes/Accounting/JournalPostingService.php',
    $root . '/classes/Accounting/JournalPostingGuard.php',
    $root . '/classes/Pos/Service/AccountingPostingService.php',
    $root . '/classes/Pos/Service/PaymentService.php',
    $root . '/classes/Pos/Service/PaymentMethodService.php',
    $root . '/classes/Pos/Service/DrawerLedgerPostingService.php',
    $root . '/classes/Pos/Service/OrderPricingService.php',
    $root . '/classes/Inventory/InventoryAccountingService.php',
    $root . '/classes/Recipe/RecipeAccountingService.php',
];
// Money-compare tolerances are banned on the cashier mutation boundary too.
$toleranceScan = array_merge($scan, [
    $root . '/classes/Pos/Service/PosOrderMutationService.php',
    $root . '/classes/Pos/Service/CashFlowPeriodService.php',
]);
$certifiedFiles = [];
foreach ($scan as $path) {
    if (is_dir($path)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($it as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $certifiedFiles[] = $file->getPathname();
            }
        }
    } elseif (is_file($path)) {
        $certifiedFiles[] = $path;
    }
}

foreach ($certifiedFiles as $pathname) {
    $contents = file_get_contents($pathname);
    if ($contents === false) {
        continue;
    }
    $relative = substr($pathname, strlen($root) + 1);
    // Money::fromLegacy may use is_float type checks; ban float casts and floatval.
    if (preg_match('/\(float\)|floatval\s*\(/', $contents)) {
        // Allow Money::fromLegacy boundary adapter only.
        if (strpos($relative, 'classes/Financial/Money.php') !== false
            || strpos($relative, 'classes/Financial/UnitPrice.php') !== false
            || strpos($relative, 'classes/Financial/DecimalQuantity.php') !== false
        ) {
            continue;
        }
        $failures[] = 'float_cast:' . $relative;
    }
    if (preg_match('/0\.0001/', $contents)) {
        $failures[] = 'tolerance:' . $relative;
    }
}

foreach ($toleranceScan as $path) {
    if (!is_file($path)) {
        continue;
    }
    $contents = file_get_contents($path);
    $relative = substr($path, strlen($root) + 1);
    if ($contents !== false && preg_match('/0\.0001/', $contents)) {
        $failures[] = 'tolerance:' . $relative;
    }
}

$forbiddenWriters = [
    'classes/Pos/Service/AccountingPostingService.php',
    'classes/Pos/Service/DrawerLedgerPostingService.php',
    'classes/Financial/FinancialRefundService.php',
    'classes/Financial/FinancialInvoicePostingService.php',
    'classes/Inventory/InventoryAccountingService.php',
    'classes/Recipe/RecipeAccountingService.php',
];
foreach ($forbiddenWriters as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        continue;
    }
    $contents = file_get_contents($path);
    if (preg_match('/INSERT\s+INTO\s+journal_(heads|entries)/i', $contents)) {
        $failures[] = 'direct_journal_insert:' . $relative;
    }
}

$posOrder = $root . '/classes/PosOrderService.php';
if (is_file($posOrder)) {
    $contents = file_get_contents($posOrder);
    if (preg_match('/function insertMainJournal[\s\S]*?INSERT\s+INTO\s+journal_heads/i', $contents)) {
        $failures[] = 'direct_journal_insert:classes/PosOrderService.php:insertMainJournal';
    }
}

$mode = $root . '/classes/Financial/FinancialCertifiedMode.php';
if (is_file($mode)) {
    $contents = file_get_contents($mode);
    if (strpos($contents, 'POSMAIN_FINANCIAL_CERTIFIED_MODE') !== false) {
        $failures[] = 'certified_mode_env_toggle_must_be_removed';
    }
    if (!preg_match('/function isEnabled\(\):\s*bool\s*\{\s*return true;/', $contents)) {
        $failures[] = 'certified_mode_must_always_be_enabled';
    }
}

$guard = $root . '/includes/financial_certified_guard.php';
if (is_file($guard)) {
    $contents = file_get_contents($guard);
    if (strpos($contents, 'FinancialCertifiedMode::isEnabled()') !== false) {
        $failures[] = 'legacy_guard_must_not_be_conditional';
    }
}

if ($failures) {
    fwrite(STDERR, "financial-float-journal-contract-FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "financial-float-journal-contract-ok\n";
