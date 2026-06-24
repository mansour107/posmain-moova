<?php

class ShopSettingsSyncPayloadService
{
    private const EXCLUDE_COLUMNS = [
        'edit_password',
        'lic',
        'updateline',
    ];

    public function build(mysqli $conn, string $branchUuid, array $options = []): ?array
    {
        if (!$this->tableExists($conn, 'settings')) {
            return null;
        }

        $row = $conn->query('SELECT * FROM settings WHERE id = 1 LIMIT 1')?->fetch_assoc();
        if (!$row) {
            return null;
        }

        foreach (self::EXCLUDE_COLUMNS as $column) {
            unset($row[$column]);
        }

        return [
            'schema_version' => 1,
            'snapshot_type' => 'shop_settings',
            'branch_uuid' => $branchUuid,
            'source_system' => (string) ($options['source_system'] ?? 'pos'),
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'settings_id' => (int) ($row['id'] ?? 1),
            'settings' => $row,
        ];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result && $result->num_rows > 0;
    }
}
