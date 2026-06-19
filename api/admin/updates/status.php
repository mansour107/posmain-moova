<?php

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    posmainUpdateJson(405, [
        'ok' => false,
        'error' => 'method_not_allowed',
        'message' => 'Use GET to read update job status.',
    ]);
}

$conn = posmainUpdateRequireAdmin();

try {
    $jobId = (string) ($_GET['id'] ?? '');
    $store = new PosmainUpdateJobStore();
    $job = $store->find($jobId);

    if ($job === null) {
        posmainUpdateJson(404, [
            'ok' => false,
            'error' => 'update_job_not_found',
            'message' => 'Update job was not found.',
        ]);
    }

    posmainUpdateJson(200, [
        'ok' => true,
        'job' => $job,
    ]);
} catch (InvalidArgumentException $e) {
    posmainUpdateJson(422, [
        'ok' => false,
        'error' => 'invalid_update_request',
        'message' => $e->getMessage(),
    ]);
} catch (RuntimeException $e) {
    posmainUpdateJson(500, [
        'ok' => false,
        'error' => 'update_job_error',
        'message' => $e->getMessage(),
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
