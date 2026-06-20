<?php

$endpoint = file_get_contents(__DIR__ . '/../../api/sync/pairing_status.php');
$pairingStatus = file_get_contents(__DIR__ . '/../../classes/Sync/PairingStatusService.php');
$ajax = file_get_contents(__DIR__ . '/../../ajax/sync_credentials.php');

pairingStatusEndpointAssert(is_string($endpoint), 'pairing_status endpoint should be readable');
pairingStatusEndpointAssert(is_string($pairingStatus), 'PairingStatusService should be readable');
pairingStatusEndpointAssert(is_string($ajax), 'sync_credentials ajax should be readable');

foreach ([
    'includes/sync_route.php',
    'posmain_sync_db_connect_for_payload',
    'BranchSecretProviderFactory',
    'CloudAuthService',
    'PairingStatusService',
    'hostedProbe',
    'GET /api/sync/pairing_status.php?',
    'touchLastSeen',
] as $snippet) {
    pairingStatusEndpointAssert(strpos($endpoint, $snippet) !== false, 'pairing_status endpoint missing snippet: ' . $snippet);
}

foreach ([
    'remoteHostedStatus',
    'localDashboard',
    'hostedDashboard',
    'hostedProbe',
    'sync_schema_ready',
    'shop_db_name',
    '/api/sync/pairing_status.php',
] as $snippet) {
    pairingStatusEndpointAssert(strpos($pairingStatus, $snippet) !== false, 'PairingStatusService missing snippet: ' . $snippet);
}

foreach ([
    "case 'pairing_status':",
    "case 'worker_status':",
    'PairingStatusService',
    'SyncWorkerHealthService',
    'testPairing($_POST, $appConfig, $conn)',
] as $snippet) {
    pairingStatusEndpointAssert(strpos($ajax, $snippet) !== false, 'sync_credentials ajax missing snippet: ' . $snippet);
}

echo "pairing-status-endpoint-contract-ok\n";

function pairingStatusEndpointAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
