<?php

$expectations = [
    'sales.php' => [
        "require_once __DIR__ . '/includes/csrf.php'",
        "csrf_input('pos_browser')",
    ],
    'includes/pos_content.php' => [
        "csrf_input('pos_browser')",
        "csrf_token('shift_close')",
        'window.POSMAIN_SHIFT_CSRF_TOKEN',
        "csrf_input('shift_close')",
    ],
    'includes/pos_supermarket_content.php' => [
        "csrf_input('pos_browser')",
        'window.POSMAIN_SHIFT_CSRF_TOKEN',
        'closeSupermarketShift',
        'close_shift.php',
    ],
    'pos_supermarket.php' => [
        "require_once __DIR__ . '/includes/csrf.php'",
        "csrf_meta_tag('pos_browser', 'posmain-csrf-token')",
        'PasswordService::verifyPassword',
    ],
    'ajax/check_exact_barcode.php' => [
        "require_once __DIR__ . '/../includes/session_bootstrap.php'",
        'posmain_supermarket_require_pos_session',
        'posmain_supermarket_require_ajax_csrf',
        'posmain_supermarket_lookup_item',
    ],
    'ajax/search_item_supermarket.php' => [
        "require_once __DIR__ . '/../includes/session_bootstrap.php'",
        'posmain_supermarket_require_pos_session',
        'posmain_supermarket_require_ajax_csrf',
        'posmain_supermarket_lookup_item',
    ],
    'ajax/search_items_autocomplete.php' => [
        "require_once __DIR__ . '/../includes/session_bootstrap.php'",
        'posmain_supermarket_require_pos_session',
        'posmain_supermarket_autocomplete_items',
    ],
    'includes/supermarket_item_lookup.php' => [
        'ItemCatalogStatus::activeOnlySql',
        'ItemCatalogStatus::posSellableOnlySql',
        'ItemAvailabilityService',
        'posmain_supermarket_require_pos_session',
        'pos_authenticated',
    ],
    'do/doadd_customer_visit.php' => [
        "rbac_guard_route('do/doadd_customer_visit.php')",
        "require_csrf('customer_visits')",
        'require_login()',
    ],
    'do/dodel_customer_visit.php' => [
        "rbac_guard_route('do/dodel_customer_visit.php')",
        "require_csrf('customer_visits')",
        'require_login()',
        '$_POST[\'id\']',
    ],
    'ajax/update_customer_visit_end_time.php' => [
        "rbac_guard_route('ajax/update_customer_visit_end_time.php')",
        "require_csrf('customer_visits')",
        'require_login()',
    ],
    'ajax/pulse_ajax.php' => [
        "rbac_guard_route('ajax/pulse_ajax.php')",
        'http_response_code(401)',
        "require_csrf('pulse')",
    ],
    'do/doadd_invoice.php' => [
        "require_once('../includes/auth_guard.php')",
        "require_once('../includes/csrf.php')",
        'require_pos_authenticated();',
        "require_csrf('pos_browser');",
    ],
    'close_shift.php' => [
        "require_once __DIR__ . '/includes/auth_guard.php'",
        "require_once __DIR__ . '/includes/csrf.php'",
        'require_pos_authenticated();',
        "require_permission('pos.shift.close'",
        "require_csrf('shift_close');",
        'ShiftSessionService',
    ],
    'z_report.php' => [
        "require_once __DIR__ . '/includes/csrf.php'",
        "csrf_input('shift_close_z')",
    ],
    'do_close_shift_z.php' => [
        "require_once __DIR__ . '/includes/auth_guard.php'",
        "require_once __DIR__ . '/includes/csrf.php'",
        'require_pos_authenticated();',
        "require_permission('pos.shift.close'",
        "require_csrf('shift_close_z');",
    ],
];

foreach ($expectations as $path => $snippets) {
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    posFormWriteSecurityAssert(is_string($source), 'unable to read ' . $path);

    foreach ($snippets as $snippet) {
        posFormWriteSecurityAssert(
            strpos($source, $snippet) !== false,
            $path . ' missing form-write security snippet: ' . $snippet
        );
    }
}

$forbiddenSnippets = [
    'do/doadd_invoice.php' => [
        "if (isset(\$_GET['debug']))",
        'print_r($_POST);',
        'print_r($_POST, true)',
    ],
    'close_shift.php' => [
        'print_r($_POST',
    ],
    'do_close_shift_z.php' => [
        'print_r($_POST',
        'echo "خطأ في الإغلاق: " . $conn->error',
    ],
    'do/dodel_customer_visit.php' => [
        "\$_GET['id']",
    ],
    'customer_visits.php' => [
        "do/dodel_customer_visit.php?id=",
    ],
    'pos_supermarket.php' => [
        'INSERT INTO tables',
    ],
    'includes/pos_supermarket_content.php' => [
        'WHERE fd.pro_id = $id',
        'SELECT * FROM ot_head where id = $id',
    ],
];

foreach ($forbiddenSnippets as $path => $snippets) {
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    posFormWriteSecurityAssert(is_string($source), 'unable to read ' . $path);

    foreach ($snippets as $snippet) {
        posFormWriteSecurityAssert(
            strpos($source, $snippet) === false,
            $path . ' should not contain unsafe form-write snippet: ' . $snippet
        );
    }
}

echo "pos-form-write-security-ok\n";

function posFormWriteSecurityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
