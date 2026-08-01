<?php

$redirectPageExpectations = [
    'add_user.php' => [
        "require_admin_or_permission('users.manage', \$conn)",
        "header('Location: team.php",
    ],
    'edit_user.php' => [
        "require_admin_or_permission('users.manage', \$conn)",
        "header('Location: team.php",
    ],
    'users.php' => [
        "require_admin_or_permission('users.manage', \$conn)",
        "header('Location:",
    ],
];

foreach ($redirectPageExpectations as $path => $snippets) {
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    adminWriteAssert(is_string($source), 'unable to read ' . $path);
    foreach ($snippets as $snippet) {
        adminWriteAssert(strpos($source, $snippet) !== false, $path . ' missing security snippet: ' . $snippet);
    }
}

$pageExpectations = [
    'team.php' => [
        'page_guard_from_manifest(',
        "csrf_token('users_write')",
    ],
    'setting.php' => [
        "require_admin_or_permission('system.tools.run', \$conn)",
        "csrf_input('settings_gate')",
        "csrf_input('settings_write')",
        "verify_csrf_from_post_or_header('settings_gate')",
    ],
    'pos_customers.php' => [
        "require_admin_or_permission('customers.manage', \$conn)",
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
    'ajax/team_hub.php' => [
        "rbac_guard_route('ajax/team_hub.php'",
        'team_hub_require_csrf(',
        'TeamHubMutationService',
    ],
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

$settingsSource = file_get_contents(__DIR__ . '/../../setting.php');
adminWriteAssert(is_string($settingsSource), 'setting.php should be readable');
$headerIncludeAt = strpos($settingsSource, "include('includes/header.php')");
adminWriteAssert($headerIncludeAt !== false, 'setting.php should include the shared header');
foreach (["csrf_token('settings_gate')", "csrf_token('system_update')", "csrf_token('settings_write')", "csrf_token('sync_credentials')"] as $tokenCall) {
    $tokenCallAt = strpos($settingsSource, $tokenCall);
    adminWriteAssert($tokenCallAt !== false, 'setting.php missing ' . $tokenCall);
    adminWriteAssert($tokenCallAt < $headerIncludeAt, 'setting.php must mint ' . $tokenCall . ' before the header releases the session lock');
}

echo "admin-write-security-ok\n";

function adminWriteAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
