<?php

require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/SchemaManager.php';
require_once __DIR__ . '/SyncRuntimeCrypto.php';

class SyncRuntimeSettings
{
    private const SECRET_KEYS = [
        'POSMAIN_BRANCH_SYNC_SECRET' => true,
    ];

    private const BOOL_KEYS = [
        'POSMAIN_SYNC_OUTBOX_ENABLED',
        'POSMAIN_BRANCH_SYNC_ENABLED',
        'POSMAIN_SYNC_WORKER_ENABLED',
        'POSMAIN_MENU_SYNC_ENABLED',
        'POSMAIN_CLOUD_APPLY_ENABLED',
        'POSMAIN_CLOUD_PULL_ENABLED',
        'POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED',
        'POSMAIN_MOOVA_POLLER_ENABLED',
        'POSMAIN_MOOVA_APPLY_ENABLED',
    ];

    public function loadForUi(mysqli $conn, bool $includeSecretValues = false): array
    {
        $rows = $this->fetchRows($conn, false);
        $settings = [];
        $crypto = $includeSecretValues ? new SyncRuntimeCrypto() : null;
        foreach ($rows as $key => $row) {
            if (!empty($row['is_secret'])) {
                $configured = trim((string) ($row['setting_value'] ?? '')) !== '';
                $value = '';
                if ($includeSecretValues && $configured) {
                    try {
                        $value = $crypto ? $crypto->decrypt((string) ($row['setting_value'] ?? '')) : '';
                    } catch (Throwable $ignored) {
                        $value = '';
                    }
                }
                $settings[$key] = [
                    'configured' => $configured,
                    'value' => $value,
                    'is_secret' => true,
                ];
                continue;
            }

            $settings[$key] = [
                'configured' => trim((string) ($row['setting_value'] ?? '')) !== '',
                'value' => (string) ($row['setting_value'] ?? ''),
                'is_secret' => false,
            ];
        }

        return $settings;
    }

    public function save(mysqli $conn, array $input): array
    {
        (new SyncSchemaManager())->apply($conn);

        $role = $this->normalizeRole($input['role'] ?? 'branch');
        $values = [
            'POSMAIN_ROLE' => $role,
        ];

        foreach (self::BOOL_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = $this->boolString($input[$key]);
            }
        }

        if ($role === 'branch') {
            $branchUuid = strtolower(trim((string) ($input['POSMAIN_BRANCH_UUID'] ?? '')));
            if (!SyncBranchIdentity::isUuid($branchUuid)) {
                throw new InvalidArgumentException('Branch UUID must be a valid UUID.');
            }

            $cloudBaseUrl = rtrim(trim((string) ($input['POSMAIN_CLOUD_BASE_URL'] ?? '')), '/');
            if ($cloudBaseUrl === '' || !preg_match('#^https?://#i', $cloudBaseUrl)) {
                throw new InvalidArgumentException('Cloud base URL must start with http:// or https://.');
            }

            $secret = (string) ($input['POSMAIN_BRANCH_SYNC_SECRET'] ?? '');
            $hasExistingSecret = $this->secretConfigured($conn, 'POSMAIN_BRANCH_SYNC_SECRET');
            if ($secret === '' && !$hasExistingSecret) {
                throw new InvalidArgumentException('Branch sync secret is required.');
            }

            $values['POSMAIN_BRANCH_UUID'] = $branchUuid;
            $values['POSMAIN_CLOUD_BASE_URL'] = $cloudBaseUrl;
            if ($secret !== '') {
                $values['POSMAIN_BRANCH_SYNC_SECRET'] = $secret;
            }

            (new SyncBranchIdentity())->ensure($conn, [
                'branch' => [
                    'uuid' => $branchUuid,
                    'cloud_base_url' => $cloudBaseUrl,
                ],
            ]);
        }

        if ($role === 'cloud') {
            $this->deleteKeys($conn, [
                'POSMAIN_BRANCH_UUID',
                'POSMAIN_CLOUD_BASE_URL',
                'POSMAIN_BRANCH_SYNC_SECRET',
            ]);
        }

        $this->upsert($conn, $values);

        return [
            'role' => $role,
            'saved_keys' => array_keys($values),
        ];
    }

    public function savePartial(mysqli $conn, array $input, array $allowedKeys): array
    {
        (new SyncSchemaManager())->apply($conn);

        $allowed = array_flip($allowedKeys);
        $values = [];
        foreach (self::BOOL_KEYS as $key) {
            if (!isset($allowed[$key]) || !array_key_exists($key, $input)) {
                continue;
            }

            $values[$key] = $this->boolString($input[$key]);
        }

        if ($values) {
            $this->upsert($conn, $values);
        }

        return [
            'saved_keys' => array_keys($values),
        ];
    }

    public function fetchConfigOverrides(mysqli $conn): array
    {
        $rows = $this->fetchRows($conn, false);
        if (!$rows) {
            return [];
        }

        $crypto = new SyncRuntimeCrypto();
        $overrides = [];
        foreach ($rows as $key => $row) {
            $value = (string) ($row['setting_value'] ?? '');
            if ($value === '') {
                continue;
            }

            if (!empty($row['is_secret'])) {
                try {
                    $value = $crypto->decrypt($value);
                } catch (Throwable $ignored) {
                    continue;
                }
            }

            $this->applyOverride($overrides, $key, $value);
        }

        return $overrides;
    }

    public function secretConfigured(mysqli $conn, string $key): bool
    {
        $rows = $this->fetchRows($conn, false);
        return isset($rows[$key]) && trim((string) ($rows[$key]['setting_value'] ?? '')) !== '';
    }

    private function fetchRows(mysqli $conn, bool $requireTable): array
    {
        if (!$this->tableExists($conn)) {
            if ($requireTable) {
                throw new RuntimeException('sync_runtime_settings table is missing.');
            }
            return [];
        }

        $result = $conn->query("
            SELECT setting_key, setting_value, is_secret
            FROM sync_runtime_settings
        ");
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[(string) $row['setting_key']] = $row;
        }

        return $rows;
    }

    private function upsert(mysqli $conn, array $values): void
    {
        $crypto = new SyncRuntimeCrypto();
        $stmt = $conn->prepare("
            INSERT INTO sync_runtime_settings (setting_key, setting_value, is_secret)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                    is_secret = VALUES(is_secret),
                                    updated_at = CURRENT_TIMESTAMP(6)
        ");

        foreach ($values as $key => $value) {
            $isSecret = isset(self::SECRET_KEYS[$key]) ? 1 : 0;
            $stored = $isSecret ? $crypto->encrypt((string) $value) : (string) $value;
            $stmt->bind_param('ssi', $key, $stored, $isSecret);
            $stmt->execute();
        }
        $stmt->close();
    }

    private function deleteKeys(mysqli $conn, array $keys): void
    {
        $stmt = $conn->prepare("DELETE FROM sync_runtime_settings WHERE setting_key = ?");
        foreach ($keys as $key) {
            $stmt->bind_param('s', $key);
            $stmt->execute();
        }
        $stmt->close();
    }

    private function applyOverride(array &$overrides, string $key, string $value): void
    {
        switch ($key) {
            case 'POSMAIN_ROLE':
                $overrides['role'] = $this->normalizeRole($value);
                return;
            case 'POSMAIN_BRANCH_UUID':
                $overrides['branch']['uuid'] = strtolower(trim($value));
                return;
            case 'POSMAIN_CLOUD_BASE_URL':
                $overrides['branch']['cloud_base_url'] = rtrim(trim($value), '/');
                return;
            case 'POSMAIN_BRANCH_SYNC_SECRET':
                $overrides['sync']['branch_secret'] = $value;
                return;
            case 'POSMAIN_SYNC_OUTBOX_ENABLED':
                $overrides['sync']['outbox_enabled'] = $this->boolValue($value);
                $overrides['features']['sync_outbox'] = $this->boolValue($value);
                return;
            case 'POSMAIN_BRANCH_SYNC_ENABLED':
                $overrides['sync']['branch_sync_enabled'] = $this->boolValue($value);
                $overrides['features']['cloud_sync'] = $this->boolValue($value);
                return;
            case 'POSMAIN_SYNC_WORKER_ENABLED':
                $overrides['sync']['worker_enabled'] = $this->boolValue($value);
                return;
            case 'POSMAIN_MENU_SYNC_ENABLED':
                $overrides['sync']['menu_sync_enabled'] = $this->boolValue($value);
                return;
            case 'POSMAIN_CLOUD_APPLY_ENABLED':
                $overrides['sync']['cloud_apply_enabled'] = $this->boolValue($value);
                return;
            case 'POSMAIN_CLOUD_PULL_ENABLED':
                $overrides['sync']['cloud_pull_enabled'] = $this->boolValue($value);
                return;
            case 'POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED':
                $overrides['sync']['cloud_to_branch_publish_enabled'] = $this->boolValue($value);
                return;
            case 'POSMAIN_MOOVA_POLLER_ENABLED':
                $overrides['sync']['moova_poller_enabled'] = $this->boolValue($value);
                return;
            case 'POSMAIN_MOOVA_APPLY_ENABLED':
                $overrides['sync']['moova_apply_enabled'] = $this->boolValue($value);
                return;
        }
    }

    private function tableExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'sync_runtime_settings'");
        return $result && $result->num_rows > 0;
    }

    private function normalizeRole($value): string
    {
        $role = strtolower(trim((string) $value));
        if (!in_array($role, ['branch', 'cloud'], true)) {
            throw new InvalidArgumentException('Sync role must be branch or cloud.');
        }

        return $role;
    }

    private function boolString($value): string
    {
        return $this->boolValue($value) ? '1' : '0';
    }

    private function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
