<?php

class ModifierGroupSyncPayloadService
{
    public function build(mysqli $conn, int $groupId, string $branchUuid, array $options = []): ?array
    {
        if ($groupId <= 0 || !$this->tableExists($conn, 'modifier_groups')) {
            return null;
        }

        $group = $this->fetchRow($conn, 'modifier_groups', $groupId);
        if (!$group) {
            return null;
        }

        $optionsRows = [];
        if ($this->tableExists($conn, 'modifier_options')) {
            $stmt = $conn->prepare('SELECT * FROM modifier_options WHERE group_id = ? ORDER BY sort_order ASC, id ASC');
            $stmt->bind_param('i', $groupId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $optionsRows[] = $row;
            }
            $stmt->close();
        }

        $itemLinks = [];
        if ($this->tableExists($conn, 'item_modifier_groups')) {
            $stmt = $conn->prepare('SELECT * FROM item_modifier_groups WHERE group_id = ? ORDER BY sort_order ASC, item_id ASC');
            $stmt->bind_param('i', $groupId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $itemLinks[] = $row;
            }
            $stmt->close();
        }

        return [
            'schema_version' => 1,
            'snapshot_type' => 'modifier_group_bundle',
            'branch_uuid' => $branchUuid,
            'source_system' => (string) ($options['source_system'] ?? 'pos'),
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'group' => $group,
            'options' => $optionsRows,
            'item_links' => $itemLinks,
        ];
    }

    private function fetchRow(mysqli $conn, string $table, int $rowId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $rowId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result && $result->num_rows > 0;
    }
}
