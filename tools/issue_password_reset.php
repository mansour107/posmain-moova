<?php

/**
 * Issue a single-use password reset credential (CLI only).
 * The plaintext token is printed once and never written to logs/audit metadata.
 *
 * Usage:
 *   php tools/issue_password_reset.php --uname=admin
 *   php tools/issue_password_reset.php --uname=admin --ttl-seconds=1800
 */

if (PHP_SAPI !== 'cli') {
    require __DIR__ . '/../includes/http_gone.php';
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Security/PasswordResetService.php';

$options = getopt('', ['uname:', 'ttl-seconds::', 'help']);
if (isset($options['help']) || empty($options['uname'])) {
    echo "Usage: php tools/issue_password_reset.php --uname=USER [--ttl-seconds=3600]\n";
    exit(isset($options['help']) ? 0 : 1);
}

try {
    $conn = posmain_db_connect();
    $service = new PasswordResetService();
    $issued = $service->issueResetToken($conn, (string) $options['uname'], [
        'ttl_seconds' => isset($options['ttl-seconds']) ? (int) $options['ttl-seconds'] : PasswordResetService::DEFAULT_TTL_SECONDS,
        'created_by' => get_current_user() ?: 'cli',
    ]);

    // Print operator-facing credential once. Do not JSON-encode into log files.
    fwrite(STDOUT, "OK user_id={$issued['user_id']} uname={$issued['uname']} expires_at_utc={$issued['expires_at']}\n");
    fwrite(STDOUT, "RESET_TOKEN={$issued['token']}\n");
    fwrite(STDOUT, "Complete with: php tools/complete_password_reset.php\n");
    fwrite(STDOUT, "Enter the token and new password only when prompted; do not pass them as command arguments.\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}
