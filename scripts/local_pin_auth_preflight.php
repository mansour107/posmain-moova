#!/usr/bin/env php
<?php

/**
 * Preflight checks before enabling POSMAIN_MAIN_AUTH_MODE=pin on a branch.
 *
 * Usage:
 *   php scripts/local_pin_auth_preflight.php
 *   php scripts/local_pin_auth_preflight.php --json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../config/app_config.php';

$asJson = in_array('--json', $argv, true);
$report = [
    'ok' => true,
    'checks' => [],
];

function preflightAdd(array &$report, string $key, bool $ok, string $detail): void
{
    $report['checks'][$key] = ['ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $report['ok'] = false;
    }
}

try {
    $mode = function_exists('posmain_main_auth_mode') ? posmain_main_auth_mode() : 'unknown';
    $role = (string) (getenv('POSMAIN_ROLE') ?: ($_ENV['POSMAIN_ROLE'] ?? 'unknown'));
    preflightAdd(
        $report,
        'auth_mode',
        in_array($mode, ['pin', 'password'], true),
        'main_auth_mode=' . $mode . ' role=' . $role
    );
    if ($mode === 'pin' && in_array(strtolower($role), ['cloud', 'router'], true)) {
        preflightAdd($report, 'hosted_pin_forbidden', false, 'PIN main auth is forbidden on cloud/router');
    } else {
        preflightAdd($report, 'hosted_pin_forbidden', true, 'deployment role/mode combination accepted');
    }

    try {
        posmain_pin_secret();
        preflightAdd($report, 'pin_secret', true, 'POSMAIN_PIN_SECRET present');
    } catch (Throwable $e) {
        preflightAdd($report, 'pin_secret', false, $e->getMessage());
    }

    $conn = posmain_db_connect();
    $tables = [
        'security_bootstrap_state',
        'pos_registers',
        'drawer_sessions',
        'users',
    ];
    foreach ($tables as $table) {
        $exists = $conn->query("SHOW TABLES LIKE '{$table}'");
        preflightAdd($report, 'table_' . $table, $exists && $exists->num_rows > 0, $table);
    }

    $userCols = ['pin_hash', 'pin_lookup', 'pin_must_change', 'auth_version'];
    foreach ($userCols as $col) {
        $res = $conn->query("SHOW COLUMNS FROM users LIKE '{$col}'");
        preflightAdd($report, 'users_' . $col, $res && $res->num_rows > 0, $col);
    }

    $drawerCols = ['register_id', 'open_register_lock', 'open_user_lock', 'business_day'];
    foreach ($drawerCols as $col) {
        $res = $conn->query("SHOW COLUMNS FROM drawer_sessions LIKE '{$col}'");
        preflightAdd($report, 'drawer_' . $col, $res && $res->num_rows > 0, $col);
    }

    $activeNoPin = 0;
    $q = $conn->query(
        "SELECT COUNT(*) AS c FROM users
          WHERE COALESCE(isdeleted,0) != 1
            AND (pin_hash IS NULL OR pin_lookup IS NULL OR pin_set_at IS NULL)"
    );
    if ($q) {
        $activeNoPin = (int) ($q->fetch_assoc()['c'] ?? 0);
    }
    preflightAdd(
        $report,
        'active_users_with_pin',
        $mode !== 'pin' || $activeNoPin === 0,
        'active_users_missing_pin=' . $activeNoPin
    );

    $legacyEnc = 0;
    $encCol = $conn->query("SHOW COLUMNS FROM users LIKE 'pin_enc'");
    if ($encCol && $encCol->num_rows > 0) {
        $q = $conn->query('SELECT COUNT(*) AS c FROM users WHERE pin_enc IS NOT NULL AND pin_enc != ""');
        $legacyEnc = $q ? (int) ($q->fetch_assoc()['c'] ?? 0) : 0;
    }
    preflightAdd($report, 'legacy_pin_enc_cleared', $legacyEnc === 0, 'pin_enc_rows=' . $legacyEnc);

    $openDrawers = 0;
    $q = $conn->query("SELECT COUNT(*) AS c FROM drawer_sessions WHERE status = 'open'");
    if ($q) {
        $openDrawers = (int) ($q->fetch_assoc()['c'] ?? 0);
    }
    preflightAdd(
        $report,
        'open_drawers_noted',
        true,
        'open_drawers=' . $openDrawers . ' (close before register lock cutover if migrating)'
    );

    $pageManifest = require __DIR__ . '/../config/rbac_page_manifest.php';
    $routeManifest = require __DIR__ . '/../config/rbac_route_manifest.php';
    preflightAdd($report, 'page_manifest', is_array($pageManifest) && $pageManifest !== [], 'pages=' . count($pageManifest));
    preflightAdd($report, 'route_manifest', is_array($routeManifest) && $routeManifest !== [], 'routes=' . count($routeManifest));
} catch (Throwable $exception) {
    preflightAdd($report, 'fatal', false, $exception->getMessage());
}

if ($asJson) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo ($report['ok'] ? "PREFLIGHT OK\n" : "PREFLIGHT FAILED\n");
    foreach ($report['checks'] as $key => $check) {
        echo ($check['ok'] ? '[ok] ' : '[!!] ') . $key . ' — ' . $check['detail'] . "\n";
    }
}

exit($report['ok'] ? 0 : 2);
