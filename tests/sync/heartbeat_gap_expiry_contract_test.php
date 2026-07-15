<?php

/**
 * Contract: PIN-mode heartbeat gap soft-locks abandoned tabs without ending drawer DB rows.
 */

putenv('POSMAIN_MAIN_AUTH_MODE=pin');
$_ENV['POSMAIN_MAIN_AUTH_MODE'] = 'pin';
putenv('POSMAIN_HEARTBEAT_GRACE_SECONDS=60');
$_ENV['POSMAIN_HEARTBEAT_GRACE_SECONDS'] = '60';
putenv('POSMAIN_SESSION_IDLE_SECONDS=3600');
$_ENV['POSMAIN_SESSION_IDLE_SECONDS'] = '3600';
putenv('POSMAIN_SESSION_ABSOLUTE_SECONDS=86400');
$_ENV['POSMAIN_SESSION_ABSOLUTE_SECONDS'] = '86400';
putenv('POSMAIN_INACTIVITY_LOCK_SECONDS=3600');
$_ENV['POSMAIN_INACTIVITY_LOCK_SECONDS'] = '3600';

require_once __DIR__ . '/../../includes/session_bootstrap.php';
require_once __DIR__ . '/../../classes/Security/MainAuthenticationService.php';

function heartbeatGapAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

heartbeatGapAssert(function_exists('posmain_session_soft_lock'), 'soft-lock helper must exist');
heartbeatGapAssert(function_exists('posmain_session_touch'), 'session touch helper must exist');
heartbeatGapAssert(posmain_is_pin_main_auth(), 'test must run in PIN main auth mode');

$sourceBootstrap = (string) file_get_contents(__DIR__ . '/../../includes/session_bootstrap.php');
heartbeatGapAssert(
    strpos($sourceBootstrap, 'POSMAIN_HEARTBEAT_GRACE_SECONDS') !== false,
    'session bootstrap must honor POSMAIN_HEARTBEAT_GRACE_SECONDS'
);
heartbeatGapAssert(
    strpos($sourceBootstrap, 'posmain_heartbeat_last_at') !== false,
    'session bootstrap must check posmain_heartbeat_last_at'
);
heartbeatGapAssert(
    strpos($sourceBootstrap, 'posmain_session_soft_lock') !== false,
    'session bootstrap must soft-lock abandoned sessions'
);

$sourceClient = (string) file_get_contents(__DIR__ . '/../../includes/main_session_lock_client.php');
heartbeatGapAssert(
    strpos($sourceClient, 'main_session_heartbeat.php') !== false,
    'lock client must send heartbeats'
);
heartbeatGapAssert(
    strpos($sourceClient, 'skipInactivityLock') !== false,
    'lock client must support KDS inactivity skip'
);

$sourceHeartbeat = (string) file_get_contents(__DIR__ . '/../../ajax/main_session_heartbeat.php');
heartbeatGapAssert(
    strpos($sourceHeartbeat, "posmain_heartbeat_last_at") !== false,
    'heartbeat endpoint must stamp posmain_heartbeat_last_at'
);

$routeManifest = require __DIR__ . '/../../config/rbac_route_manifest.php';
heartbeatGapAssert(
    isset($routeManifest['ajax/main_session_heartbeat.php']),
    'heartbeat route must be registered'
);
heartbeatGapAssert(
    ($routeManifest['ajax/main_session_heartbeat.php']['lane'] ?? '') === 'erp',
    'heartbeat route must be ERP-lane authenticated'
);
heartbeatGapAssert(
    strpos($sourceClient, 'AUTH_VERSION_STALE') !== false
        || strpos($sourceClient, 'AUTH_REQUIRED') !== false,
    'heartbeat client must not redirect on every 403'
);

$kdsSource = (string) file_get_contents(__DIR__ . '/../../kds.php');
$kdsStationSource = (string) file_get_contents(__DIR__ . '/../../kds_station.php');
heartbeatGapAssert(
    strpos($kdsSource, '$posmainSkipInactivityLock = true') !== false,
    'kds.php must skip inactivity lock'
);
heartbeatGapAssert(
    strpos($kdsStationSource, '$posmainSkipInactivityLock = true') !== false,
    'kds_station.php must skip inactivity lock'
);

$envExample = (string) file_get_contents(__DIR__ . '/../../.env.example');
heartbeatGapAssert(
    strpos($envExample, 'POSMAIN_HEARTBEAT_GRACE_SECONDS') !== false,
    '.env.example must document heartbeat grace'
);

// Behavioral: fresh session without heartbeat timestamp is unaffected.
$_SESSION['login'] = 'cashier1';
$_SESSION['userid'] = 42;
$_SESSION['posmain_session_started_at'] = time();
$_SESSION['posmain_session_last_seen_at'] = time();
unset($_SESSION['posmain_heartbeat_last_at']);
unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT']);
posmain_session_touch();
heartbeatGapAssert(
    (int) ($_SESSION['userid'] ?? 0) === 42,
    'fresh session without heartbeat must remain logged in'
);

// Behavioral: recent heartbeat is unaffected.
$_SESSION['login'] = 'cashier1';
$_SESSION['userid'] = 42;
$_SESSION['posmain_heartbeat_last_at'] = time() - 30;
$_SESSION['posmain_session_started_at'] = time() - 30;
$_SESSION['posmain_session_last_seen_at'] = time() - 30;
unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT']);
posmain_session_touch();
heartbeatGapAssert(
    (int) ($_SESSION['userid'] ?? 0) === 42,
    'session with recent heartbeat must remain logged in'
);

// Document navigation with a stale heartbeat must NOT soft-lock (user is present).
$_SESSION['login'] = 'cashier1';
$_SESSION['userid'] = 42;
$_SESSION['pos_drawer_session_id'] = 99;
$_SESSION['posmain_heartbeat_last_at'] = time() - 120;
$_SESSION['posmain_session_started_at'] = time() - 120;
$_SESSION['posmain_session_last_seen_at'] = time() - 120;
unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT']);
$_SERVER['SCRIPT_NAME'] = '/sales.php';
posmain_session_touch();
heartbeatGapAssert(
    (int) ($_SESSION['userid'] ?? 0) === 42,
    'document navigation with stale heartbeat must keep identity'
);
heartbeatGapAssert(
    !empty($_SESSION['posmain_heartbeat_last_at'])
        && (time() - (int) $_SESSION['posmain_heartbeat_last_at']) < 5,
    'document navigation must refresh heartbeat stamp'
);

// XHR/poll with stale heartbeat soft-locks identity but keeps PHP session alive
// (drawer durability is DB-backed; soft-lock must not destroy the session cookie).
$_SESSION['login'] = 'cashier1';
$_SESSION['userid'] = 42;
$_SESSION['pos_drawer_session_id'] = 99;
$_SESSION['posmain_heartbeat_last_at'] = time() - 120;
$_SESSION['posmain_session_started_at'] = time() - 120;
$_SESSION['posmain_session_last_seen_at'] = time() - 120;
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_SERVER['SCRIPT_NAME'] = '/ajax/main_session_heartbeat.php';
$beforeStatus = session_status();
posmain_session_touch();
heartbeatGapAssert(
    $beforeStatus === PHP_SESSION_ACTIVE && session_status() === PHP_SESSION_ACTIVE,
    'soft-lock must keep the PHP session active'
);
heartbeatGapAssert(
    empty($_SESSION['userid']) && empty($_SESSION['login']),
    'stale heartbeat on XHR must clear login identity'
);
heartbeatGapAssert(
    empty($_SESSION['pos_drawer_session_id']),
    'soft-lock must clear in-session drawer pointer'
);
heartbeatGapAssert(
    empty($_SESSION['posmain_heartbeat_last_at']),
    'soft-lock must clear stale heartbeat stamp'
);
unset($_SERVER['HTTP_X_REQUESTED_WITH']);

heartbeatGapAssert(
    function_exists('posmain_is_background_session_request'),
    'bootstrap must expose background-request helper'
);

// Ensure MainAuthenticationService clears heartbeat on lock.
$_SESSION['login'] = 'cashier1';
$_SESSION['userid'] = 42;
$_SESSION['posmain_heartbeat_last_at'] = time();
(new MainAuthenticationService())->lockToLoginScreen();
heartbeatGapAssert(
    empty($_SESSION['posmain_heartbeat_last_at']) && empty($_SESSION['userid']),
    'lockToLoginScreen must clear heartbeat and identity'
);

echo "heartbeat-gap-expiry-contract-ok\n";
