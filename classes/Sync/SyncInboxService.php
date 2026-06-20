<?php

require_once __DIR__ . '/CloudMenuSnapshotService.php';
require_once __DIR__ . '/CloudOperationalMirrorService.php';
require_once __DIR__ . '/CloudOrderSnapshotService.php';
require_once __DIR__ . '/CloudLegacyPosMirrorService.php';
require_once __DIR__ . '/CloudShiftSnapshotService.php';
require_once __DIR__ . '/CloudTableSnapshotService.php';

class SyncInboxService
{
    public function receiveBranchEvent(mysqli $conn, string $branchUuid, array $event, string $mode, array $config = []): array
    {
        $eventUuid = trim((string) ($event['event_uuid'] ?? ''));
        $idempotencyKey = trim((string) ($event['idempotency_key'] ?? ''));
        if ($eventUuid === '' || $idempotencyKey === '') {
            throw new InvalidArgumentException('event_uuid and idempotency_key are required.');
        }

        $payloadHash = $this->payloadHash($event);
        $payloadJson = $this->encodeJson($event);
        $sourceSystem = trim((string) ($event['source_system'] ?? 'pos'));
        if ($sourceSystem === '') {
            $sourceSystem = 'pos';
        }

        $conn->begin_transaction();
        try {
            $existing = $this->findForUpdate($conn, $branchUuid, $idempotencyKey);
            if ($existing) {
                if ((string) $existing['payload_hash'] === $payloadHash) {
                    $result = $this->duplicateResult($eventUuid, $idempotencyKey, $mode);
                    $this->updateInboxResult($conn, (int) $existing['id'], 'duplicate', $result, null);
                    $conn->commit();
                    return $result;
                }

                $result = $this->conflictResult($eventUuid, $idempotencyKey, 'idempotency hash mismatch');
                $this->insertConflict($conn, $branchUuid, $event, (string) $existing['payload_json'], $payloadJson);
                $this->updateInboxResult($conn, (int) $existing['id'], 'conflict', $result, 'idempotency hash mismatch');
                $conn->commit();
                return $result;
            }

            $stmt = $conn->prepare("
                INSERT INTO sync_inbox (
                    event_uuid,
                    branch_uuid,
                    direction,
                    source_system,
                    idempotency_key,
                    payload_hash,
                    payload_json,
                    status
                ) VALUES (?, ?, 'branch_to_cloud', ?, ?, ?, ?, 'received')
            ");
            $stmt->bind_param('ssssss', $eventUuid, $branchUuid, $sourceSystem, $idempotencyKey, $payloadHash, $payloadJson);
            $stmt->execute();
            $inboxId = (int) $conn->insert_id;
            $stmt->close();

            $cloudEntityId = $this->cloudEntityId($event);
            $message = $mode . ' receive';
            if ($mode !== SyncApplyMode::RECEIVE_ONLY) {
                $legacyMirror = null;
                $snapshot = (new CloudOrderSnapshotService())->upsertFromBranchEvent($conn, $branchUuid, $event);
                if ($snapshot) {
                    $cloudEntityId = 'cloud_order:' . (int) $snapshot['cloud_order_id'];
                    $message = $mode . ' order snapshot';
                    $legacyMirror = $this->mirrorLegacyPosTables($conn, $branchUuid, $event, $config);
                } else {
                    $tableSnapshot = (new CloudTableSnapshotService())->upsertFromBranchEvent($conn, $branchUuid, $event);
                    if ($tableSnapshot) {
                        $cloudEntityId = 'cloud_table:' . (int) $tableSnapshot['cloud_table_id'];
                        $message = $mode . ' table snapshot';
                        $legacyMirror = $this->mirrorLegacyPosTables($conn, $branchUuid, $event, $config);
                    } else {
                        $shiftSnapshot = (new CloudShiftSnapshotService())->upsertFromBranchEvent($conn, $branchUuid, $event);
                        if ($shiftSnapshot) {
                            $cloudEntityId = 'cloud_shift:' . (int) $shiftSnapshot['cloud_shift_id'];
                            $message = $mode . ' shift snapshot';
                        } else {
                            $menuSnapshot = (new CloudMenuSnapshotService())->upsertFromBranchEvent($conn, $branchUuid, $event);
                            if ($menuSnapshot) {
                                $cloudEntityId = 'cloud_menu_item:' . (int) $menuSnapshot['cloud_menu_item_id'];
                                $message = $mode . ' menu snapshot';
                                $legacyMirror = $this->mirrorLegacyPosTables($conn, $branchUuid, $event, $config);
                            } else {
                                $operational = (new CloudOperationalMirrorService())->applyFromBranchEvent($conn, $branchUuid, $event);
                                if ($operational && !empty($operational['entity_id'])) {
                                    $cloudEntityId = (string) $operational['entity_id'];
                                    $message = $mode . ' operational snapshot';
                                }
                            }
                        }
                    }
                }
                if ($legacyMirror && isset($legacyMirror['legacy_entity_id'])) {
                    $message .= ' + legacy POS mirror';
                }
            }

            $result = SyncApplyMode::acceptedResult(
                $mode,
                $eventUuid,
                $idempotencyKey,
                $cloudEntityId,
                $message
            );
            $inboxStatus = $mode === SyncApplyMode::RECEIVE_ONLY ? 'received' : 'processed';
            $this->updateInboxResult($conn, $inboxId, $inboxStatus, $result, null);

            $conn->commit();
            return $result;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    private function mirrorLegacyPosTables(mysqli $conn, string $branchUuid, array $event, array $config): ?array
    {
        if (empty($config['sync']['legacy_pos_mirror_enabled'])) {
            return null;
        }

        return (new CloudLegacyPosMirrorService())->mirrorFromBranchEvent($conn, $branchUuid, $event);
    }

    private function findForUpdate(mysqli $conn, string $branchUuid, string $idempotencyKey): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM sync_inbox
            WHERE branch_uuid = ?
              AND direction = 'branch_to_cloud'
              AND idempotency_key = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('ss', $branchUuid, $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function updateInboxResult(mysqli $conn, int $inboxId, string $status, array $result, ?string $errorMessage): void
    {
        $resultJson = $this->encodeJson($result);
        $processedAtSql = $status === 'received' ? 'NULL' : 'NOW(6)';

        $stmt = $conn->prepare("
            UPDATE sync_inbox
            SET status = ?,
                result_json = ?,
                error_message = ?,
                processed_at = {$processedAtSql}
            WHERE id = ?
        ");
        $stmt->bind_param('sssi', $status, $resultJson, $errorMessage, $inboxId);
        $stmt->execute();
        $stmt->close();
    }

    private function insertConflict(
        mysqli $conn,
        string $branchUuid,
        array $event,
        string $localPayloadJson,
        string $remotePayloadJson
    ): void {
        $aggregateType = $this->nullableString($event['aggregate_type'] ?? null);
        $aggregateUuid = $this->nullableString($event['aggregate_uuid'] ?? null);
        $remoteEntityId = $this->nullableString($event['event_uuid'] ?? null);

        $stmt = $conn->prepare("
            INSERT INTO sync_conflicts (
                branch_uuid,
                conflict_type,
                aggregate_type,
                aggregate_uuid,
                remote_entity_id,
                local_payload_json,
                remote_payload_json,
                resolution_status
            ) VALUES (?, 'idempotency_hash_mismatch', ?, ?, ?, ?, ?, 'open')
        ");
        $stmt->bind_param(
            'ssssss',
            $branchUuid,
            $aggregateType,
            $aggregateUuid,
            $remoteEntityId,
            $localPayloadJson,
            $remotePayloadJson
        );
        $stmt->execute();
        $stmt->close();
    }

    private function duplicateResult(string $eventUuid, string $idempotencyKey, string $mode): array
    {
        return [
            'event_uuid' => $eventUuid,
            'idempotency_key' => $idempotencyKey,
            'status' => 'duplicate',
            'stored' => true,
            'applied' => false,
            'report_trusted' => $mode === SyncApplyMode::LIVE_APPLY,
            'cloud_entity_id' => null,
            'message' => 'duplicate idempotency key',
        ];
    }

    private function conflictResult(string $eventUuid, string $idempotencyKey, string $message): array
    {
        return [
            'event_uuid' => $eventUuid,
            'idempotency_key' => $idempotencyKey,
            'status' => 'conflict',
            'stored' => false,
            'applied' => false,
            'report_trusted' => false,
            'cloud_entity_id' => null,
            'message' => $message,
        ];
    }

    private function payloadHash(array $event): string
    {
        $hash = trim((string) ($event['payload_hash'] ?? ''));
        if ($hash !== '') {
            return $hash;
        }

        return hash('sha256', $this->encodeJson($event['payload'] ?? $event));
    }

    private function cloudEntityId(array $event): ?string
    {
        $aggregateUuid = $this->nullableString($event['aggregate_uuid'] ?? null);
        if ($aggregateUuid !== null) {
            return 'cloud-' . substr($aggregateUuid, 0, 12);
        }

        $eventUuid = $this->nullableString($event['event_uuid'] ?? null);
        return $eventUuid === null ? null : 'cloud-' . substr($eventUuid, 0, 12);
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode sync payload JSON.');
        }

        return $json;
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
