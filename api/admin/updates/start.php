<?php

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    posmainUpdateJson(405, [
        'ok' => false,
        'error' => 'method_not_allowed',
        'message' => 'Use POST to start an update job.',
    ]);
}

$conn = posmainUpdateRequireAdmin();

if (!verify_csrf_from_post_or_header('system_update')) {
    posmainUpdateJson(403, [
        'ok' => false,
        'error' => 'csrf_invalid',
        'message' => 'CSRF token is invalid.',
    ]);
}

try {
    $payload = posmainUpdateRequestPayload();
    $store = new PosmainUpdateJobStore();
    $job = $store->create([
        'action' => $payload['action'] ?? 'apply',
        'target_version' => $payload['target_version'] ?? null,
        'requested_by_user_id' => current_user_id(),
    ]);
    $job = $store->markDispatching((string) $job['id']);
    $workerStarted = posmainDispatchUpdateWorker((string) $job['id']);
    if (!$workerStarted) {
        $job = $store->markDispatchFailed((string) $job['id'], 'UPDATE_WORKER_DISPATCH_FAILED');
        posmainUpdateJson(503, [
            'ok' => false,
            'error' => 'update_worker_dispatch_failed',
            'job_id' => $job['id'],
            'status' => $job['status'],
            'status_url' => posmainUpdateStatusUrl((string) $job['id']),
            'worker_started' => false,
            'message' => 'Update job was not started. No update steps were applied.',
        ]);
    }

    posmainUpdateJson(202, [
        'ok' => true,
        'job_id' => $job['id'],
        'status' => $job['status'],
        'status_url' => posmainUpdateStatusUrl((string) $job['id']),
        'worker_started' => true,
        'message' => 'Update job created and worker dispatched.',
    ]);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    if (strpos($message, 'UPDATE_ALREADY_RUNNING:') === 0) {
        $activeJobId = substr($message, strlen('UPDATE_ALREADY_RUNNING:'));
        posmainUpdateJson(409, [
            'ok' => false,
            'error' => 'update_already_running',
            'message' => 'Another update job is already active.',
            'job_id' => $activeJobId,
            'status_url' => posmainUpdateStatusUrl($activeJobId),
        ]);
    }

    posmainUpdateJson(500, [
        'ok' => false,
        'error' => 'update_job_error',
        'message' => $message,
    ]);
} catch (InvalidArgumentException $e) {
    posmainUpdateJson(422, [
        'ok' => false,
        'error' => 'invalid_update_request',
        'message' => $e->getMessage(),
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
