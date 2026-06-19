<?php

require_once __DIR__ . '/../../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../../includes/auth_guard.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../../../classes/Updates/UpdateJobStore.php';

if (!function_exists('posmainUpdateJson')) {
    function posmainUpdateJson(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit;
    }
}

if (!function_exists('posmainUpdateRequireAdmin')) {
    function posmainUpdateRequireAdmin(): mysqli
    {
        try {
            $conn = posmain_db_connect();
        } catch (Throwable $e) {
            posmainUpdateJson(503, [
                'ok' => false,
                'error' => 'database_unavailable',
                'message' => 'Database connection is required before starting an update.',
            ]);
        }

        if (!auth_guard_is_logged_in()) {
            posmainUpdateJson(401, [
                'ok' => false,
                'error' => 'auth_required',
                'message' => 'Admin login is required.',
            ]);
        }

        $roleFlags = auth_guard_current_role_flags($conn);
        if (
            !auth_guard_is_admin_session($_SESSION, $roleFlags)
            && !auth_guard_session_has_permission('system.tools.run', $roleFlags, $_SESSION)
        ) {
            posmainUpdateJson(403, [
                'ok' => false,
                'error' => 'permission_denied',
                'message' => 'System tools permission is required.',
            ]);
        }

        return $conn;
    }
}

if (!function_exists('posmainUpdateRequestPayload')) {
    function posmainUpdateRequestPayload(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (strpos($contentType, 'application/json') !== false) {
            $raw = (string) file_get_contents('php://input');
            if (trim($raw) === '') {
                return [];
            }

            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                throw new InvalidArgumentException('INVALID_JSON_BODY');
            }

            return $payload;
        }

        return $_POST;
    }
}

if (!function_exists('posmainUpdateStatusUrl')) {
    function posmainUpdateStatusUrl(string $jobId): string
    {
        return '/api/admin/updates/status.php?id=' . rawurlencode($jobId);
    }
}

if (!function_exists('posmainInstalledVersion')) {
    function posmainInstalledVersion(?string $root = null): ?string
    {
        $path = rtrim($root ?: dirname(__DIR__, 3), '/\\') . '/version.txt';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $version = trim((string) file_get_contents($path));
        if ($version === '' || preg_match('/^[A-Za-z0-9._+-]{1,64}$/', $version) !== 1) {
            return null;
        }

        return $version;
    }
}

if (!function_exists('posmainUpdateVersionUrl')) {
    function posmainUpdateVersionUrl(?array $config = null): ?string
    {
        $config = $config ?: posmain_app_config();
        $url = trim((string) ($config['update_version_url'] ?? ''));

        return $url !== '' ? $url : null;
    }
}

if (!function_exists('posmainFetchPublishedVersion')) {
    function posmainFetchPublishedVersion(?string $url = null): ?array
    {
        $url = $url ?: posmainUpdateVersionUrl();
        if ($url === null) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'header' => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }

        $version = trim((string) ($payload['version'] ?? ''));
        if ($version === '' || preg_match('/^[A-Za-z0-9._+-]{1,64}$/', $version) !== 1) {
            return null;
        }

        $payload['version'] = $version;

        return $payload;
    }
}

if (!function_exists('posmainCompareVersions')) {
    function posmainCompareVersions(?string $installed, ?string $target): int
    {
        if ($installed === null || $target === null || $installed === '' || $target === '') {
            return 0;
        }

        return version_compare($target, $installed);
    }
}

if (!function_exists('posmainUpdateAvailability')) {
    function posmainUpdateAvailability(): array
    {
        $installed = posmainInstalledVersion();
        $published = posmainFetchPublishedVersion();
        $versionUrl = posmainUpdateVersionUrl();

        if ($installed === null) {
            return [
                'ok' => false,
                'error' => 'installed_version_unavailable',
                'message' => 'Local version.txt is missing or invalid.',
                'update_available' => false,
            ];
        }

        if ($published === null) {
            return [
                'ok' => false,
                'error' => 'published_version_unavailable',
                'message' => 'Published version.json could not be loaded.',
                'installed_version' => $installed,
                'version_url' => $versionUrl,
                'update_available' => false,
            ];
        }

        $targetVersion = (string) $published['version'];

        return [
            'ok' => true,
            'installed_version' => $installed,
            'published_version' => $targetVersion,
            'update_available' => posmainCompareVersions($installed, $targetVersion) > 0,
            'version_url' => $versionUrl,
            'published' => $published,
        ];
    }
}

if (!function_exists('posmainUpdatePhpBinary')) {
    function posmainUpdatePhpBinary(): string
    {
        $configured = trim((string) (getenv('POSMAIN_UPDATE_PHP_BIN') ?: ''));
        if ($configured !== '') {
            return $configured;
        }

        if (defined('PHP_BINARY') && PHP_BINARY !== '' && stripos((string) PHP_BINARY, 'fpm') === false) {
            return (string) PHP_BINARY;
        }

        return 'php';
    }
}

if (!function_exists('posmainUpdateWorkerDispatchCommand')) {
    function posmainUpdateWorkerDispatchCommand(string $jobId): ?string
    {
        $root = realpath(dirname(__DIR__, 3));
        if ($root === false) {
            return null;
        }

        $php = posmainUpdatePhpBinary();
        $script = $root . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'update_worker.php';
        if (!is_file($script)) {
            return null;
        }

        $custom = trim((string) (getenv('POSMAIN_UPDATE_WORKER_DISPATCH_CMD') ?: ''));
        if ($custom !== '') {
            return strtr($custom, [
                '{php}' => $php,
                '{script}' => $script,
                '{job_id}' => $jobId,
                '{root}' => $root,
            ]);
        }

        $logDir = $root . '/var/update_jobs';
        if (!is_dir($logDir) && !mkdir($logDir, 0750, true) && !is_dir($logDir)) {
            return null;
        }

        $logFile = $logDir . '/worker-' . $jobId . '.log';
        $runAs = trim((string) (getenv('POSMAIN_UPDATE_RUN_AS') ?: ''));
        $wrapper = trim((string) (getenv('POSMAIN_UPDATE_WORKER_WRAPPER') ?: '/usr/local/bin/posmain-update-worker'));

        if ($runAs !== '' && is_file($wrapper)) {
            $inner = escapeshellarg($wrapper) . ' ' . escapeshellarg($jobId);

            return 'nohup sudo -n -u ' . escapeshellarg($runAs) . ' ' . $inner
                . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        }

        $inner = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --job-id=' . escapeshellarg($jobId);

        return 'nohup ' . $inner . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
    }
}

if (!function_exists('posmainDispatchUpdateWorker')) {
    function posmainDispatchUpdateWorker(string $jobId): bool
    {
        $command = posmainUpdateWorkerDispatchCommand($jobId);
        if ($command === null) {
            return false;
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $handle = popen('start /B ' . $command, 'r');
            if (!is_resource($handle)) {
                return false;
            }
            pclose($handle);

            return true;
        }

        if (!function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return false;
        }

        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }
}
