<?php

require_once __DIR__ . '/SyncRuntimeCrypto.php';

class SyncRuntimeDbConfigFile
{
    public static function defaultPath(): string
    {
        $configured = getenv('POSMAIN_RUNTIME_CONFIG_FILE');
        if ($configured !== false && trim((string) $configured) !== '') {
            return (string) $configured;
        }

        return dirname(__DIR__, 2) . '/var/posmain-runtime-config.json';
    }

    public static function disabled(): bool
    {
        foreach (['POSMAIN_DISABLE_UI_RUNTIME_CONFIG', 'POSMAIN_RUNTIME_CONFIG_DISABLED'] as $name) {
            $value = getenv($name);
            if ($value !== false && in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return false;
    }

    public function load(?string $path = null): array
    {
        if (self::disabled()) {
            return [];
        }

        $path = $path ?: self::defaultPath();
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['database']) || !is_array($decoded['database'])) {
            throw new RuntimeException('Invalid POSMAIN runtime config file.');
        }

        $db = $decoded['database'];
        if (isset($db['pass_encrypted']) && (string) $db['pass_encrypted'] !== '') {
            $db['pass'] = (new SyncRuntimeCrypto())->decrypt((string) $db['pass_encrypted']);
        }
        unset($db['pass_encrypted']);

        return [
            'database' => $this->normalizeDatabase($db, false),
            'updated_at_utc' => (string) ($decoded['updated_at_utc'] ?? ''),
        ];
    }

    public function save(array $database, ?string $path = null): void
    {
        $database = $this->normalizeDatabase($database, true);
        $path = $path ?: self::defaultPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create runtime config directory.');
        }

        $crypto = new SyncRuntimeCrypto();
        $payload = [
            'version' => 1,
            'updated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'database' => [
                'host' => $database['host'],
                'port' => $database['port'],
                'name' => $database['name'],
                'user' => $database['user'],
                'pass_encrypted' => $crypto->encrypt((string) $database['pass']),
                'charset' => $database['charset'],
            ],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode runtime config file.');
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write runtime config file.');
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to replace runtime config file.');
        }
        @chmod($path, 0600);
    }

    public function testDatabase(array $database): array
    {
        $database = $this->normalizeDatabase($database, true);
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = @new mysqli(
            (string) $database['host'],
            (string) $database['user'],
            (string) $database['pass'],
            (string) $database['name'],
            (int) $database['port']
        );
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        if ($conn->connect_error) {
            return [
                'ok' => false,
                'message' => $conn->connect_error,
            ];
        }

        $conn->set_charset((string) $database['charset']);
        $serverInfo = $conn->server_info;
        $conn->close();

        return [
            'ok' => true,
            'message' => 'تم الاتصال بقاعدة البيانات بنجاح.',
            'server' => $serverInfo,
        ];
    }

    public function exportEnv(array $database): string
    {
        $database = $this->normalizeDatabase($database, true);
        $lines = [
            'POSMAIN_DB_HOST=' . $database['host'],
            'POSMAIN_DB_PORT=' . $database['port'],
            'POSMAIN_DB_NAME=' . $database['name'],
            'POSMAIN_DB_USER=' . $database['user'],
            'POSMAIN_DB_PASS=' . $database['pass'],
        ];

        return implode("\n", $lines);
    }

    private function normalizeDatabase(array $database, bool $requirePassword): array
    {
        $normalized = [
            'host' => trim((string) ($database['host'] ?? '')),
            'port' => (int) ($database['port'] ?? 3306),
            'name' => trim((string) ($database['name'] ?? '')),
            'user' => trim((string) ($database['user'] ?? '')),
            'pass' => array_key_exists('pass', $database) ? (string) $database['pass'] : '',
            'charset' => trim((string) ($database['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
        ];

        foreach (['host', 'name', 'user'] as $key) {
            if ($normalized[$key] === '') {
                throw new InvalidArgumentException('Database ' . $key . ' is required.');
            }
        }
        if ($normalized['port'] < 1 || $normalized['port'] > 65535) {
            throw new InvalidArgumentException('Database port is invalid.');
        }
        return $normalized;
    }
}
