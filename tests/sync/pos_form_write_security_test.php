<?php

$expectations = [
    'includes/pos_content.php' => [
        "csrf_input('pos_browser')",
        "csrf_token('shift_close')",
        'window.POSMAIN_SHIFT_CSRF_TOKEN',
        "csrf_input('shift_close')",
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
        "require_csrf('shift_close');",
        '$sales_stmt = $conn->prepare($sales_query)',
        '$user_stmt = $conn->prepare($user_query)',
        '$insert_stmt = $conn->prepare($insert_query)',
    ],
    'z_report.php' => [
        "require_once __DIR__ . '/includes/csrf.php'",
        "csrf_input('shift_close_z')",
    ],
    'do_close_shift_z.php' => [
        "require_once __DIR__ . '/includes/auth_guard.php'",
        "require_once __DIR__ . '/includes/csrf.php'",
        'require_pos_authenticated();',
        "require_csrf('shift_close_z');",
        '$user_stmt = $conn->prepare($user_query)',
        '$insert_stmt = $conn->prepare($insert_query)',
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
