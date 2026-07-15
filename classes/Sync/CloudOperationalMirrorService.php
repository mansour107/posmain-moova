<?php

require_once __DIR__ . '/OperationalSyncDomains.php';
require_once __DIR__ . '/PosOrderSnapshotBuilder.php';

class CloudOperationalMirrorService
{
    private array $columnCache = [];

    public function applyFromBranchEvent(mysqli $conn, string $branchUuid, array $event): ?array
    {
        $payload = $this->payload($event);
        $snapshotType = (string) ($payload['snapshot_type'] ?? '');

        if ($snapshotType === 'operational_row') {
            return $this->mirrorOperationalRow($conn, $payload);
        }

        if ($snapshotType === 'operational_delete') {
            return $this->mirrorOperationalDelete($conn, $payload);
        }

        if ($snapshotType === 'recipe_bundle') {
            return $this->mirrorRecipeBundle($conn, $payload);
        }

        if ($snapshotType === 'shop_settings') {
            return $this->mirrorShopSettings($conn, $payload);
        }

        if ($snapshotType === 'modifier_group_bundle') {
            return $this->mirrorModifierGroupBundle($conn, $payload);
        }

        if ($snapshotType === 'moova_shop_link') {
            return $this->mirrorMoovaShopLink($conn, $payload);
        }

        if ($snapshotType === 'shift_close') {
            return $this->mirrorShiftClose($conn, $branchUuid, $payload);
        }

        return null;
    }

    private function mirrorOperationalRow(mysqli $conn, array $payload): ?array
    {
        $table = (string) ($payload['table'] ?? '');
        $row = $payload['row'] ?? null;
        if ($table === 'drawer_sessions' && is_array($row)) {
            return $this->mirrorDrawerSessionRow($conn, $row);
        }
        if ($table === '' || !is_array($row) || empty($row['id'])) {
            return null;
        }

        $domain = (string) ($payload['domain'] ?? '');
        $definition = $domain !== '' ? OperationalSyncDomains::get($domain) : null;
        if ($definition) {
            $row = $this->sanitizeRow($row, $definition['exclude_columns'] ?? []);
        }

        $this->upsertRow($conn, $table, $row);

        return ['entity_id' => $table . ':' . (int) $row['id']];
    }

    private function mirrorOperationalDelete(mysqli $conn, array $payload): ?array
    {
        $table = (string) ($payload['table'] ?? '');
        $rowId = (int) ($payload['row_id'] ?? 0);
        if ($table === '' || $rowId <= 0 || !$this->tableExists($conn, $table)) {
            return null;
        }

        $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE id = ?");
        $stmt->bind_param('i', $rowId);
        $stmt->execute();
        $stmt->close();

        return ['entity_id' => $table . ':' . $rowId, 'deleted' => true];
    }

    private function mirrorRecipeBundle(mysqli $conn, array $payload): ?array
    {
        $header = $payload['header'] ?? null;
        if (!is_array($header) || empty($header['id'])) {
            return null;
        }

        $recipeId = (int) $header['id'];
        $this->upsertRow($conn, 'recipe_headers', $header);

        if ($this->tableExists($conn, 'recipe_lines')) {
            $conn->query('DELETE FROM recipe_lines WHERE recipe_id = ' . $recipeId);
            foreach ($payload['lines'] ?? [] as $line) {
                if (!is_array($line) || empty($line['id'])) {
                    continue;
                }
                $this->upsertRow($conn, 'recipe_lines', $line);
            }
        }

        if ($this->tableExists($conn, 'recipe_variant_lines')) {
            $conn->query('DELETE FROM recipe_variant_lines WHERE recipe_id = ' . $recipeId);
            foreach ($payload['variant_lines'] ?? [] as $line) {
                if (!is_array($line) || empty($line['id'])) {
                    continue;
                }
                $this->upsertRow($conn, 'recipe_variant_lines', $line);
            }
        }

        if ($this->tableExists($conn, 'recipe_cost_snapshots')) {
            foreach ($payload['cost_snapshots'] ?? [] as $snapshot) {
                if (!is_array($snapshot) || empty($snapshot['id'])) {
                    continue;
                }
                $this->upsertRow($conn, 'recipe_cost_snapshots', $snapshot);
            }
        }

        return ['entity_id' => 'recipe_headers:' . $recipeId];
    }

    private function mirrorShopSettings(mysqli $conn, array $payload): ?array
    {
        $settings = $payload['settings'] ?? null;
        if (!is_array($settings)) {
            return null;
        }

        $settings['id'] = 1;
        $this->upsertRow($conn, 'settings', $settings);

        return ['entity_id' => 'settings:1'];
    }

    private function mirrorModifierGroupBundle(mysqli $conn, array $payload): ?array
    {
        $group = $payload['group'] ?? null;
        if (!is_array($group) || empty($group['id'])) {
            return null;
        }

        $groupId = (int) $group['id'];
        $this->upsertRow($conn, 'modifier_groups', $group);

        if ($this->tableExists($conn, 'modifier_options')) {
            foreach ($payload['options'] ?? [] as $option) {
                if (!is_array($option) || empty($option['id'])) {
                    continue;
                }
                $this->upsertRow($conn, 'modifier_options', $option);
            }
        }

        if ($this->tableExists($conn, 'item_modifier_groups')) {
            foreach ($payload['item_links'] ?? [] as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $this->upsertJunctionRow($conn, 'item_modifier_groups', $link, ['item_id', 'group_id']);
            }
        }

        return ['entity_id' => 'modifier_groups:' . $groupId];
    }

    private function mirrorMoovaShopLink(mysqli $conn, array $payload): ?array
    {
        $link = $payload['link'] ?? null;
        if (!is_array($link) || empty($link['id'])) {
            return null;
        }

        $this->upsertRow($conn, 'moova_pos_shop_links', $link);

        return ['entity_id' => 'moova_pos_shop_links:' . (int) $link['id']];
    }

    private function mirrorShiftClose(mysqli $conn, string $branchUuid, array $payload): ?array
    {
        $shift = $payload['shift'] ?? null;
        if (!is_array($shift) || !$this->tableExists($conn, 'drawer_session_close_summaries')) {
            return null;
        }

        $schemaVersion = (int) ($payload['schema_version'] ?? 1);
        $legacy = is_array($shift['legacy'] ?? null) ? $shift['legacy'] : [];
        $drawerUuid = trim((string) ($payload['drawer_session_uuid'] ?? $shift['drawer_session_uuid'] ?? ''));
        $recoveredLegacyDrawer = false;
        if ($schemaVersion >= 2) {
            $drawerSessionId = $this->upsertClosedDrawerSession($conn, $drawerUuid, $payload, $shift);
        } else {
            $drawerSessionId = (int) ($shift['local_drawer_session_id'] ?? $legacy['drawer_session_id'] ?? 0);
            if ($drawerUuid !== '') {
                $found = $this->findDrawerByUuid($conn, $drawerUuid, true);
                if ($found) {
                    $drawerSessionId = (int) $found['id'];
                }
            }
            if ($drawerSessionId > 0 && !$this->drawerSessionExists($conn, $drawerSessionId)) {
                $drawerSessionId = 0;
            }
            if ($drawerSessionId < 1) {
                $drawerSessionId = $this->recoverLegacyShiftDrawerSession($conn, $branchUuid, $payload, $shift, $legacy);
                $recoveredLegacyDrawer = $drawerSessionId > 0;
            }
            if ($drawerSessionId < 1) {
                throw new RuntimeException('V1_SHIFT_CLOSE_DRAWER_RECOVERY_FAILED');
            }
        }

        $summary = $payload['close_summary'] ?? null;
        if (!is_array($summary)) {
            // v1 restore compatibility: convert its embedded legacy close row
            // into the canonical summary after resolving or recovering a drawer.
            $summary = [
                'id' => (int) ($shift['close_summary_id'] ?? $shift['local_closed_order_id'] ?? $legacy['id'] ?? 0),
                'uuid' => (string) ($payload['close_uuid'] ?? $shift['close_uuid'] ?? ''),
                'drawer_session_id' => $drawerSessionId,
                'shift_number' => (string) ($shift['shift_number'] ?? $legacy['shift'] ?? ''),
                'total_orders' => (int) ($legacy['total_orders'] ?? 0),
                'total_sales' => $shift['total_sales'] ?? $legacy['total_sales'] ?? 0,
                'cash_sales' => $shift['total_cash'] ?? $legacy['total_cash'] ?? $legacy['cash'] ?? 0,
                'non_cash_sales' => $shift['total_card'] ?? $legacy['total_visa'] ?? 0,
                'discount_total' => $legacy['total_discount'] ?? 0,
                'return_total' => $legacy['total_returns'] ?? 0,
                'expense_total' => $legacy['expenses'] ?? 0,
                'expense_notes' => $legacy['exp_notes'] ?? null,
                'expected_non_cash' => $legacy['total_visa'] ?? null,
                'counted_non_cash' => $legacy['actual_visa'] ?? null,
                'non_cash_difference' => isset($legacy['actual_visa'], $legacy['total_visa'])
                    ? (float) $legacy['actual_visa'] - (float) $legacy['total_visa']
                    : null,
                'close_path' => 'sync_v1_restore',
                'report_snapshot_json' => $legacy['json_details'] ?? null,
                'payment_summary_json' => null,
                'created_at' => $legacy['created_at'] ?? null,
            ];
        }

        $summary['drawer_session_id'] = $drawerSessionId;
        if (empty($summary['uuid'])) {
            $summary['uuid'] = (string) ($payload['close_uuid'] ?? $shift['close_uuid'] ?? '');
        }
        if (trim((string) $summary['uuid']) === '') {
            $legacyIdentity = (int) ($shift['local_closed_order_id'] ?? $legacy['id'] ?? 0);
            $summary['uuid'] = PosOrderSnapshotBuilder::deterministicUuid(
                $branchUuid,
                $legacyIdentity > 0
                    ? 'closed_orders:' . $legacyIdentity
                    : 'drawer_sessions:' . $drawerSessionId . ':close'
            );
        }
        if ($schemaVersion < 2 && empty($summary['id'])) {
            // A source id is not required after legacy recovery; UUID and the
            // one-to-one drawer relation are the portable identities.
            unset($summary['id']);
        }
        if (empty($summary['created_at'])) {
            unset($summary['created_at']);
        }
        $this->assertCloseSummaryIdentity($conn, (string) $summary['uuid'], $drawerSessionId);
        if ($schemaVersion >= 2) {
            // V2 is UUID-linked. A source auto-increment ID is not portable and
            // may collide with an unrelated local close during restore.
            unset($summary['id']);
            $this->upsertRow($conn, 'drawer_session_close_summaries', $summary, true);
        } elseif (!empty($summary['id'])) {
            $this->upsertRow($conn, 'drawer_session_close_summaries', $summary);
        } else {
            $this->upsertRow($conn, 'drawer_session_close_summaries', $summary, true);
        }

        return [
            'entity_id' => 'drawer_session_close_summaries:' . $drawerSessionId,
            'recovered_legacy_shift_close' => $recoveredLegacyDrawer,
        ];
    }

    private function upsertClosedDrawerSession(
        mysqli $conn,
        string $drawerUuid,
        array $payload,
        array $shift
    ): int {
        if (!$this->tableExists($conn, 'drawer_sessions')) {
            throw new RuntimeException('SCHEMA_MIGRATIONS_PENDING');
        }
        if (!$this->isUuid($drawerUuid)) {
            throw new RuntimeException('SHIFT_CLOSE_DRAWER_UUID_INVALID');
        }

        $snapshot = is_array($payload['drawer_session'] ?? null) ? $payload['drawer_session'] : [];
        $closedAt = $this->nullableDateTime($snapshot['closed_at'] ?? $shift['closed_at'] ?? null);
        if ($closedAt === null) {
            throw new RuntimeException('SHIFT_CLOSE_CLOSED_AT_REQUIRED');
        }

        $status = (string) ($snapshot['status'] ?? $shift['status'] ?? 'closed');
        if (!in_array($status, ['closed', 'forced_closed'], true)) {
            throw new RuntimeException('SHIFT_CLOSE_STATUS_NOT_TERMINAL');
        }

        $existing = $this->findDrawerByUuid($conn, $drawerUuid, true);
        $this->assertDrawerScopeCompatible($existing, $snapshot, $shift);
        if (($existing['status'] ?? '') === 'forced_closed') {
            $status = 'forced_closed';
        }

        $userId = max(0, (int) ($snapshot['user_id'] ?? $shift['cashier_user_id'] ?? 0));
        $openedAt = $this->nullableDateTime($snapshot['opened_at'] ?? $shift['opened_at'] ?? null) ?: $closedAt;
        if (strtotime($openedAt) > strtotime($closedAt)) {
            throw new RuntimeException('SHIFT_CLOSE_TIME_RANGE_INVALID');
        }

        $row = array_merge($snapshot, [
            'uuid' => $drawerUuid,
            'user_id' => $userId,
            'tenant' => max(0, (int) ($snapshot['tenant'] ?? $shift['tenant'] ?? 0)),
            'branch' => max(0, (int) ($snapshot['branch'] ?? $shift['branch'] ?? 0)),
            'opened_at' => $openedAt,
            'business_day' => (string) ($snapshot['business_day'] ?? substr($openedAt, 0, 10)),
            'opened_by' => max(0, (int) ($snapshot['opened_by'] ?? $userId)),
            'opening_cash' => $snapshot['opening_cash'] ?? 0,
            'closed_at' => $closedAt,
            'closed_by' => max(0, (int) ($snapshot['closed_by'] ?? $userId)),
            'expected_cash' => $snapshot['expected_cash'] ?? $this->expectedCashFromShift($shift),
            'counted_cash' => $snapshot['counted_cash'] ?? $shift['actual_cash'] ?? null,
            'difference' => $snapshot['difference'] ?? $shift['cash_deficit'] ?? null,
            'status' => $status,
            'variance_status' => (string) ($snapshot['variance_status'] ?? $shift['variance_status'] ?? 'none'),
            'variance_type' => (string) ($snapshot['variance_type'] ?? $shift['variance_type'] ?? 'none'),
            'open_branch_lock' => null,
            'open_register_lock' => null,
            'open_user_lock' => null,
            'close_token_hash' => null,
        ]);
        unset($row['id']);
        $this->upsertRow($conn, 'drawer_sessions', $row, true);

        $saved = $this->findDrawerByUuid($conn, $drawerUuid, true);
        if (!$saved) {
            throw new RuntimeException('SHIFT_CLOSE_DRAWER_UPSERT_FAILED');
        }

        return (int) $saved['id'];
    }

    private function mirrorDrawerSessionRow(mysqli $conn, array $row): ?array
    {
        $uuid = trim((string) ($row['uuid'] ?? ''));
        if (!$this->isUuid($uuid)) {
            throw new RuntimeException('DRAWER_SESSION_UUID_INVALID');
        }

        $existing = $this->findDrawerByUuid($conn, $uuid, true);
        $this->assertDrawerScopeCompatible($existing, $row, []);
        $incomingStatus = (string) ($row['status'] ?? 'open');
        if ($existing && $this->isTerminalDrawerStatus((string) ($existing['status'] ?? '')) && !$this->isTerminalDrawerStatus($incomingStatus)) {
            return ['entity_id' => 'drawer_sessions:' . (int) $existing['id'], 'stale_open_ignored' => true];
        }
        if ($existing && ($existing['status'] ?? '') === 'forced_closed' && $incomingStatus === 'closed') {
            $row['status'] = 'forced_closed';
        }
        if ($this->isTerminalDrawerStatus((string) ($row['status'] ?? ''))) {
            $row['open_branch_lock'] = null;
            $row['open_register_lock'] = null;
            $row['open_user_lock'] = null;
            $row['close_token_hash'] = null;
        }

        unset($row['id']);
        $this->upsertRow($conn, 'drawer_sessions', $row, true);
        $saved = $this->findDrawerByUuid($conn, $uuid, false);

        return $saved ? ['entity_id' => 'drawer_sessions:' . (int) $saved['id']] : null;
    }

    private function assertCloseSummaryIdentity(mysqli $conn, string $summaryUuid, int $drawerSessionId): void
    {
        $stmt = $conn->prepare(
            'SELECT uuid, drawer_session_id FROM drawer_session_close_summaries '
            . 'WHERE uuid = ? OR drawer_session_id = ? FOR UPDATE'
        );
        $stmt->bind_param('si', $summaryUuid, $drawerSessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if ((string) $row['uuid'] !== $summaryUuid || (int) $row['drawer_session_id'] !== $drawerSessionId) {
                $stmt->close();
                throw new RuntimeException('DRAWER_CLOSE_SUMMARY_IDENTITY_CONFLICT');
            }
        }
        $stmt->close();
    }

    private function assertDrawerScopeCompatible(?array $existing, array $snapshot, array $shift): void
    {
        if (!$existing) {
            return;
        }
        foreach (['tenant', 'branch'] as $field) {
            $incoming = (int) ($snapshot[$field] ?? $shift[$field] ?? 0);
            $current = (int) ($existing[$field] ?? 0);
            if ($incoming > 0 && $current > 0 && $incoming !== $current) {
                throw new RuntimeException('DRAWER_SESSION_UUID_SCOPE_CONFLICT');
            }
        }
    }

    private function findDrawerByUuid(mysqli $conn, string $uuid, bool $forUpdate): ?array
    {
        if (!$this->tableExists($conn, 'drawer_sessions')) {
            return null;
        }
        $sql = 'SELECT * FROM drawer_sessions WHERE uuid = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function expectedCashFromShift(array $shift)
    {
        if (!isset($shift['actual_cash'], $shift['cash_deficit'])) {
            return null;
        }

        return (float) $shift['actual_cash'] - (float) $shift['cash_deficit'];
    }

    private function nullableDateTime($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function isTerminalDrawerStatus(string $status): bool
    {
        return in_array($status, ['closed', 'forced_closed'], true);
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function recoverLegacyShiftDrawerSession(
        mysqli $conn,
        string $branchUuid,
        array $payload,
        array $shift,
        array $legacy
    ): int {
        if (!$this->tableExists($conn, 'drawer_sessions')) {
            return 0;
        }

        $legacyCloseId = (int) (
            $shift['local_closed_order_id']
            ?? $legacy['id']
            ?? $payload['local_closed_order_id']
            ?? 0
        );
        $identity = $legacyCloseId > 0
            ? 'closed_orders:' . $legacyCloseId
            : 'shift_close:' . hash('sha256', json_encode([$shift, $legacy], JSON_UNESCAPED_SLASHES) ?: 'unknown');
        $drawerUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'restored_drawer:' . $identity);

        $existing = $conn->prepare('SELECT id FROM drawer_sessions WHERE uuid = ? LIMIT 1');
        $existing->bind_param('s', $drawerUuid);
        $existing->execute();
        $found = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($found) {
            return (int) $found['id'];
        }

        $closedAt = $this->normalizedDateTime(
            $shift['closed_at'] ?? $legacy['endtime'] ?? $legacy['crtime'] ?? $legacy['date'] ?? null,
            date('Y-m-d H:i:s')
        );
        $openedAt = $this->normalizedDateTime(
            $shift['opened_at'] ?? $legacy['strttime'] ?? null,
            $closedAt
        );
        if (strtotime($openedAt) > strtotime($closedAt)) {
            $openedAt = $closedAt;
        }
        $counted = (float) ($shift['actual_cash'] ?? $legacy['actual_cash'] ?? $legacy['cash'] ?? 0);
        $difference = (float) ($shift['cash_deficit'] ?? $legacy['deficit'] ?? 0);
        $expected = $counted - $difference;
        $userId = max(0, (int) ($shift['cashier_user_id'] ?? $legacy['user_id'] ?? $legacy['user'] ?? 0));

        $this->upsertRow($conn, 'drawer_sessions', [
            'uuid' => $drawerUuid,
            'user_id' => $userId,
            'tenant' => max(0, (int) ($shift['tenant'] ?? $legacy['tenant'] ?? 0)),
            'branch' => max(0, (int) ($shift['branch'] ?? $legacy['branch'] ?? 0)),
            'opened_at' => $openedAt,
            'business_day' => substr($openedAt, 0, 10),
            'opened_by' => $userId,
            'opening_cash' => '0.000',
            'closed_at' => $closedAt,
            'closed_by' => $userId,
            'expected_cash' => number_format($expected, 3, '.', ''),
            'counted_cash' => number_format($counted, 3, '.', ''),
            'difference' => number_format($difference, 3, '.', ''),
            'status' => 'closed',
            'variance_status' => abs($difference) > 0.0001 ? 'unresolved' : 'none',
            'variance_type' => abs($difference) > 0.0001 ? 'closing' : 'none',
            'notes' => 'Recovered from unlinked v1 shift-close backup',
        ], true);

        $lookup = $conn->prepare('SELECT id FROM drawer_sessions WHERE uuid = ? LIMIT 1');
        $lookup->bind_param('s', $drawerUuid);
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();
        $lookup->close();

        return (int) ($row['id'] ?? 0);
    }

    private function drawerSessionExists(mysqli $conn, int $sessionId): bool
    {
        if ($sessionId < 1 || !$this->tableExists($conn, 'drawer_sessions')) {
            return false;
        }
        $stmt = $conn->prepare('SELECT 1 FROM drawer_sessions WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        return $found;
    }

    private function normalizedDateTime($value, string $fallback): string
    {
        $timestamp = strtotime(trim((string) $value));
        if ($timestamp === false) {
            return $fallback;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function upsertJunctionRow(mysqli $conn, string $table, array $row, array $keyColumns): void
    {
        if (!$this->tableExists($conn, $table)) {
            return;
        }

        foreach ($keyColumns as $column) {
            if (empty($row[$column])) {
                return;
            }
        }

        $columns = $this->tableColumns($conn, $table);
        $fields = [];
        $values = [];
        foreach ($row as $column => $value) {
            if (!in_array($column, $columns, true)) {
                continue;
            }
            $fields[] = '`' . $column . '`';
            $values[] = $value;
        }

        if ($fields === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $updates = [];
        foreach ($fields as $field) {
            $updates[] = $field . ' = VALUES(' . $field . ')';
        }

        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';
        if ($updates !== []) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        }

        $stmt = $conn->prepare($sql);
        $types = str_repeat('s', count($values));
        $this->bindParams($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    }

    private function upsertRow(mysqli $conn, string $table, array $row, bool $allowAutoId = false): void
    {
        if (!$this->tableExists($conn, $table) || $row === [] || (!$allowAutoId && empty($row['id']))) {
            return;
        }

        $columns = $this->tableColumns($conn, $table);
        $fields = [];
        $values = [];
        foreach ($row as $column => $value) {
            if (($allowAutoId && $column === 'id') || !in_array($column, $columns, true)) {
                continue;
            }
            $fields[] = '`' . $column . '`';
            $values[] = $value;
        }

        if ($fields === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $updates = [];
        foreach ($fields as $field) {
            if (!$allowAutoId && $field === '`id`') {
                continue;
            }
            $updates[] = $field . ' = VALUES(' . $field . ')';
        }

        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';
        if ($updates !== []) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        }

        $stmt = $conn->prepare($sql);
        $types = str_repeat('s', count($values));
        $this->bindParams($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    }

    private function sanitizeRow(array $row, array $excludeColumns): array
    {
        foreach ($excludeColumns as $column) {
            unset($row[$column]);
        }

        return $row;
    }

    private function payload(array $event): array
    {
        $payload = $event['payload'] ?? [];
        return is_array($payload) ? $payload : [];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        return $result && $result->num_rows > 0;
    }

    private function tableColumns(mysqli $conn, string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }

        $columns = [];
        $result = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        while ($row = $result->fetch_assoc()) {
            $columns[] = (string) $row['Field'];
        }
        $this->columnCache[$table] = $columns;

        return $columns;
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array $values): void
    {
        $refs = [];
        foreach ($values as $key => &$value) {
            $refs[$key] = &$value;
        }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}
