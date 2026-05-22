<?php

$pageExpectations = [
    'add_user.php' => [
        "require_admin_or_permission('users.manage', \$conn)",
        "csrf_input('users_write')",
    ],
    'edit_user.php' => [
        "require_admin_or_permission('users.manage', \$conn)",
        "csrf_input('users_write')",
        '$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1")',
    ],
    'users.php' => [
        "require_admin_or_permission('users.manage', \$conn)",
    ],
    'setting.php' => [
        "require_admin_or_permission('system.tools.run', \$conn)",
        "csrf_input('settings_gate')",
        "csrf_input('settings_write')",
        "verify_csrf_from_post_or_header('settings_gate')",
    ],
];

foreach ($pageExpectations as $path => $snippets) {
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    adminWriteAssert(is_string($source), 'unable to read ' . $path);
    foreach ($snippets as $snippet) {
        adminWriteAssert(strpos($source, $snippet) !== false, $path . ' missing security snippet: ' . $snippet);
    }
}

$handlerExpectations = [
    'do/doadd_user.php' => [
        "require_admin_or_permission('users.manage', \$conn)",
        "require_csrf('users_write')",
        "'user_created'",
        'SecurityAuditLogger',
    ],
    'do/doedit_user.php' => [
        "require_admin_or_permission('users.manage', \$conn)",
        "require_csrf('users_write')",
        "'user_updated'",
        'SecurityAuditLogger',
    ],
    'do/doedit_settings.php' => [
        "require_admin_or_permission('system.tools.run', \$conn)",
        "require_csrf('settings_write')",
        "'settings_updated'",
        'SecurityAuditLogger',
        'print_r($_POST, true)',
    ],
    'ajax/sync_credentials.php' => [
        "require_admin_or_permission('system.tools.run', \$conn)",
        "require_csrf('sync_credentials')",
        'SecurityAuditLogger',
        'syncCredentialsRequireEncryption',
        'print_r($_POST, true)',
    ],
];

foreach ($handlerExpectations as $path => $snippets) {
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    adminWriteAssert(is_string($source), 'unable to read ' . $path);
    foreach ($snippets as $snippet) {
        if ($snippet === 'print_r($_POST, true)') {
            adminWriteAssert(strpos($source, $snippet) === false, $path . ' should not log raw POST data');
            continue;
        }
        adminWriteAssert(strpos($source, $snippet) !== false, $path . ' missing handler security snippet: ' . $snippet);
    }
}

echo "admin-write-security-ok\n";

function adminWriteAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
