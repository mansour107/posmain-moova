<?php

$root = dirname(__DIR__, 2);
$form = file_get_contents($root . '/change_password.php');
$handler = file_get_contents($root . '/do/dochange_password.php');

passwordSecurityAssertContains("require_once __DIR__ . '/includes/csrf.php'", $form, 'password form should load central CSRF helper');
passwordSecurityAssertContains("csrf_input('password_change')", $form, 'password form should include password_change CSRF token');

foreach ([
    "../includes/auth_guard.php",
    "../includes/csrf.php",
    "SecurityAuditLogger",
    "require_login()",
    "verify_csrf_from_post_or_header('password_change')",
    "password_change_failed",
    "password_changed",
    "current_user_id()",
] as $snippet) {
    passwordSecurityAssertContains($snippet, $handler, 'password handler missing ' . $snippet);
}

echo "password-change-security-ok\n";

function passwordSecurityAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}
