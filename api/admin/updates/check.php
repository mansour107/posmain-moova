<?php

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    posmainUpdateJson(405, [
        'ok' => false,
        'error' => 'method_not_allowed',
        'message' => 'Use GET to check update availability.',
    ]);
}

$conn = posmainUpdateRequireAdmin();

try {
    $availability = posmainUpdateAvailability();
    $activeJob = (new PosmainUpdateJobStore())->activeJob();

    posmainUpdateJson(200, [
        'ok' => (bool) ($availability['ok'] ?? false),
        'installed_version' => $availability['installed_version'] ?? null,
        'published_version' => $availability['published_version'] ?? null,
        'update_available' => (bool) ($availability['update_available'] ?? false),
        'update_reason' => $availability['update_reason'] ?? null,
        'git_sync' => $availability['git_sync'] ?? null,
        'version_url' => $availability['version_url'] ?? null,
        'published' => $availability['published'] ?? null,
        'error' => $availability['error'] ?? null,
        'message' => $availability['message'] ?? null,
        'active_job' => $activeJob,
    ]);
} catch (RuntimeException $e) {
    posmainUpdateJson(500, [
        'ok' => false,
        'error' => 'update_check_error',
        'message' => $e->getMessage(),
        'update_available' => false,
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
