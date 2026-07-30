<?php

require_once __DIR__ . '/../../includes/api_entry_classification.php';

define('POSMAIN_BRANCH_WORKER_STATUS_LIBRARY', true);

require_once __DIR__ . '/../../tools/branch_worker_status.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    syncStatusJson(405, [
        'ok' => false,
        'healthy' => false,
        'error' => 'method_not_allowed',
        'message' => 'Use GET for sync status.',
    ]);
}

$statusConfig = function_exists('posmain_app_config') ? posmain_app_config() : [];
$configuredToken = trim((string) ($statusConfig['status_token'] ?? ''));
if ($configuredToken === '') {
    syncStatusJson(503, [
        'ok' => false,
        'healthy' => false,
        'error' => 'status_token_not_configured',
        'message' => 'Set POSMAIN_STATUS_TOKEN before exposing sync status over HTTP.',
    ]);
}

$providedToken = syncStatusRequestToken();
if ($providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
    syncStatusJson(403, [
        'ok' => false,
        'healthy' => false,
        'error' => 'forbidden',
        'message' => 'Invalid sync status token.',
    ]);
}

$limit = syncStatusBoundedInt($_GET['limit'] ?? 10, 1, 50);
$recentMinutes = syncStatusBoundedInt($_GET['recent_minutes'] ?? ($_GET['recent-minutes'] ?? 60), 0, 10080);
$failOnProblems = syncStatusBool($_GET['fail_on_problems'] ?? ($_GET['fail-on-problems'] ?? false));

try {
    $conn = posmain_db_connect();
    $report = branchWorkerStatusReport($conn, $limit, $recentMinutes);
    $conn->close();
} catch (Throwable $e) {
    $report = branchWorkerStatusUnavailable($e);
}

$report['api'] = 'sync_status';

if (isset($report['database']['user'])) {
    unset($report['database']['user']);
}

$statusCode = empty($report['ok']) || ($failOnProblems && empty($report['healthy'])) ? 503 : 200;
syncStatusJson($statusCode, $report);

function syncStatusRequestToken(): string
{
    $headerToken = (string) ($_SERVER['HTTP_X_POSMAIN_STATUS_TOKEN'] ?? '');
    if ($headerToken !== '') {
        return trim($headerToken);
    }

    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($authorization === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                $authorization = (string) $value;
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        return trim($matches[1]);
    }

    return '';
}

function syncStatusBoundedInt($value, int $min, int $max): int
{
    if (!is_scalar($value)) {
        return $min;
    }

    return max($min, min($max, (int) $value));
}

function syncStatusBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

function syncStatusJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
