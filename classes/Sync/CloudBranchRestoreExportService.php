<?php

require_once __DIR__ . '/RestoreEventPhase.php';
require_once __DIR__ . '/OperationalSyncDomains.php';
require_once __DIR__ . '/ShopSettingsSyncPayloadService.php';
require_once __DIR__ . '/ModifierGroupSyncPayloadService.php';
require_once __DIR__ . '/ShiftCloseSyncPayloadService.php';
require_once __DIR__ . '/PosOrderSnapshotBuilder.php';

class CloudBranchRestoreExportService
{
    private const INBOX_STATUSES = ['processed', 'duplicate'];
    private const MAX_SCAN_MULTIPLIER = 8;
    private const OPERATIONAL_CURSOR_SCALE = 1000000000;

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

        if ($phase === RestoreEventPhase::ORDERS) {
            return $this->exportCloudOrders($conn, $branchUuid, $afterId, $limit, $source, $phase);
        }

        if ($phase === RestoreEventPhase::OPERATIONAL) {
            return $this->exportOperationalFromLegacy($conn, $branchUuid, $afterId, $limit, $source, $phase);
        }

        return $this->emptyPage($source, $phase, $afterId);
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

    private function exportOperationalFromLegacy(
        mysqli $conn,
        string $branchUuid,
        int $afterId,
        int $limit,
        string $source,
        string $phase
    ): array {
        $segments = $this->operationalExportSegments($conn);
        if ($segments === []) {
            return $this->emptyPage($source, $phase, $afterId);
        }

        $segmentIndex = intdiv($afterId, self::OPERATIONAL_CURSOR_SCALE);
        $rowAfterId = $afterId % self::OPERATIONAL_CURSOR_SCALE;
        $events = [];
        $nextAfterId = $afterId;
        $hasMore = false;

        while (count($events) < $limit && $segmentIndex < count($segments)) {
            $page = $this->exportOperationalSegmentPage(
                $conn,
                $branchUuid,
                $segments[$segmentIndex],
                $rowAfterId,
                $limit - count($events)
            );

            foreach ($page['events'] as $event) {
                $events[] = [
                    'restore_id' => $nextAfterId,
                    'event' => $event,
                ];
                if (count($events) >= $limit) {
                    break;
                }
            }

            $rowAfterId = (int) $page['next_after_id'];
            $nextAfterId = ($segmentIndex * self::OPERATIONAL_CURSOR_SCALE) + $rowAfterId;

            if (!empty($page['has_more_in_segment'])) {
                $hasMore = true;
                break;
            }

            $segmentIndex++;
            $rowAfterId = 0;
            if ($segmentIndex < count($segments)) {
                $hasMore = true;
                $nextAfterId = $segmentIndex * self::OPERATIONAL_CURSOR_SCALE;
            }

            if (count($events) >= $limit) {
                $hasMore = true;
                break;
            }
        }

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

    private function operationalExportSegments(mysqli $conn): array
    {
        $segments = [];
        foreach (OperationalSyncDomains::bulkRowDomains() as $domain => $definition) {
            $table = (string) ($definition['table'] ?? '');
            if ($table !== '' && $this->tableExists($conn, $table)) {
                $segments[] = [
                    'type' => 'row_domain',
                    'domain' => $domain,
                    'definition' => $definition,
                ];
            }
        }

        if ($this->tableExists($conn, 'recipe_headers')) {
            $segments[] = ['type' => 'recipes'];
        }
        if ($this->tableExists($conn, 'settings')) {
            $segments[] = ['type' => 'shop_settings'];
        }
        if ($this->tableExists($conn, 'modifier_groups')) {
            $segments[] = ['type' => 'modifier_groups'];
        }
        if ($this->tableExists($conn, 'moova_pos_shop_links')) {
            $segments[] = ['type' => 'moova_shop_links'];
        }
        if ($this->tableExists($conn, 'drawer_session_close_summaries')) {
            $segments[] = ['type' => 'shift_closes'];
        }

        return $segments;
    }

    private function exportOperationalSegmentPage(
        mysqli $conn,
        string $branchUuid,
        array $segment,
        int $rowAfterId,
        int $limit
    ): array {
        $type = (string) ($segment['type'] ?? '');
        if ($type === 'row_domain') {
            return $this->exportRowDomainSegment(
                $conn,
                $branchUuid,
                (string) ($segment['domain'] ?? ''),
                (array) ($segment['definition'] ?? []),
                $rowAfterId,
                $limit
            );
        }

        if ($type === 'recipes') {
            return $this->exportRecipeSegment($conn, $branchUuid, $rowAfterId, $limit);
        }

        if ($type === 'shop_settings') {
            return $this->exportShopSettingsSegment($conn, $branchUuid, $rowAfterId);
        }

        if ($type === 'modifier_groups') {
            return $this->exportModifierGroupSegment($conn, $branchUuid, $rowAfterId, $limit);
        }

        if ($type === 'moova_shop_links') {
            return $this->exportMoovaShopLinkSegment($conn, $branchUuid, $rowAfterId, $limit);
        }

        if ($type === 'shift_closes') {
            return $this->exportShiftCloseSegment($conn, $branchUuid, $rowAfterId, $limit);
        }

        return [
            'events' => [],
            'next_after_id' => $rowAfterId,
            'has_more_in_segment' => false,
        ];
    }

    private function exportRowDomainSegment(
        mysqli $conn,
        string $branchUuid,
        string $domain,
        array $definition,
        int $rowAfterId,
        int $limit
    ): array {
        $table = (string) ($definition['table'] ?? '');
        if ($table === '' || !$this->tableExists($conn, $table)) {
            return [
                'events' => [],
                'next_after_id' => $rowAfterId,
                'has_more_in_segment' => false,
            ];
        }

        $stmt = $conn->prepare("SELECT * FROM `{$table}` WHERE id > ? ORDER BY id ASC LIMIT ?");
        $stmt->bind_param('ii', $rowAfterId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = [];
        $nextRowAfterId = $rowAfterId;
        while ($row = $result->fetch_assoc()) {
            $nextRowAfterId = (int) $row['id'];
            $event = $this->operationalRowEvent($branchUuid, $domain, $definition, $row);
            if ($event) {
                $events[] = $event;
            }
        }
        $result->free();
        $stmt->close();

        $hasMore = count($events) >= $limit;
        if (!$hasMore && $events !== []) {
            $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM `{$table}` WHERE id > ?");
            $countStmt->bind_param('i', $nextRowAfterId);
            $countStmt->execute();
            $hasMore = ((int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
            $countStmt->close();
        }

        return [
            'events' => $events,
            'next_after_id' => $nextRowAfterId,
            'has_more_in_segment' => $hasMore,
        ];
    }

    private function exportRecipeSegment(mysqli $conn, string $branchUuid, int $rowAfterId, int $limit): array
    {
        $stmt = $conn->prepare('SELECT * FROM recipe_headers WHERE id > ? ORDER BY id ASC LIMIT ?');
        $stmt->bind_param('ii', $rowAfterId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = [];
        $nextRowAfterId = $rowAfterId;
        while ($header = $result->fetch_assoc()) {
            $recipeId = (int) $header['id'];
            $nextRowAfterId = $recipeId;
            $lines = $this->rowsForRecipeChildTable($conn, 'recipe_lines', $recipeId);
            $variantLines = $this->rowsForRecipeChildTable($conn, 'recipe_variant_lines', $recipeId);
            $costSnapshots = $this->rowsForRecipeChildTable($conn, 'recipe_cost_snapshots', $recipeId);
            $recipeUuid = (string) ($header['recipe_uuid'] ?? PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'recipe_headers:' . $recipeId));
            $payload = [
                'schema_version' => 1,
                'snapshot_type' => 'recipe_bundle',
                'domain' => 'recipe',
                'branch_uuid' => $branchUuid,
                'source_system' => 'cloud_restore',
                'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'recipe_id' => $recipeId,
                'recipe_uuid' => $recipeUuid,
                'header' => $header,
                'lines' => $lines,
                'variant_lines' => $variantLines,
                'cost_snapshots' => $costSnapshots,
            ];
            $events[] = $this->wrapOperationalEvent('recipe.saved', 'recipe', 'recipe', $payload);
        }
        $result->free();
        $stmt->close();

        $hasMore = count($events) >= $limit;
        if (!$hasMore && $events !== []) {
            $countStmt = $conn->prepare('SELECT COUNT(*) AS c FROM recipe_headers WHERE id > ?');
            $countStmt->bind_param('i', $nextRowAfterId);
            $countStmt->execute();
            $hasMore = ((int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
            $countStmt->close();
        }

        return [
            'events' => $events,
            'next_after_id' => $nextRowAfterId,
            'has_more_in_segment' => $hasMore,
        ];
    }

    private function exportShopSettingsSegment(mysqli $conn, string $branchUuid, int $rowAfterId): array
    {
        if ($rowAfterId > 0) {
            return [
                'events' => [],
                'next_after_id' => $rowAfterId,
                'has_more_in_segment' => false,
            ];
        }

        $payload = (new ShopSettingsSyncPayloadService())->build($conn, $branchUuid, ['source_system' => 'cloud_restore']);
        if (!$payload) {
            return [
                'events' => [],
                'next_after_id' => 0,
                'has_more_in_segment' => false,
            ];
        }

        return [
            'events' => [$this->wrapOperationalEvent('shop_settings.saved', 'shop_settings', 'shop_settings', $payload)],
            'next_after_id' => 1,
            'has_more_in_segment' => false,
        ];
    }

    private function exportModifierGroupSegment(mysqli $conn, string $branchUuid, int $rowAfterId, int $limit): array
    {
        $stmt = $conn->prepare('SELECT id FROM modifier_groups WHERE id > ? ORDER BY id ASC LIMIT ?');
        $stmt->bind_param('ii', $rowAfterId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = [];
        $nextRowAfterId = $rowAfterId;
        $builder = new ModifierGroupSyncPayloadService();
        while ($row = $result->fetch_assoc()) {
            $groupId = (int) $row['id'];
            $nextRowAfterId = $groupId;
            $payload = $builder->build($conn, $groupId, $branchUuid, ['source_system' => 'cloud_restore']);
            if ($payload) {
                $events[] = $this->wrapOperationalEvent('modifier_group.saved', 'modifier_group', 'modifier_group', $payload);
            }
        }
        $result->free();
        $stmt->close();

        $hasMore = count($events) >= $limit;
        if (!$hasMore && $events !== []) {
            $countStmt = $conn->prepare('SELECT COUNT(*) AS c FROM modifier_groups WHERE id > ?');
            $countStmt->bind_param('i', $nextRowAfterId);
            $countStmt->execute();
            $hasMore = ((int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
            $countStmt->close();
        }

        return [
            'events' => $events,
            'next_after_id' => $nextRowAfterId,
            'has_more_in_segment' => $hasMore,
        ];
    }

    private function exportMoovaShopLinkSegment(mysqli $conn, string $branchUuid, int $rowAfterId, int $limit): array
    {
        $stmt = $conn->prepare('SELECT * FROM moova_pos_shop_links WHERE id > ? ORDER BY id ASC LIMIT ?');
        $stmt->bind_param('ii', $rowAfterId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = [];
        $nextRowAfterId = $rowAfterId;
        while ($row = $result->fetch_assoc()) {
            $nextRowAfterId = (int) $row['id'];
            $payload = [
                'schema_version' => 1,
                'snapshot_type' => 'moova_shop_link',
                'branch_uuid' => $branchUuid,
                'source_system' => 'cloud_restore',
                'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'link' => $row,
            ];
            $events[] = $this->wrapOperationalEvent('moova.shop_link_saved', 'moova_shop_link', 'moova_shop_link', $payload);
        }
        $result->free();
        $stmt->close();

        $hasMore = count($events) >= $limit;
        if (!$hasMore && $events !== []) {
            $countStmt = $conn->prepare('SELECT COUNT(*) AS c FROM moova_pos_shop_links WHERE id > ?');
            $countStmt->bind_param('i', $nextRowAfterId);
            $countStmt->execute();
            $hasMore = ((int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
            $countStmt->close();
        }

        return [
            'events' => $events,
            'next_after_id' => $nextRowAfterId,
            'has_more_in_segment' => $hasMore,
        ];
    }

    private function exportShiftCloseSegment(mysqli $conn, string $branchUuid, int $rowAfterId, int $limit): array
    {
        $stmt = $conn->prepare('SELECT id FROM drawer_session_close_summaries WHERE id > ? ORDER BY id ASC LIMIT ?');
        $stmt->bind_param('ii', $rowAfterId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = [];
        $nextRowAfterId = $rowAfterId;
        $builder = new ShiftCloseSyncPayloadService();
        while ($row = $result->fetch_assoc()) {
            $closeSummaryId = (int) $row['id'];
            $nextRowAfterId = $closeSummaryId;
            $payload = $builder->build($conn, $closeSummaryId, $branchUuid, ['source_system' => 'cloud_restore']);
            if ($payload) {
                $events[] = $this->wrapOperationalEvent('shift_close.saved', 'shift_close', 'shift_close', $payload);
            }
        }
        $result->free();
        $stmt->close();

        $hasMore = count($events) >= $limit;
        if (!$hasMore && $events !== []) {
            $countStmt = $conn->prepare('SELECT COUNT(*) AS c FROM drawer_session_close_summaries WHERE id > ?');
            $countStmt->bind_param('i', $nextRowAfterId);
            $countStmt->execute();
            $hasMore = ((int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
            $countStmt->close();
        }

        return [
            'events' => $events,
            'next_after_id' => $nextRowAfterId,
            'has_more_in_segment' => $hasMore,
        ];
    }

    private function operationalRowEvent(string $branchUuid, string $domain, array $definition, array $row): ?array
    {
        $rowId = (int) ($row['id'] ?? 0);
        if ($rowId <= 0) {
            return null;
        }

        foreach ($definition['exclude_columns'] ?? [] as $column) {
            unset($row[$column]);
        }

        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'operational_row',
            'domain' => $domain,
            'table' => (string) ($definition['table'] ?? ''),
            'primary_key' => 'id',
            'row' => $row,
            'branch_uuid' => $branchUuid,
            'source_system' => 'cloud_restore',
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        return $this->wrapOperationalEvent(
            (string) ($definition['event_type'] ?? 'operational.saved'),
            (string) ($definition['aggregate_type'] ?? $domain),
            (string) ($definition['entity_type'] ?? $domain),
            $payload
        );
    }

    private function wrapOperationalEvent(string $eventType, string $aggregateType, string $entityType, array $payload): array
    {
        return [
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'entity_type' => $entityType,
            'payload' => $payload,
        ];
    }

    private function rowsForRecipeChildTable(mysqli $conn, string $table, int $recipeId): array
    {
        if (!$this->tableExists($conn, $table)) {
            return [];
        }

        $stmt = $conn->prepare("SELECT * FROM `{$table}` WHERE recipe_id = ? ORDER BY id ASC");
        $stmt->bind_param('i', $recipeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $stmt->close();

        return $rows;
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
