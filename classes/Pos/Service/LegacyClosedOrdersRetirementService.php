<?php

require_once dirname(__DIR__, 2) . '/Sync/BranchIdentity.php';
require_once dirname(__DIR__, 2) . '/Sync/PosOrderSnapshotBuilder.php';

final class LegacyClosedOrdersRetirementService
{
    public function inspect(mysqli $conn): array
    {
        if (!$this->tableExists($conn, 'closed_orders')) {
            return ['source_exists' => false, 'rows' => [], 'counts' => [], 'manifest_hash' => hash('sha256', '[]')];
        }

        $hasDrawerId = $this->columnExists($conn, 'closed_orders', 'drawer_session_id');
        $result = $conn->query('SELECT * FROM closed_orders ORDER BY id ASC');
        $rows = [];
        $counts = ['linked' => 0, 'backfilled' => 0, 'missing_drawer' => 0, 'unlinkable' => 0];
        while ($source = $result->fetch_assoc()) {
            $rawJson = $this->canonicalJson($source);
            $drawer = null;
            $summary = null;
            $drawerId = $hasDrawerId ? (int) ($source['drawer_session_id'] ?? 0) : 0;
            if (!$hasDrawerId || $drawerId < 1) {
                $status = 'unlinkable';
            } else {
                $drawer = $this->findDrawer($conn, $drawerId);
                if (!$drawer) {
                    $status = 'missing_drawer';
                } else {
                    $summary = $this->findSummary($conn, $drawerId);
                    $status = $summary ? 'backfilled' : 'linked';
                }
            }
            $counts[$status]++;
            $rows[] = [
                'legacy_row_id' => (string) ($source['id'] ?? ''),
                'row_hash' => hash('sha256', $rawJson),
                'link_status' => $status,
                'drawer_session_id' => $drawerId > 0 ? $drawerId : null,
                'resolved_drawer_uuid' => $drawer['uuid'] ?? null,
                'resolved_summary_uuid' => $summary['uuid'] ?? null,
                'raw_json' => $rawJson,
                'source' => $source,
            ];
        }
        $result->free();

        $manifestRows = array_map(
            static fn (array $row): array => ['legacy_row_id' => $row['legacy_row_id'], 'row_hash' => $row['row_hash']],
            $rows
        );

        return [
            'source_exists' => true,
            'rows' => $rows,
            'counts' => $counts,
            'source_count' => count($rows),
            'manifest_hash' => hash('sha256', $this->canonicalJson($manifestRows)),
        ];
    }

    public function archive(mysqli $conn, string $backupFile, array $approvedRowHashes = []): array
    {
        $this->assertBackup($backupFile);
        if (!$this->tableExists($conn, 'legacy_closed_orders_archive')) {
            throw new RuntimeException('SCHEMA_MIGRATIONS_PENDING');
        }

        $plan = $this->inspect($conn);
        $batchId = SyncBranchIdentity::generateUuidV4();
        $approved = array_fill_keys(array_values(array_filter(array_map('strval', $approvedRowHashes))), true);
        $conn->begin_transaction();
        try {
            foreach ($plan['rows'] as $row) {
                if ($row['link_status'] === 'linked') {
                    $this->backfillRow($conn, $row);
                }
            }
            $final = $this->inspect($conn);
            foreach ($final['rows'] as $row) {
                $status = (string) $row['link_status'];
                if (isset($approved[$row['row_hash']]) && in_array($status, ['unlinkable', 'missing_drawer'], true)) {
                    $status = 'approved_unlinkable';
                }
                $this->archiveRow($conn, $batchId, $final['manifest_hash'], $row, $status);
            }
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        return array_merge($final, ['batch_id' => $batchId, 'archived' => count($final['rows'])]);
    }

    public function drop(mysqli $conn, string $backupFile, string $manifestHash): array
    {
        $this->assertBackup($backupFile);
        if (!preg_match('/^[a-f0-9]{64}$/', $manifestHash)) {
            throw new RuntimeException('LEGACY_CLOSE_MANIFEST_HASH_INVALID');
        }
        $plan = $this->inspect($conn);
        if (!$plan['source_exists']) {
            return ['dropped' => false, 'already_absent' => true, 'manifest_hash' => $plan['manifest_hash']];
        }
        if (!hash_equals($plan['manifest_hash'], $manifestHash)) {
            throw new RuntimeException('LEGACY_CLOSE_MANIFEST_CHANGED');
        }

        $stmt = $conn->prepare(
            'SELECT legacy_row_id, row_hash, link_status FROM legacy_closed_orders_archive WHERE manifest_hash = ?'
        );
        $stmt->bind_param('s', $manifestHash);
        $stmt->execute();
        $archived = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $archived[(string) $row['legacy_row_id'] . ':' . (string) $row['row_hash']] = (string) $row['link_status'];
        }
        $stmt->close();

        if (count($archived) !== (int) $plan['source_count']) {
            throw new RuntimeException('LEGACY_CLOSE_ARCHIVE_COUNT_MISMATCH');
        }
        foreach ($plan['rows'] as $row) {
            $key = $row['legacy_row_id'] . ':' . $row['row_hash'];
            $archivedStatus = $archived[$key] ?? '';
            if ($archivedStatus === '') {
                throw new RuntimeException('LEGACY_CLOSE_ARCHIVE_HASH_MISMATCH');
            }
            if (!in_array($archivedStatus, ['backfilled', 'approved_unlinkable'], true)) {
                throw new RuntimeException('LEGACY_CLOSE_UNAPPROVED_ROWS_REMAIN');
            }
        }

        $conn->query('DROP TABLE closed_orders');

        return ['dropped' => true, 'source_count' => $plan['source_count'], 'manifest_hash' => $manifestHash];
    }

    private function backfillRow(mysqli $conn, array $row): void
    {
        $source = $row['source'];
        $drawerId = (int) ($row['drawer_session_id'] ?? 0);
        if ($drawerId < 1 || $this->findSummary($conn, $drawerId)) {
            return;
        }
        $uuid = PosOrderSnapshotBuilder::deterministicUuid('legacy-closed-orders', $row['legacy_row_id'] . ':' . $row['row_hash']);
        $shift = trim((string) ($source['shift'] ?? '')) ?: ('legacy_' . $row['legacy_row_id']);
        $totalSales = (string) ($source['total_sales'] ?? 0);
        $cash = (string) ($source['total_cash'] ?? $source['cash'] ?? 0);
        $nonCash = (string) ($source['total_visa'] ?? 0);
        $discount = (string) ($source['total_discount'] ?? 0);
        $expenses = (string) ($source['expenses'] ?? 0);
        $expenseNotes = $source['exp_notes'] ?? null;
        $countedNonCash = $source['actual_visa'] ?? null;
        $difference = $countedNonCash === null ? null : (string) ((float) $countedNonCash - (float) $nonCash);
        $report = $this->normalizeJsonDocument($source['json_details'] ?? null);
        $createdAt = $source['crtime'] ?? $source['created_at'] ?? date('Y-m-d H:i:s');
        $stmt = $conn->prepare(
            'INSERT INTO drawer_session_close_summaries '
            . '(uuid, drawer_session_id, shift_number, total_orders, total_sales, cash_sales, non_cash_sales, discount_total, return_total, expense_total, expense_notes, expected_non_cash, counted_non_cash, non_cash_difference, close_path, report_snapshot_json, payment_summary_json, created_at) '
            . "VALUES (?, ?, ?, 0, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 'legacy_archive_backfill', ?, NULL, ?)"
        );
        $stmt->bind_param('sissssssssssss', $uuid, $drawerId, $shift, $totalSales, $cash, $nonCash, $discount, $expenses, $expenseNotes, $nonCash, $countedNonCash, $difference, $report, $createdAt);
        $stmt->execute();
        $stmt->close();
    }

    private function archiveRow(mysqli $conn, string $batchId, string $manifestHash, array $row, string $status): void
    {
        $sourceCreated = $row['source']['crtime'] ?? $row['source']['created_at'] ?? null;
        $stmt = $conn->prepare(
            'INSERT INTO legacy_closed_orders_archive '
            . '(batch_id, legacy_row_id, raw_json, row_hash, link_status, resolved_drawer_uuid, resolved_summary_uuid, manifest_hash, source_created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE link_status = VALUES(link_status), resolved_drawer_uuid = VALUES(resolved_drawer_uuid), resolved_summary_uuid = VALUES(resolved_summary_uuid), manifest_hash = VALUES(manifest_hash)'
        );
        $stmt->bind_param('sssssssss', $batchId, $row['legacy_row_id'], $row['raw_json'], $row['row_hash'], $status, $row['resolved_drawer_uuid'], $row['resolved_summary_uuid'], $manifestHash, $sourceCreated);
        $stmt->execute();
        $stmt->close();
    }

    private function findDrawer(mysqli $conn, int $id): ?array
    {
        if (!$this->tableExists($conn, 'drawer_sessions')) {
            return null;
        }
        $stmt = $conn->prepare('SELECT id, uuid FROM drawer_sessions WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function findSummary(mysqli $conn, int $drawerId): ?array
    {
        if (!$this->tableExists($conn, 'drawer_session_close_summaries')) {
            return null;
        }
        $stmt = $conn->prepare('SELECT id, uuid FROM drawer_session_close_summaries WHERE drawer_session_id = ? LIMIT 1');
        $stmt->bind_param('i', $drawerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function assertBackup(string $backupFile): void
    {
        if ($backupFile === '' || !is_file($backupFile) || !is_readable($backupFile) || filesize($backupFile) < 1) {
            throw new RuntimeException('READABLE_DATABASE_BACKUP_REQUIRED');
        }
    }

    private function canonicalJson($value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $value = array_map(fn ($item) => is_array($item) ? $this->canonicalize($item) : $item, $value);
            } else {
                $value = $this->canonicalize($value);
            }
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function normalizeJsonDocument($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }

            return json_encode(['legacy_raw' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $escaped = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$escaped}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
