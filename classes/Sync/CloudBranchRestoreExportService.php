<?php

require_once __DIR__ . '/RestoreEventPhase.php';

class CloudBranchRestoreExportService
{
    private const INBOX_STATUSES = ['processed', 'duplicate'];
    private const MAX_SCAN_MULTIPLIER = 8;

    public function exportPage(mysqli $conn, string $branchUuid, string $phase, int $afterId, int $limit, string $source = 'auto'): array
    {
        $phase = RestoreEventPhase::normalize($phase);
        $afterId = max(0, $afterId);
        $limit = max(1, min(100, $limit));
        $source = $this->normalizeSource($source);

        if ($source === 'auto') {
            $source = $this->hasInboxEvents($conn, $branchUuid) ? 'sync_inbox' : 'cloud_snapshot';
        }

        if ($source === 'sync_inbox') {
            return $this->exportFromSyncInbox($conn, $branchUuid, $phase, $afterId, $limit, $source);
        }

        return $this->exportFromCloudSnapshot($conn, $branchUuid, $phase, $afterId, $limit, $source);
    }

    public function hasInboxEvents(mysqli $conn, string $branchUuid): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM sync_inbox
            WHERE branch_uuid = ?
              AND direction = 'branch_to_cloud'
              AND status IN ('processed', 'duplicate')
            LIMIT 1
        ");
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        return $count > 0;
    }

    private function exportFromSyncInbox(
        mysqli $conn,
        string $branchUuid,
        string $phase,
        int $afterId,
        int $limit,
        string $source
    ): array {
        $events = [];
        $cursor = $afterId;
        $nextAfterId = $afterId;
        $scanLimit = max($limit, min(800, $limit * self::MAX_SCAN_MULTIPLIER));

        while (count($events) < $limit) {
            $stmt = $conn->prepare("
                SELECT id, payload_json
                FROM sync_inbox
                WHERE branch_uuid = ?
                  AND direction = 'branch_to_cloud'
                  AND status IN ('processed', 'duplicate')
                  AND id > ?
                ORDER BY id ASC
                LIMIT ?
            ");
            $stmt->bind_param('sii', $branchUuid, $cursor, $scanLimit);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
            $stmt->close();

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                $event = json_decode((string) ($row['payload_json'] ?? ''), true);
                if (!is_array($event) || RestoreEventPhase::classify($event) !== $phase) {
                    continue;
                }

                $events[] = [
                    'restore_id' => $cursor,
                    'event' => $event,
                ];
                $nextAfterId = $cursor;

                if (count($events) >= $limit) {
                    break;
                }
            }

            if (count($rows) < $scanLimit) {
                break;
            }
        }

        $hasMore = $this->syncInboxHasMore($conn, $branchUuid, $phase, $nextAfterId);

        return [
            'source' => $source,
            'phase' => $phase,
            'after_id' => $afterId,
            'next_after_id' => $nextAfterId,
            'has_more' => $hasMore,
            'count' => count($events),
            'events' => $events,
        ];
    }

    private function exportFromCloudSnapshot(
        mysqli $conn,
        string $branchUuid,
        string $phase,
        int $afterId,
        int $limit,
        string $source
    ): array {
        if ($phase === RestoreEventPhase::MENU) {
            return $this->exportCloudMenuItems($conn, $branchUuid, $afterId, $limit, $source, $phase);
        }

        if ($phase === RestoreEventPhase::TABLES) {
            return $this->exportCloudTables($conn, $branchUuid, $afterId, $limit, $source, $phase);
        }

        return $this->exportCloudOrders($conn, $branchUuid, $afterId, $limit, $source, $phase);
    }

    private function exportCloudMenuItems(
        mysqli $conn,
        string $branchUuid,
        int $afterId,
        int $limit,
        string $source,
        string $phase
    ): array {
        $stmt = $conn->prepare("
            SELECT id, payload_json, item_uuid, local_item_id, item_name, barcode, category_id, price, cost, isdeleted
            FROM cloud_menu_items
            WHERE branch_uuid = ?
              AND id > ?
            ORDER BY id ASC
            LIMIT ?
        ");
        $stmt->bind_param('sii', $branchUuid, $afterId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = [];
        $nextAfterId = $afterId;
        while ($row = $result->fetch_assoc()) {
            $nextAfterId = (int) $row['id'];
            $events[] = [
                'restore_id' => $nextAfterId,
                'event' => $this->menuEventFromSnapshotRow($row),
            ];
        }
        $result->free();
        $stmt->close();

        return [
            'source' => $source,
            'phase' => $phase,
            'after_id' => $afterId,
            'next_after_id' => $nextAfterId,
            'has_more' => count($events) >= $limit,
            'count' => count($events),
            'events' => $events,
        ];
    }

    private function exportCloudTables(
        mysqli $conn,
        string $branchUuid,
        int $afterId,
        int $limit,
        string $source,
        string $phase
    ): array {
        if (!$this->tableExists($conn, 'cloud_tables')) {
            return $this->emptyPage($source, $phase, $afterId);
        }

        $stmt = $conn->prepare("
            SELECT id, payload_json, table_uuid, local_table_id, table_name, isdeleted
            FROM cloud_tables
            WHERE branch_uuid = ?
              AND id > ?
            ORDER BY id ASC
            LIMIT ?
        ");
        $stmt->bind_param('sii', $branchUuid, $afterId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = [];
        $nextAfterId = $afterId;
        while ($row = $result->fetch_assoc()) {
            $nextAfterId = (int) $row['id'];
            $events[] = [
                'restore_id' => $nextAfterId,
                'event' => $this->tableEventFromSnapshotRow($row),
            ];
        }
        $result->free();
        $stmt->close();

        return [
            'source' => $source,
            'phase' => $phase,
            'after_id' => $afterId,
            'next_after_id' => $nextAfterId,
            'has_more' => count($events) >= $limit,
            'count' => count($events),
            'events' => $events,
        ];
    }

    private function exportCloudOrders(
        mysqli $conn,
        string $branchUuid,
        int $afterId,
        int $limit,
        string $source,
        string $phase
    ): array {
        if (!$this->tableExists($conn, 'cloud_orders')) {
            return $this->emptyPage($source, $phase, $afterId);
        }

        $stmt = $conn->prepare("
            SELECT id, payload_json, order_uuid, local_order_id
            FROM cloud_orders
            WHERE branch_uuid = ?
              AND id > ?
            ORDER BY id ASC
            LIMIT ?
        ");
        $stmt->bind_param('sii', $branchUuid, $afterId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = [];
        $nextAfterId = $afterId;
        while ($row = $result->fetch_assoc()) {
            $nextAfterId = (int) $row['id'];
            $events[] = [
                'restore_id' => $nextAfterId,
                'event' => $this->orderEventFromSnapshotRow($conn, $branchUuid, $row),
            ];
        }
        $result->free();
        $stmt->close();

        return [
            'source' => $source,
            'phase' => $phase,
            'after_id' => $afterId,
            'next_after_id' => $nextAfterId,
            'has_more' => count($events) >= $limit,
            'count' => count($events),
            'events' => $events,
        ];
    }

    private function menuEventFromSnapshotRow(array $row): array
    {
        $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (is_array($decoded) && RestoreEventPhase::classify($decoded) === RestoreEventPhase::MENU) {
            return $decoded;
        }

        $item = is_array($decoded) ? $decoded : [];
        if (!isset($item['item']) && !isset($item['menu_item'])) {
            $item = [
                'item_uuid' => (string) ($row['item_uuid'] ?? ''),
                'local_item_id' => $row['local_item_id'] !== null ? (int) $row['local_item_id'] : null,
                'item_name' => (string) ($row['item_name'] ?? ''),
                'barcode' => (string) ($row['barcode'] ?? ''),
                'category_id' => $row['category_id'] !== null ? (int) $row['category_id'] : null,
                'price' => $row['price'] ?? 0,
                'cost' => $row['cost'] ?? 0,
                'isdeleted' => (int) ($row['isdeleted'] ?? 0),
            ];
        }

        return [
            'event_type' => 'menu.item_saved',
            'aggregate_type' => 'menu_item',
            'entity_type' => 'menu_item',
            'payload' => $item,
        ];
    }

    private function tableEventFromSnapshotRow(array $row): array
    {
        $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (is_array($decoded) && RestoreEventPhase::classify($decoded) === RestoreEventPhase::TABLES) {
            return $decoded;
        }

        $table = is_array($decoded) ? $decoded : [];
        if (!isset($table['table'])) {
            $table = [
                'table_uuid' => (string) ($row['table_uuid'] ?? ''),
                'local_table_id' => $row['local_table_id'] !== null ? (int) $row['local_table_id'] : null,
                'table_name' => (string) ($row['table_name'] ?? ''),
                'isdeleted' => (int) ($row['isdeleted'] ?? 0),
            ];
        }

        return [
            'event_type' => 'table.saved',
            'aggregate_type' => 'table',
            'entity_type' => 'table',
            'payload' => $table,
        ];
    }

    private function orderEventFromSnapshotRow(mysqli $conn, string $branchUuid, array $row): array
    {
        $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (is_array($decoded) && RestoreEventPhase::classify($decoded) === RestoreEventPhase::ORDERS) {
            return $decoded;
        }

        $payload = is_array($decoded) ? $decoded : [];
        if (!isset($payload['order'])) {
            $payload['order'] = [
                'order_uuid' => (string) ($row['order_uuid'] ?? ''),
                'local_order_id' => $row['local_order_id'] !== null ? (int) $row['local_order_id'] : null,
            ];
        }

        if ($this->tableExists($conn, 'cloud_order_lines') && !isset($payload['lines'])) {
            $payload['lines'] = $this->orderLinesForUuid($conn, $branchUuid, (string) ($row['order_uuid'] ?? ''));
        }

        return [
            'event_type' => 'order.saved',
            'aggregate_type' => 'order',
            'entity_type' => 'order',
            'payload' => $payload,
        ];
    }

    private function orderLinesForUuid(mysqli $conn, string $branchUuid, string $orderUuid): array
    {
        if ($orderUuid === '') {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT payload_json, line_uuid, local_line_id, item_id, item_uuid, item_name, qty_in, qty_out, price, isdeleted
            FROM cloud_order_lines
            WHERE branch_uuid = ?
              AND order_uuid = ?
            ORDER BY id ASC
        ");
        $stmt->bind_param('ss', $branchUuid, $orderUuid);
        $stmt->execute();
        $result = $stmt->get_result();
        $lines = [];
        while ($row = $result->fetch_assoc()) {
            $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (is_array($decoded)) {
                $lines[] = $decoded;
                continue;
            }

            $lines[] = [
                'line_uuid' => (string) ($row['line_uuid'] ?? ''),
                'local_line_id' => $row['local_line_id'] !== null ? (int) $row['local_line_id'] : null,
                'item_id' => $row['item_id'] !== null ? (int) $row['item_id'] : null,
                'item_uuid' => (string) ($row['item_uuid'] ?? ''),
                'item_name' => (string) ($row['item_name'] ?? ''),
                'qty_in' => $row['qty_in'] ?? 0,
                'qty_out' => $row['qty_out'] ?? 0,
                'price' => $row['price'] ?? 0,
                'isdeleted' => (int) ($row['isdeleted'] ?? 0),
            ];
        }
        $result->free();
        $stmt->close();

        return $lines;
    }

    private function syncInboxHasMore(mysqli $conn, string $branchUuid, string $phase, int $afterId): bool
    {
        $cursor = $afterId;
        $scanLimit = 200;

        while (true) {
            $stmt = $conn->prepare("
                SELECT id, payload_json
                FROM sync_inbox
                WHERE branch_uuid = ?
                  AND direction = 'branch_to_cloud'
                  AND status IN ('processed', 'duplicate')
                  AND id > ?
                ORDER BY id ASC
                LIMIT ?
            ");
            $stmt->bind_param('sii', $branchUuid, $cursor, $scanLimit);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
            $stmt->close();

            if ($rows === []) {
                return false;
            }

            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                $event = json_decode((string) ($row['payload_json'] ?? ''), true);
                if (is_array($event) && RestoreEventPhase::classify($event) === $phase) {
                    return true;
                }
            }

            if (count($rows) < $scanLimit) {
                return false;
            }
        }
    }

    private function emptyPage(string $source, string $phase, int $afterId): array
    {
        return [
            'source' => $source,
            'phase' => $phase,
            'after_id' => $afterId,
            'next_after_id' => $afterId,
            'has_more' => false,
            'count' => 0,
            'events' => [],
        ];
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        if (!in_array($source, ['auto', 'sync_inbox', 'cloud_snapshot'], true)) {
            throw new InvalidArgumentException('source must be one of: auto, sync_inbox, cloud_snapshot.');
        }

        return $source;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }

        return $exists;
    }
}
