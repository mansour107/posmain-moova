<?php

class SyncProjectionVersionGuard
{
    private const CORE_SNAPSHOT_TYPES = [
        'pos_order' => 'order',
        'pos_table' => 'table',
        'pos_menu_item' => 'menu_item',
        'drawer_session_snapshot' => 'drawer_session',
        'drawer_movement_bundle' => 'drawer_movement',
        'financial_refund_bundle' => 'financial_refund',
        'manager_approval' => 'manager_approval',
        'inventory_movement' => 'inventory_movement',
        'inventory_balance' => 'inventory_balance',
        'customer_bundle' => 'pos_customer',
        'inventory_journal_bundle' => 'inventory_journal',
        'inventory_count_bundle' => 'inventory_count',
        'production_batch_bundle' => 'production_batch',
        'purchase_receipt_bundle' => 'purchase_receipt',
        'purchase_order_bundle' => 'purchase_order',
    ];

    public function supports(array $event): bool
    {
        $snapshotType = $this->snapshotType($event);
        if (isset(self::CORE_SNAPSHOT_TYPES[$snapshotType])) {
            return true;
        }

        return in_array($this->aggregateType($event), array_values(self::CORE_SNAPSHOT_TYPES), true);
    }

    /**
     * The caller must hold an open transaction. The returned `apply` decision is
     * only a reservation; markApplied() advances the cursor after projection.
     */
    public function evaluateAndLock(mysqli $conn, string $branchUuid, array $event): array
    {
        $branchUuid = trim($branchUuid);
        $aggregateType = $this->aggregateType($event);
        $aggregateUuid = trim((string) ($event['aggregate_uuid'] ?? ''));
        $payloadHash = $this->payloadHash($event);
        $effectiveRevision = $this->effectiveRevision($event);

        if ($branchUuid === '' || $aggregateType === '' || $aggregateUuid === '' || $payloadHash === '') {
            return $this->decision(
                'conflict',
                $effectiveRevision,
                0,
                '',
                'core projection event is missing branch, aggregate identity, or payload hash'
            );
        }

        $stmt = $conn->prepare("
            INSERT IGNORE INTO sync_projection_versions (
                branch_uuid,
                aggregate_type,
                aggregate_uuid,
                last_event_version,
                last_payload_hash
            ) VALUES (?, ?, ?, 0, '')
        ");
        $stmt->bind_param('sss', $branchUuid, $aggregateType, $aggregateUuid);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT last_event_version, last_payload_hash, last_event_uuid
            FROM sync_projection_versions
            WHERE branch_uuid = ?
              AND aggregate_type = ?
              AND aggregate_uuid = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('sss', $branchUuid, $aggregateType, $aggregateUuid);
        $stmt->execute();
        $stored = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$stored) {
            throw new RuntimeException('Unable to reserve the sync projection version cursor.');
        }

        $storedRevision = max(0, (int) ($stored['last_event_version'] ?? 0));
        $storedHash = trim((string) ($stored['last_payload_hash'] ?? ''));

        if ($storedRevision === 0) {
            return $this->decision('apply', $effectiveRevision, 0, '', 'first projection for aggregate');
        }
        if ($effectiveRevision < $storedRevision) {
            return $this->decision('stale', $effectiveRevision, $storedRevision, $storedHash, 'older aggregate revision');
        }
        if ($effectiveRevision === $storedRevision && hash_equals($storedHash, $payloadHash)) {
            return $this->decision('duplicate', $effectiveRevision, $storedRevision, $storedHash, 'aggregate revision already applied');
        }
        if ($effectiveRevision === $storedRevision) {
            return $this->decision('conflict', $effectiveRevision, $storedRevision, $storedHash, 'same aggregate revision has different content');
        }

        return $this->decision('apply', $effectiveRevision, $storedRevision, $storedHash, 'newer aggregate revision');
    }

    public function markApplied(mysqli $conn, string $branchUuid, array $event, array $decision): void
    {
        if (($decision['decision'] ?? '') !== 'apply') {
            throw new InvalidArgumentException('Only an apply decision may advance a projection cursor.');
        }

        $branchUuid = trim($branchUuid);
        $aggregateType = $this->aggregateType($event);
        $aggregateUuid = trim((string) ($event['aggregate_uuid'] ?? ''));
        $eventUuid = $this->nullableString($event['event_uuid'] ?? null);
        $eventType = $this->nullableString($event['event_type'] ?? null);
        $payloadHash = $this->payloadHash($event);
        $effectiveRevision = max(1, (int) ($decision['effective_revision'] ?? $this->effectiveRevision($event)));

        $stmt = $conn->prepare("
            UPDATE sync_projection_versions
            SET last_event_version = ?,
                last_payload_hash = ?,
                last_event_uuid = ?,
                last_event_type = ?,
                updated_at = NOW(6)
            WHERE branch_uuid = ?
              AND aggregate_type = ?
              AND aggregate_uuid = ?
              AND last_event_version < ?
        ");
        $stmt->bind_param(
            'issssssi',
            $effectiveRevision,
            $payloadHash,
            $eventUuid,
            $eventType,
            $branchUuid,
            $aggregateType,
            $aggregateUuid,
            $effectiveRevision
        );
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        if ($affectedRows !== 1) {
            throw new RuntimeException('Sync projection cursor was not advanced after apply.');
        }
    }

    public function effectiveRevision(array $event): int
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        $table = is_array($payload['table'] ?? null) ? $payload['table'] : [];
        $menuItem = is_array($payload['menu_item'] ?? null) ? $payload['menu_item'] : [];

        $candidates = [
            $event['event_version'] ?? 1,
            $payload['sync_revision'] ?? null,
            $payload['menu_version'] ?? null,
            $order['sync_revision'] ?? null,
            $table['sync_revision'] ?? null,
            $menuItem['sync_revision'] ?? null,
            $menuItem['menu_version'] ?? null,
        ];

        $revision = 1;
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                $revision = max($revision, (int) $candidate);
            }
        }

        return $revision;
    }

    private function aggregateType(array $event): string
    {
        $aggregateType = trim((string) ($event['aggregate_type'] ?? ''));
        if (in_array($aggregateType, array_values(self::CORE_SNAPSHOT_TYPES), true)) {
            return $aggregateType;
        }

        return self::CORE_SNAPSHOT_TYPES[$this->snapshotType($event)] ?? '';
    }

    private function snapshotType(array $event): string
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        return trim((string) ($payload['snapshot_type'] ?? $event['snapshot_type'] ?? ''));
    }

    private function payloadHash(array $event): string
    {
        $hash = trim((string) ($event['payload_hash'] ?? ''));
        if ($hash !== '') {
            return $hash;
        }

        $json = json_encode($event['payload'] ?? $event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to hash sync projection payload.');
        }

        return hash('sha256', $json);
    }

    private function decision(
        string $decision,
        int $effectiveRevision,
        int $storedRevision,
        string $storedHash,
        string $message
    ): array {
        return [
            'decision' => $decision,
            'effective_revision' => max(1, $effectiveRevision),
            'stored_revision' => max(0, $storedRevision),
            'stored_payload_hash' => $storedHash,
            'message' => $message,
        ];
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
