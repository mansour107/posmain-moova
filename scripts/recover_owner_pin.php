#!/usr/bin/env php
<?php

/**
 * Local-only owner PIN recovery CLI.
 *
 * Requires console access to the application host and database credentials.
 * Never expose this over HTTP.
 *
 * Usage:
 *   php scripts/recover_owner_pin.php --pin=1234
 *   php scripts/recover_owner_pin.php --user-id=1 --pin=1234 --force
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../classes/Security/PinService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Security/LocalSecurityBootstrapService.php';

$options = getopt('', ['pin:', 'user-id::', 'force', 'help']);
if (isset($options['help']) || empty($options['pin'])) {
    echo "Usage: php scripts/recover_owner_pin.php --pin=1234 [--user-id=ID] [--force]\n";
    exit(isset($options['help']) ? 0 : 1);
}

$pin = (string) $options['pin'];
$userId = isset($options['user-id']) ? (int) $options['user-id'] : 0;
$force = isset($options['force']);

try {
    if (function_exists('posmain_main_auth_mode') && posmain_main_auth_mode() !== 'pin' && !$force) {
        throw new RuntimeException('MAIN_AUTH_MODE_NOT_PIN (pass --force to override)');
    }

    $conn = posmain_db_connect();
    $pinService = new PinService();
    $pinService->validatePinFormat($pin);

    if ($userId < 1) {
        $bootstrap = new LocalSecurityBootstrapService();
        $state = $bootstrap->currentState($conn);
        $userId = (int) ($state['owner_user_id'] ?? 0);
        if ($userId < 1) {
            $ownerResult = $conn->query(
                'SELECT id FROM users WHERE COALESCE(isdeleted,0) != 1 AND (usertype = 2 OR userrole = 1) ORDER BY id ASC LIMIT 1'
            );
            if ($ownerResult === false) {
                throw new RuntimeException('OWNER_LOOKUP_FAILED: ' . $conn->error);
            }
            $row = $ownerResult->fetch_assoc();
            $userId = (int) ($row['id'] ?? 0);
        }
    }
    if ($userId < 1) {
        throw new RuntimeException('OWNER_USER_NOT_FOUND');
    }

    $pinService->setPinForUser($conn, $userId, $pin, [
        'must_change' => true,
        'bump_auth_version' => true,
    ]);

    $bootstrapTable = $conn->query("SHOW TABLES LIKE 'security_bootstrap_state'");
    if ($bootstrapTable === false) {
        throw new RuntimeException('BOOTSTRAP_TABLE_CHECK_FAILED: ' . $conn->error);
    }
    if ($bootstrapTable->num_rows > 0) {
        $updated = $conn->query(
            "UPDATE security_bootstrap_state
                SET status = 'completed', completed_at = NOW(), updated_at = CURRENT_TIMESTAMP
              WHERE id = 1"
        );
        if ($updated === false) {
            throw new RuntimeException('BOOTSTRAP_STATUS_UPDATE_FAILED: ' . $conn->error);
        }
    }

    (new SecurityAuditLogger())->record($conn, 'owner_pin_recovered_cli', [
        'user_id' => null,
        'target_type' => 'user',
        'target_id' => $userId,
        'metadata' => ['source' => 'cli'],
    ]);

    echo "OK user_id={$userId} pin_must_change=1 auth_version bumped\n";
    echo "Temporary PIN set. It will be required to change on next login.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
}
