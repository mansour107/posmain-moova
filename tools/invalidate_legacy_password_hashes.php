<?php

/**
 * Invalidate legacy MD5 password hashes without assigning predictable passwords.
 *
 * Usage:
 *   php tools/invalidate_legacy_password_hashes.php --dry-run
 *   php tools/invalidate_legacy_password_hashes.php --apply
 */

if (PHP_SAPI !== 'cli') {
    require __DIR__ . '/../includes/http_gone.php';
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Security/PasswordResetService.php';

$options = getopt('', ['dry-run', 'apply', 'help']);
if (isset($options['help']) || (!isset($options['dry-run']) && !isset($options['apply']))) {
    echo "Usage: php tools/invalidate_legacy_password_hashes.php (--dry-run|--apply)\n";
    exit(isset($options['help']) ? 0 : 1);
}

try {
    $conn = posmain_db_connect();
    $service = new PasswordResetService();
    $result = $service->invalidateLegacyPasswordHashes($conn, [
        'dry_run' => isset($options['dry-run']),
    ]);
    echo json_encode([
        'ok' => true,
        'dry_run' => isset($options['dry-run']),
        'invalidated' => $result['invalidated'],
        'skipped' => $result['skipped'],
        'user_ids' => $result['user_ids'],
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}
