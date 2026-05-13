<?php

require_once __DIR__ . '/../classes/Sync/ArrayBranchSecretProvider.php';
require_once __DIR__ . '/../classes/Sync/BranchSecretProvider.php';
require_once __DIR__ . '/../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../classes/Sync/MoovaBranchEventCursor.php';
require_once __DIR__ . '/../classes/Sync/OutboxWorker.php';
require_once __DIR__ . '/../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../classes/Sync/SyncDeliveryResultHandler.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage: POSMAIN_TEST_MYSQL_PORT=3307 php tools/e2e_mock_online_offline_sync.php\n";
    echo "\n";
    echo "Runs a local two-mock-server online/offline sync proof against the test database.\n";
    echo "The harness starts a mock cloud server and a mock branch server, inserts scoped e2e rows, and cleans those rows after the run.\n";
    echo "\n";
    echo "Scenarios:\n";
    echo "- cloud_receive_only\n";
    echo "- cloud_shadow_apply\n";
    echo "- cloud_live_apply\n";
    echo "- online_cloud_down_first_attempt\n";
    echo "- online_cloud_back_retries_failed_event\n";
    echo "- branch_worker_crash_lock_expires_and_reclaims\n";
    echo "- offline_branch_down_first_attempt\n";
    echo "- offline_branch_back_cloud_event_delivered_and_acked\n";
    echo "\n";
    echo "Output is JSON and includes report_path, mock server URLs, per-scenario pass/fail details, and log paths.\n";
    exit(0);
}

assertE2eRuntimeRequirements();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$runId = 'e2e:' . date('YmdHis') . ':' . bin2hex(random_bytes(3));
$branchUuid = '11111111-2222-3333-4444-555555555555';
$branchSecret = 'local-e2e-branch-secret';
$tmpRoot = sys_get_temp_dir() . '/posmain-sync-e2e-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $runId);
mkdir($tmpRoot, 0777, true);

$cloudStateFile = $tmpRoot . '/cloud-state.json';
$cloudLogFile = $tmpRoot . '/cloud.log';
$branchLogFile = $tmpRoot . '/branch.log';
file_put_contents($cloudStateFile, json_encode(['mode' => SyncApplyMode::SHADOW_APPLY], JSON_PRETTY_PRINT));

$children = [];

try {
    $cloud = startServer('cloud', function (array $request) use ($branchUuid, $branchSecret, $cloudStateFile, $cloudLogFile): array {
        $state = json_decode(file_get_contents($cloudStateFile), true) ?: [];
        $mode = $state['mode'] ?? SyncApplyMode::SHADOW_APPLY;
        $headers = $request['headers'];
        $rawBody = $request['body'];
        $auth = (new CloudAuthService())->verifyRequest(
            new ArrayBranchSecretProvider([$branchUuid => $branchSecret]),
            $headers['x-branch-uuid'] ?? '',
            $headers['x-timestamp'] ?? '',
            $headers['x-nonce'] ?? '',
            $rawBody,
            $headers['x-signature'] ?? ''
        );

        if (!$auth['ok']) {
            appendJsonLine($cloudLogFile, ['type' => 'reject', 'reason' => $auth['reason']]);
            return jsonResponse(401, ['ok' => false, 'reason' => $auth['reason']]);
        }

        $payload = json_decode($rawBody, true);
        $results = [];
        foreach (($payload['events'] ?? []) as $event) {
            $results[] = SyncApplyMode::acceptedResult(
                $mode,
                (string) $event['event_uuid'],
                (string) $event['idempotency_key'],
                'cloud-' . substr((string) $event['event_uuid'], 0, 8),
                $mode . ' mock accept'
            );
        }

        appendJsonLine($cloudLogFile, ['type' => 'push', 'mode' => $mode, 'count' => count($results)]);
        return jsonResponse(200, SyncApplyMode::response($mode, $results));
    });
    $children[] = $cloud;

    $branch = startServer('branch', function (array $request) use ($branchLogFile): array {
        $payload = json_decode($request['body'], true) ?: [];
        $acks = [];
        foreach (($payload['events'] ?? []) as $event) {
            $acks[] = [
                'event_uuid' => (string) $event['event_uuid'],
                'idempotency_key' => (string) $event['idempotency_key'],
                'ack_status' => 'ack_applied',
            ];
        }

        appendJsonLine($branchLogFile, ['type' => 'moova_events', 'count' => count($acks)]);
        return jsonResponse(200, ['ok' => true, 'acks' => $acks]);
    });
    $children[] = $branch;

    $conn = connectTestDb();
    $conn->set_charset('utf8mb4');
    (new SyncSchemaManager())->apply($conn);
    cleanupRows($conn, 'e2e:');

    $results = [];
    $results[] = scenarioCloudMode($conn, $runId, $branchUuid, $branchSecret, $cloud, $cloudStateFile, SyncApplyMode::RECEIVE_ONLY);
    $results[] = scenarioCloudMode($conn, $runId, $branchUuid, $branchSecret, $cloud, $cloudStateFile, SyncApplyMode::SHADOW_APPLY);
    $results[] = scenarioCloudMode($conn, $runId, $branchUuid, $branchSecret, $cloud, $cloudStateFile, SyncApplyMode::LIVE_APPLY);

    stopServer($cloud);
    $children = array_values(array_filter($children, fn ($child) => $child['pid'] !== $cloud['pid']));
    $cloudDropResult = scenarioCloudDrop($conn, $runId, $branchUuid, $branchSecret, $cloud);
    $conn->close();
    $cloud = startServer('cloud-restarted', function (array $request) use ($branchUuid, $branchSecret, $cloudStateFile, $cloudLogFile): array {
        file_put_contents($cloudStateFile, json_encode(['mode' => SyncApplyMode::LIVE_APPLY], JSON_PRETTY_PRINT));
        $headers = $request['headers'];
        $rawBody = $request['body'];
        $auth = (new CloudAuthService())->verifyRequest(
            new ArrayBranchSecretProvider([$branchUuid => $branchSecret]),
            $headers['x-branch-uuid'] ?? '',
            $headers['x-timestamp'] ?? '',
            $headers['x-nonce'] ?? '',
            $rawBody,
            $headers['x-signature'] ?? ''
        );
        if (!$auth['ok']) {
            appendJsonLine($cloudLogFile, ['type' => 'reject_after_restart', 'reason' => $auth['reason']]);
            return jsonResponse(401, ['ok' => false, 'reason' => $auth['reason']]);
        }

        $payload = json_decode($rawBody, true);
        $results = [];
        foreach (($payload['events'] ?? []) as $event) {
            $results[] = SyncApplyMode::acceptedResult(
                SyncApplyMode::LIVE_APPLY,
                (string) $event['event_uuid'],
                (string) $event['idempotency_key'],
                'cloud-' . substr((string) $event['event_uuid'], 0, 8),
                'live_apply after restart'
            );
        }
        appendJsonLine($cloudLogFile, ['type' => 'push_after_restart', 'count' => count($results)]);
        return jsonResponse(200, SyncApplyMode::response(SyncApplyMode::LIVE_APPLY, $results));
    }, $cloud['port']);
    $children[] = $cloud;
    $conn = connectTestDb();
    $conn->set_charset('utf8mb4');
    $cloudDropResult = finishCloudDropAfterRestart($conn, $branchUuid, $branchSecret, $cloud, $cloudDropResult);
    $results[] = $cloudDropResult;

    $results[] = scenarioWorkerCrashReclaim($conn, $runId, $branchUuid, $branchSecret, $cloud);

    stopServer($branch);
    $children = array_values(array_filter($children, fn ($child) => $child['pid'] !== $branch['pid']));
    $branchDropResult = scenarioBranchDrop($conn, $runId, $branchUuid, $branch);
    $conn->close();
    $branch = startServer('branch-restarted', function (array $request) use ($branchLogFile): array {
        $payload = json_decode($request['body'], true) ?: [];
        $acks = [];
        foreach (($payload['events'] ?? []) as $event) {
            $acks[] = [
                'event_uuid' => (string) $event['event_uuid'],
                'idempotency_key' => (string) $event['idempotency_key'],
                'ack_status' => 'ack_applied',
            ];
        }
        appendJsonLine($branchLogFile, ['type' => 'moova_events_after_restart', 'count' => count($acks)]);
        return jsonResponse(200, ['ok' => true, 'acks' => $acks]);
    }, $branch['port']);
    $children[] = $branch;
    $conn = connectTestDb();
    $conn->set_charset('utf8mb4');
    $results[] = finishBranchDropAfterRestart($conn, $branchUuid, $branch, $branchDropResult);

    $summary = [
        'run_id' => $runId,
        'db' => testDbDsnForOutput(),
        'mock_servers' => [
            'cloud' => 'http://127.0.0.1:' . $cloud['port'],
            'branch' => 'http://127.0.0.1:' . $branch['port'],
        ],
        'results' => $results,
        'logs' => [
            'cloud' => $cloudLogFile,
            'branch' => $branchLogFile,
        ],
    ];

    $reportPath = $tmpRoot . '/report.json';
    file_put_contents($reportPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode($summary + ['report_path' => $reportPath], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    cleanupRows($conn, $runId);
    exit(hasFailures($results) ? 1 : 0);
} finally {
    foreach ($children as $child) {
        stopServer($child);
    }
}

function connectTestDb(): mysqli
{
    $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
    $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
    $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
    $db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

    return new mysqli($host, $user, $pass, $db, $port);
}

function testDbDsnForOutput(): string
{
    return sprintf(
        '%s:%s/%s',
        getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2'
    );
}

function cleanupRows(mysqli $conn, string $runId): void
{
    $like = $conn->real_escape_string($runId . '%');
    $conn->query("DELETE FROM sync_outbox WHERE idempotency_key LIKE '{$like}'");
    $conn->query("DELETE FROM cloud_moova_branch_events WHERE idempotency_key LIKE '{$like}'");
}

function scenarioCloudMode(
    mysqli $conn,
    string $runId,
    string $branchUuid,
    string $branchSecret,
    array $cloud,
    string $cloudStateFile,
    string $mode
): array {
    file_put_contents($cloudStateFile, json_encode(['mode' => $mode], JSON_PRETTY_PRINT));
    $event = insertOutboxEvent($conn, $runId, $branchUuid, 'cloud-mode-' . $mode);
    $claimed = (new OutboxWorker())->claimBatch($conn, 'e2e-' . $mode, 10, 5);
    $push = pushOutboxRows($conn, $claimed, $branchUuid, $branchSecret, cloudPushUrl($cloud));
    $row = fetchOutboxById($conn, $event['id']);
    $first = $push['json']['results'][0] ?? [];

    return assertScenario('cloud_' . $mode, [
        'event_id' => $event['id'],
        'http_status' => $push['status'],
        'cloud_status' => $first['status'] ?? null,
        'applied' => $first['applied'] ?? null,
        'report_trusted' => $first['report_trusted'] ?? null,
        'outbox_status' => $row['status'],
        'attempts' => (int) $row['attempts'],
    ], [
        $push['ok'],
        $row['status'] === 'synced',
        (int) $row['attempts'] === 1,
        $mode === SyncApplyMode::LIVE_APPLY ? ($first['status'] ?? null) === 'processed' : ($first['status'] ?? null) === 'accepted_shadow',
        $mode === SyncApplyMode::RECEIVE_ONLY ? ($first['applied'] ?? true) === false : ($first['applied'] ?? false) === true,
        $mode === SyncApplyMode::LIVE_APPLY ? ($first['report_trusted'] ?? false) === true : ($first['report_trusted'] ?? true) === false,
    ]);
}

function scenarioCloudDrop(mysqli $conn, string $runId, string $branchUuid, string $branchSecret, array $cloud): array
{
    $event = insertOutboxEvent($conn, $runId, $branchUuid, 'cloud-down');
    $claimed = (new OutboxWorker())->claimBatch($conn, 'e2e-cloud-down', 10, 5);
    $push = pushOutboxRows($conn, $claimed, $branchUuid, $branchSecret, cloudPushUrl($cloud));
    $row = fetchOutboxById($conn, $event['id']);

    return assertScenario('online_cloud_down_first_attempt', [
        'event_id' => $event['id'],
        'http_ok' => $push['ok'],
        'error' => $push['error'],
        'outbox_status_after_drop' => $row['status'],
        'attempts_after_drop' => (int) $row['attempts'],
    ], [
        !$push['ok'],
        $row['status'] === 'failed',
        (int) $row['attempts'] === 1,
    ]) + ['event_id' => $event['id']];
}

function finishCloudDropAfterRestart(mysqli $conn, string $branchUuid, string $branchSecret, array $cloud, array $previous): array
{
    $claimed = (new OutboxWorker())->claimBatch($conn, 'e2e-cloud-recovery', 10, 5);
    $push = pushOutboxRows($conn, $claimed, $branchUuid, $branchSecret, cloudPushUrl($cloud));
    $row = fetchOutboxById($conn, $previous['event_id']);

    return assertScenario('online_cloud_back_retries_failed_event', $previous['details'] + [
        'retry_http_status' => $push['status'],
        'final_outbox_status' => $row['status'],
        'final_attempts' => (int) $row['attempts'],
    ], [
        $previous['pass'],
        $push['ok'],
        $row['status'] === 'synced',
        (int) $row['attempts'] === 2,
    ]);
}

function scenarioWorkerCrashReclaim(mysqli $conn, string $runId, string $branchUuid, string $branchSecret, array $cloud): array
{
    $event = insertOutboxEvent($conn, $runId, $branchUuid, 'worker-crash');
    $claimedByCrashedWorker = (new OutboxWorker())->claimBatch($conn, 'e2e-crashed-worker', 10, 1);
    usleep(1300000);
    $claimedByRecoveryWorker = (new OutboxWorker())->claimBatch($conn, 'e2e-recovery-worker', 10, 5);
    $push = pushOutboxRows($conn, $claimedByRecoveryWorker, $branchUuid, $branchSecret, cloudPushUrl($cloud));
    $row = fetchOutboxById($conn, $event['id']);

    return assertScenario('branch_worker_crash_lock_expires_and_reclaims', [
        'event_id' => $event['id'],
        'crashed_claim_count' => count($claimedByCrashedWorker),
        'recovery_claim_count' => count($claimedByRecoveryWorker),
        'retry_http_status' => $push['status'],
        'final_outbox_status' => $row['status'],
        'final_attempts' => (int) $row['attempts'],
        'final_locked_by' => $row['locked_by'],
    ], [
        count($claimedByCrashedWorker) === 1,
        count($claimedByRecoveryWorker) >= 1,
        $push['ok'],
        $row['status'] === 'synced',
        (int) $row['attempts'] === 2,
        $row['locked_by'] === null,
    ]);
}

function scenarioBranchDrop(mysqli $conn, string $runId, string $branchUuid, array $branch): array
{
    $event = insertMoovaEvent($conn, $runId, $branchUuid, 'branch-down');
    $cursor = new MoovaBranchEventCursor();
    $pending = $cursor->fetchPendingAfter($conn, $branchUuid, 0, 10);
    $push = pushMoovaEventsToBranch($pending, branchMoovaUrl($branch));
    markMoovaDeliveryFailure($conn, $pending, $push['error'] ?: 'branch unavailable');
    $row = fetchMoovaById($conn, $event['id']);

    return assertScenario('offline_branch_down_first_attempt', [
        'event_id' => $event['id'],
        'pending_count' => count($pending),
        'http_ok' => $push['ok'],
        'error' => $push['error'],
        'event_status_after_drop' => $row['status'],
        'attempts_after_drop' => (int) $row['attempts'],
    ], [
        count($pending) >= 1,
        !$push['ok'],
        $row['status'] === 'pending',
        (int) $row['attempts'] === 1,
    ]) + ['event_id' => $event['id']];
}

function finishBranchDropAfterRestart(mysqli $conn, string $branchUuid, array $branch, array $previous): array
{
    $cursor = new MoovaBranchEventCursor();
    $pending = $cursor->fetchPendingAfter($conn, $branchUuid, 0, 10);
    $push = pushMoovaEventsToBranch($pending, branchMoovaUrl($branch));

    foreach (($push['json']['acks'] ?? []) as $ack) {
        $cursor->ackByEvent(
            $conn,
            (string) $ack['event_uuid'],
            (string) $ack['idempotency_key'],
            (string) $ack['ack_status']
        );
    }

    $row = fetchMoovaById($conn, $previous['event_id']);

    return assertScenario('offline_branch_back_cloud_event_delivered_and_acked', $previous['details'] + [
        'retry_http_status' => $push['status'],
        'final_event_status' => $row['status'],
        'final_attempts' => (int) $row['attempts'],
    ], [
        $previous['pass'],
        $push['ok'],
        $row['status'] === 'ack_applied',
        (int) $row['attempts'] === 1,
    ]);
}

function insertOutboxEvent(mysqli $conn, string $runId, string $branchUuid, string $suffix): array
{
    $eventUuid = uuid();
    $idempotencyKey = $runId . ':outbox:' . $suffix;
    $payloadJson = json_encode([
        'run_id' => $runId,
        'scenario' => $suffix,
        'amount' => 12.34,
        'created_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES);
    $payloadHash = hash('sha256', $payloadJson);
    $aggregateUuid = uuid();
    $entityUuid = uuid();
    $aggregateId = 'order:' . $suffix;

    $stmt = $conn->prepare("
        INSERT INTO sync_outbox (
            event_uuid, branch_uuid, pos_tenant, pos_branch, aggregate_type, aggregate_uuid,
            aggregate_local_id, entity_type, entity_uuid, entity_local_id, aggregate_id, event_type,
            event_version, source_system, source_event_uuid, idempotency_key, payload_json,
            payload_hash, status, attempts
        ) VALUES (?, ?, 0, 0, 'order', ?, 1, 'order', ?, 1, ?, 'order.saved', 1, 'pos', NULL, ?, ?, ?, 'pending', 0)
    ");
    $stmt->bind_param('ssssssss', $eventUuid, $branchUuid, $aggregateUuid, $entityUuid, $aggregateId, $idempotencyKey, $payloadJson, $payloadHash);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();

    return ['id' => $id, 'event_uuid' => $eventUuid, 'idempotency_key' => $idempotencyKey];
}

function insertMoovaEvent(mysqli $conn, string $runId, string $branchUuid, string $suffix): array
{
    $eventUuid = uuid();
    $idempotencyKey = $runId . ':moova:' . $suffix;
    $payloadJson = json_encode([
        'run_id' => $runId,
        'scenario' => $suffix,
        'order_id' => 'moova-' . substr($eventUuid, 0, 8),
    ], JSON_UNESCAPED_SLASHES);
    $payloadHash = hash('sha256', $payloadJson);
    $moovaOrderId = 'moova-order-' . substr($eventUuid, 0, 8);

    $stmt = $conn->prepare("
        INSERT INTO cloud_moova_branch_events (
            event_uuid, branch_uuid, moova_order_id, moova_branch_id, event_type,
            idempotency_key, payload_hash, payload_json, status, attempts
        ) VALUES (?, ?, ?, 'e2e-branch', 'new_order', ?, ?, ?, 'pending', 0)
    ");
    $stmt->bind_param('ssssss', $eventUuid, $branchUuid, $moovaOrderId, $idempotencyKey, $payloadHash, $payloadJson);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();

    return ['id' => $id, 'event_uuid' => $eventUuid, 'idempotency_key' => $idempotencyKey];
}

function pushOutboxRows(mysqli $conn, array $rows, string $branchUuid, string $secret, string $url): array
{
    $events = [];
    foreach ($rows as $row) {
        $events[] = [
            'event_uuid' => $row['event_uuid'],
            'idempotency_key' => $row['idempotency_key'],
            'payload' => json_decode($row['payload_json'], true),
        ];
    }

    $body = json_encode(['events' => $events], JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(8));
    $signature = CloudAuthService::sign($secret, $timestamp, $nonce, $body);
    $response = postJson($url, $body, [
        'Content-Type: application/json',
        'X-Branch-UUID: ' . $branchUuid,
        'X-Timestamp: ' . $timestamp,
        'X-Nonce: ' . $nonce,
        'X-Signature: ' . $signature,
    ]);

    if (!$response['ok']) {
        markOutboxFailure($conn, $rows, $response['error'] ?: 'cloud unavailable');
        return $response;
    }

    foreach (($response['json']['results'] ?? []) as $result) {
        $status = SyncDeliveryResultHandler::outboxStatusForResult($result);
        $eventUuid = $conn->real_escape_string((string) $result['event_uuid']);
        $idempotencyKey = $conn->real_escape_string((string) $result['idempotency_key']);
        if ($status === 'synced') {
            $conn->query("
                UPDATE sync_outbox
                SET status = 'synced',
                    locked_by = NULL,
                    locked_until = NULL,
                    next_retry_at = NULL,
                    last_error = NULL,
                    synced_at = NOW(6)
                WHERE event_uuid = '{$eventUuid}'
                  AND idempotency_key = '{$idempotencyKey}'
            ");
        } elseif ($status === 'dead') {
            $conn->query("
                UPDATE sync_outbox
                SET status = 'dead',
                    locked_by = NULL,
                    locked_until = NULL,
                    last_error = 'conflict'
                WHERE event_uuid = '{$eventUuid}'
                  AND idempotency_key = '{$idempotencyKey}'
            ");
        } else {
            markOutboxFailure($conn, $rows, 'cloud returned failed');
        }
    }

    return $response;
}

function markOutboxFailure(mysqli $conn, array $rows, string $error): void
{
    $escapedError = $conn->real_escape_string($error);
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $conn->query("
            UPDATE sync_outbox
            SET status = 'failed',
                locked_by = NULL,
                locked_until = NULL,
                last_error = '{$escapedError}',
                next_retry_at = NOW(6)
            WHERE id = {$id}
        ");
    }
}

function pushMoovaEventsToBranch(array $events, string $url): array
{
    $body = json_encode(['events' => array_map(function (array $event): array {
        return [
            'event_uuid' => $event['event_uuid'],
            'idempotency_key' => $event['idempotency_key'],
            'cursor' => (int) $event['cursor'],
            'payload' => json_decode($event['payload_json'], true),
        ];
    }, $events)], JSON_UNESCAPED_SLASHES);

    return postJson($url, $body, ['Content-Type: application/json']);
}

function markMoovaDeliveryFailure(mysqli $conn, array $events, string $error): void
{
    $escapedError = $conn->real_escape_string($error);
    foreach ($events as $event) {
        $id = (int) $event['id'];
        $conn->query("
            UPDATE cloud_moova_branch_events
            SET attempts = attempts + 1,
                last_error = '{$escapedError}'
            WHERE id = {$id}
        ");
    }
}

function fetchOutboxById(mysqli $conn, int $id): array
{
    return $conn->query("SELECT * FROM sync_outbox WHERE id = {$id}")->fetch_assoc();
}

function fetchMoovaById(mysqli $conn, int $id): array
{
    return $conn->query("SELECT * FROM cloud_moova_branch_events WHERE id = {$id}")->fetch_assoc();
}

function postJson(string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_TIMEOUT_MS => 1200,
    ]);

    $responseBody = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    $json = is_string($responseBody) ? json_decode($responseBody, true) : null;

    return [
        'ok' => $responseBody !== false && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $responseBody === false ? '' : $responseBody,
        'json' => is_array($json) ? $json : null,
        'error' => $responseBody === false ? $error : ($status >= 200 && $status < 300 ? '' : (string) $responseBody),
    ];
}

function startServer(string $name, callable $handler, ?int $preferredPort = null): array
{
    $port = $preferredPort ?? freePort();
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Could not fork mock server: ' . $name);
    }

    if ($pid === 0) {
        runServer($name, $port, $handler);
        exit(0);
    }

    waitForPort($port, $name);
    return ['name' => $name, 'pid' => $pid, 'port' => $port];
}

function stopServer(array $server): void
{
    if (empty($server['pid'])) {
        return;
    }

    @posix_kill((int) $server['pid'], SIGTERM);
    pcntl_waitpid((int) $server['pid'], $status, WNOHANG);
    usleep(120000);
    @posix_kill((int) $server['pid'], SIGKILL);
    pcntl_waitpid((int) $server['pid'], $status, WNOHANG);
}

function runServer(string $name, int $port, callable $handler): void
{
    $running = true;
    pcntl_signal(SIGTERM, function () use (&$running): void {
        $running = false;
    });

    $server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
    if (!$server) {
        fwrite(STDERR, "Mock server {$name} failed on {$port}: {$errstr}\n");
        exit(1);
    }
    stream_set_blocking($server, false);

    while ($running) {
        pcntl_signal_dispatch();
        $conn = @stream_socket_accept($server, 0, $peer);
        if (!$conn) {
            usleep(10000);
            continue;
        }
        handleHttpConnection($conn, $handler);
        fclose($conn);
    }

    fclose($server);
}

function handleHttpConnection($conn, callable $handler): void
{
    stream_set_timeout($conn, 2);
    $headerText = '';
    while (!str_contains($headerText, "\r\n\r\n") && !feof($conn)) {
        $headerText .= fread($conn, 1024);
    }

    [$rawHeaders, $remainingBody] = array_pad(explode("\r\n\r\n", $headerText, 2), 2, '');
    $lines = explode("\r\n", $rawHeaders);
    $requestLine = array_shift($lines) ?: '';
    $parts = explode(' ', $requestLine);
    $headers = [];
    foreach ($lines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$key, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($key))] = trim($value);
    }

    $contentLength = (int) ($headers['content-length'] ?? 0);
    $body = $remainingBody;
    while (strlen($body) < $contentLength && !feof($conn)) {
        $body .= fread($conn, $contentLength - strlen($body));
    }

    try {
        $response = $handler([
            'method' => $parts[0] ?? 'GET',
            'path' => $parts[1] ?? '/',
            'headers' => $headers,
            'body' => $body,
        ]);
    } catch (Throwable $e) {
        $response = jsonResponse(500, ['ok' => false, 'error' => $e->getMessage()]);
    }

    $status = $response['status'];
    $responseBody = $response['body'];
    $contentType = $response['content_type'];
    fwrite($conn, "HTTP/1.1 {$status} OK\r\nContent-Type: {$contentType}\r\nContent-Length: " . strlen($responseBody) . "\r\nConnection: close\r\n\r\n{$responseBody}");
}

function jsonResponse(int $status, array $payload): array
{
    return [
        'status' => $status,
        'content_type' => 'application/json',
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ];
}

function freePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$server) {
        throw new RuntimeException('Could not allocate free port: ' . $errstr);
    }
    $name = stream_socket_get_name($server, false);
    fclose($server);
    return (int) substr(strrchr($name, ':'), 1);
}

function waitForPort(int $port, string $name): void
{
    $deadline = microtime(true) + 3;
    do {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($socket) {
            fclose($socket);
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("Mock server {$name} did not open port {$port}");
}

function cloudPushUrl(array $cloud): string
{
    return 'http://127.0.0.1:' . $cloud['port'] . '/sync/push';
}

function branchMoovaUrl(array $branch): string
{
    return 'http://127.0.0.1:' . $branch['port'] . '/moova/events';
}

function uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function appendJsonLine(string $path, array $payload): void
{
    file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

function assertScenario(string $name, array $details, array $checks): array
{
    return [
        'name' => $name,
        'pass' => !in_array(false, $checks, true),
        'details' => $details,
    ];
}

function hasFailures(array $results): bool
{
    foreach ($results as $result) {
        if (empty($result['pass'])) {
            return true;
        }
    }
    return false;
}

function assertE2eRuntimeRequirements(): void
{
    $missing = [];
    foreach (['curl', 'mysqli', 'pcntl', 'posix'] as $extension) {
        if (!extension_loaded($extension)) {
            $missing[] = $extension;
        }
    }

    if ($missing) {
        fwrite(STDERR, 'Missing required PHP extension(s): ' . implode(', ', $missing) . PHP_EOL);
        exit(2);
    }
}
