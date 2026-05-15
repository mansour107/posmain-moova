<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
phase6E2eAssert(is_string($root), 'repo root should resolve');

$path = $root . '/docs/production/phase6_e2e_local_command.md';
phase6E2eAssert(is_file($path), 'Phase 6 E2E local command doc should exist');
$content = (string)file_get_contents($path);
phase6E2eAssert(strlen($content) > 1000, 'Phase 6 E2E local command doc should not be a placeholder');

phase6E2eAssertContains($content, [
    'docker start posmain-mysql posmain-php',
    'tools/seed_demo_restaurant.php --apply --reset-demo --with-moova-dummy',
    'tools/phase6_load_concurrency_check.php --json --allow-current-db',
    'http://127.0.0.1:8010/index.php',
    'p6_admin',
    'p6_manager',
    'p6_cashier',
    'p6_waiter',
    'Login',
    'POS lock/unlock',
    'Takeaway sale paid cash',
    'Table order save',
    'Add item to table',
    'Partial payment',
    'Split payment',
    'Cancel unpaid table',
    'Manager approval for void',
    'Open shift',
    'Close shift',
    'Print receipt view',
    'Print KOT view',
    'Moova accept if enabled',
    'Moova decline if enabled',
    'do/doadd_invoice.php',
    'ajax/save_order.php',
    'ajax/process_table_payment.php',
    'ajax/process_split_payment.php',
    'ajax/delete_order.php',
    'close_shift.php',
    'do_close_shift_z.php',
    'ajax/moova_confirm_order.php',
    'ajax/moova_change_order.php',
    'No duplicate `pro_id`, negative remaining amount, or table stuck occupied after paid/cancelled order',
], 'Phase 6 E2E local command doc');

phase6E2eAssert(!str_contains($content, 'TODO'), 'Phase 6 E2E doc should not contain TODO placeholders');

echo "phase6-e2e-docs-contract-ok\n";

/**
 * @param list<string> $needles
 */
function phase6E2eAssertContains(string $content, array $needles, string $label): void
{
    foreach ($needles as $needle) {
        phase6E2eAssert(str_contains($content, $needle), "{$label} missing required text: {$needle}");
    }
}

function phase6E2eAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
