<?php

$pageExpectations = [
    'pos_barcode.php' => [
        "require_once __DIR__ . '/includes/csrf.php'",
        "csrf_meta_tag('pos_browser', 'posmain-csrf-token')",
        'window.POSMAIN_CSRF_TOKEN',
        'window.POSMAIN_ATTACH_CSRF_HEADER',
        "ajaxSetup({ beforeSend: window.POSMAIN_ATTACH_CSRF_HEADER })",
    ],
    'tables.php' => [
        "require_once __DIR__ . '/includes/csrf.php'",
        "csrf_meta_tag('pos_browser', 'posmain-csrf-token')",
        'window.POSMAIN_CSRF_TOKEN',
        'window.POSMAIN_ATTACH_CSRF_HEADER',
        "ajaxSetup({ beforeSend: window.POSMAIN_ATTACH_CSRF_HEADER })",
    ],
    'includes/pos_content.php' => [
        "csrf_input('pos_browser')",
    ],
    'pos_tables.php' => [
        "require_once __DIR__ . '/includes/csrf.php'",
        "csrf_meta_tag('pos_browser', 'posmain-csrf-token')",
        'window.POSMAIN_CSRF_TOKEN',
    ],
    'js/pos_tables.js' => [
        'getPOSTableCsrfToken',
        'attachPOSTableCsrfHeader',
        'beforeSend: attachPOSTableCsrfHeader',
    ],
];

foreach ($pageExpectations as $path => $snippets) {
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    posBrowserWriteCsrfAssert(is_string($source), 'unable to read ' . $path);

    foreach ($snippets as $snippet) {
        posBrowserWriteCsrfAssert(strpos($source, $snippet) !== false, $path . ' missing CSRF propagation snippet: ' . $snippet);
    }
}

$guardedEndpoints = [
    'ajax/save_order.php',
    'ajax/process_table_payment.php',
    'ajax/process_split_payment.php',
    'ajax/delete_order.php',
    'ajax/clear_table.php',
    'ajax/clear_table_normal.php',
    'ajax/update_table_status.php',
];

foreach ($guardedEndpoints as $path) {
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    posBrowserWriteCsrfAssert(is_string($source), 'unable to read ' . $path);
    posBrowserWriteCsrfAssert(strpos($source, "require_once('../includes/auth_guard.php')") !== false, $path . ' should require auth_guard.php');
    posBrowserWriteCsrfAssert(strpos($source, "require_once('../includes/csrf.php')") !== false, $path . ' should require csrf.php');
    posBrowserWriteCsrfAssert(strpos($source, 'require_pos_authenticated();') !== false, $path . ' should require POS/browser authentication');
    posBrowserWriteCsrfAssert(strpos($source, "require_csrf('pos_browser');") !== false, $path . ' should require the POS browser CSRF token');
}

echo "pos-browser-write-csrf-ok\n";

function posBrowserWriteCsrfAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
