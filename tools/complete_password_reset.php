<?php

/**
 * Consume a single-use password reset token and set a new password (CLI only).
 *
 * Usage:
 *   php tools/complete_password_reset.php
 * The token and password are read from standard input so neither secret appears
 * in the process list or shell history.
 */

if (PHP_SAPI !== 'cli') {
    require __DIR__ . '/../includes/http_gone.php';
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Security/PasswordResetService.php';

$options = getopt('', ['help']);
if (isset($options['help'])) {
    echo "Usage: php tools/complete_password_reset.php\n";
    echo "Reads the reset token and new password securely from standard input.\n";
    exit(0);
}

function posmainReadResetSecret(string $prompt): string
{
    $interactive = function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($interactive) {
        fwrite(STDERR, $prompt);
        if (DIRECTORY_SEPARATOR === '/') {
            @shell_exec('stty -echo');
        }
    }

    try {
        $value = fgets(STDIN);
    } finally {
        if ($interactive && DIRECTORY_SEPARATOR === '/') {
            @shell_exec('stty echo');
            fwrite(STDERR, PHP_EOL);
        }
    }

    return rtrim((string) $value, "\r\n");
}

try {
    $token = posmainReadResetSecret('Reset token: ');
    $newPassword = posmainReadResetSecret('New password: ');
    if ($token === '' || $newPassword === '') {
        throw new InvalidArgumentException('RESET_TOKEN_AND_PASSWORD_REQUIRED');
    }

    $conn = posmain_db_connect();
    $service = new PasswordResetService();
    $result = $service->completeReset(
        $conn,
        $token,
        $newPassword,
        ['source' => 'cli']
    );
    echo "OK user_id={$result['user_id']} token_id={$result['token_id']}\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}
