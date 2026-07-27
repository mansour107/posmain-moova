<?php

declare(strict_types=1);

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryLedgerService.php';
require_once __DIR__ . '/../Maintenance/DataRepairRunLedger.php';
require_once dirname(__DIR__, 2) . '/includes/pos_operational_store.php';

/**
 * Repairs historical single-store ledger rows that were posted under the wrong
 * store without editing or deleting the immutable movements.
 *
 * Every repair is a value-neutral pair:
 * - positive source balance: transfer_out(source), transfer_in(operational)
 * - negative source balance: transfer_in(source), transfer_out(operational)
 */
final class InventoryStoreScopeReclassificationService
{
    private const REPAIR_TYPE = 'inventory_store_scope_reclassification';

    private InventoryLedgerService $ledger;

    public function __construct(?InventoryLedgerService $ledger = null)
    {
        $this->ledger = $ledger ?: new InventoryLedgerService();
    }

    public function plan(mysqli $conn, array $options = []): array
    {
        if (!posmain_single_store_mode_enabled()) {
            throw new RuntimeException('INVENTORY_SCOPE_RECLASSIFICATION_REQUIRES_SINGLE_STORE_MODE');
        }

        $operationalStoreId = (int) ($options['operational_store_id'] ?? posmain_operational_store_id($conn));
        if ($operationalStoreId < 1) {
            throw new RuntimeException('INVENTORY_SCOPE_RECLASSIFICATION_OPERATIONAL_STORE_REQUIRED');
        }

        $allowedSourceStores = $this->sourceStoreIds($options['source_store_ids'] ?? []);
        if (!$allowedSourceStores) {
            throw new RuntimeException('INVENTORY_SCOPE_RECLASSIFICATION_SOURCE_STORES_REQUIRED');
        }
        if (in_array($operationalStoreId, $allowedSourceStores, true)) {
            throw new RuntimeException('INVENTORY_SCOPE_RECLASSIFICATION_SOURCE_EQUALS_TARGET');
        }

        $entries = [];
        $blockers = [];
        foreach ($this->sourceBalances($conn, $operationalStoreId, $allowedSourceStores) as $row) {
            $qty = InventoryDecimal::normalize($row['qty_on_hand'] ?? '0');
            $reserved = InventoryDecimal::normalize($row['qty_reserved'] ?? '0');
            $ledgerQty = InventoryDecimal::normalize($row['ledger_qty'] ?? '0');
            if (InventoryDecimal::compare($reserved, '0') !== 0) {
                $blockers[] = $this->blocker('source_balance_has_reserved_quantity', $row);
                continue;
            }
            if (InventoryDecimal::compare($qty, $ledgerQty) !== 0) {
                $blockers[] = $this->blocker('source_ledger_balance_mismatch', $row, [
                    'balance_qty' => $qty,
                    'ledger_qty' => $ledgerQty,
                ]);
                continue;
            }
            if (InventoryDecimal::compare($qty, '0') === 0) {
                continue;
            }
            if ((int) ($row['track_stock'] ?? 1) !== 1) {
                $blockers[] = $this->blocker('source_item_is_not_stock_tracked', $row);
                continue;
            }

            $direction = InventoryDecimal::compare($qty, '0') > 0 ? 'positive_to_target' : 'negative_to_target';
            $absoluteQty = $direction === 'positive_to_target'
                ? $qty
                : InventoryDecimal::subtract('0', $qty);
            $unitCost = InventoryDecimal::normalize($row['moving_average_cost'] ?? '0');
            if (InventoryDecimal::compare($unitCost, '0') < 0) {
                $blockers[] = $this->blocker('source_balance_has_negative_cost', $row);
                continue;
            }

            $entries[] = [
                'pos_tenant' => (int) $row['pos_tenant'],
                'pos_branch' => (int) $row['pos_branch'],
                'branch_uuid' => $this->nullableString($row['branch_uuid'] ?? null),
                'source_store_id' => (int) $row['store_id'],
                'target_store_id' => $operationalStoreId,
                'item_id' => (int) $row['item_id'],
                'item_name' => (string) ($row['item_name'] ?? ''),
                'direction' => $direction,
                'quantity' => $absoluteQty,
                'unit_cost' => $unitCost,
                'total_cost' => InventoryDecimal::multiply($absoluteQty, $unitCost),
                'source_ledger_qty_before' => $ledgerQty,
                'source_balance_qty_before' => $qty,
                'source_reserved_qty_before' => $reserved,
                'target_balance_qty_before' => InventoryDecimal::normalize($row['target_qty_on_hand'] ?? '0'),
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            return [
                $left['pos_tenant'],
                $left['pos_branch'],
                $left['source_store_id'],
                $left['item_id'],
            ] <=> [
                $right['pos_tenant'],
                $right['pos_branch'],
                $right['source_store_id'],
                $right['item_id'],
            ];
        });
        usort($blockers, static fn(array $left, array $right): int => strcmp(
            $left['code'] . ':' . $left['pos_tenant'] . ':' . $left['pos_branch'] . ':' . $left['store_id'] . ':' . $left['item_id'],
            $right['code'] . ':' . $right['pos_tenant'] . ':' . $right['pos_branch'] . ':' . $right['store_id'] . ':' . $right['item_id']
        ));

        $manifest = [
            'repair_type' => self::REPAIR_TYPE,
            'operational_store_id' => $operationalStoreId,
            'source_store_ids' => $allowedSourceStores,
            'entries' => $entries,
            'blockers' => $blockers,
        ];
        $manifestHash = hash('sha256', $this->canonicalJson($manifest));

        return [
            'ok' => !$blockers,
            'mode' => 'plan',
            'manifest_hash' => $manifestHash,
            'operational_store_id' => $operationalStoreId,
            'source_store_ids' => $allowedSourceStores,
            'summary' => [
                'entry_count' => count($entries),
                'movement_count' => count($entries) * 2,
                'blocker_count' => count($blockers),
            ],
            'entries' => $entries,
            'blockers' => $blockers,
        ];
    }

    public function rehearse(mysqli $conn, array $options = []): array
    {
        $conn->begin_transaction();
        try {
            $plan = $this->plan($conn, $options);
            $this->assertPlanRunnable($plan);
            $result = $this->run($conn, $plan, true);
            $conn->rollback();

            return $result;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    public function apply(
        mysqli $conn,
        array $options,
        string $reviewedManifestHash,
        string $backupPath
    ): array {
        $reviewedManifestHash = strtolower(trim($reviewedManifestHash));
        if (!preg_match('/^[a-f0-9]{64}$/', $reviewedManifestHash)) {
            throw new RuntimeException('INVENTORY_SCOPE_RECLASSIFICATION_REVIEWED_MANIFEST_REQUIRED');
        }
        $backupPath = trim($backupPath);
        if ($backupPath === '' || !is_file($backupPath) || !is_readable($backupPath) || filesize($backupPath) < 1) {
            throw new RuntimeException('INVENTORY_SCOPE_RECLASSIFICATION_READABLE_BACKUP_REQUIRED');
        }

        $runLedger = new DataRepairRunLedger();
        $prior = $runLedger->find($conn, self::REPAIR_TYPE, $reviewedManifestHash);
        if ($prior !== null) {
            $prior['replayed'] = true;

            return $prior;
        }

        $conn->begin_transaction();
        try {
            $this->lockAffectedBalances($conn, $options);
            $plan = $this->plan($conn, $options);
            $this->assertPlanRunnable($plan);
            if (!hash_equals((string) $plan['manifest_hash'], $reviewedManifestHash)) {
                throw new RuntimeException('INVENTORY_SCOPE_RECLASSIFICATION_MANIFEST_CHANGED');
            }
            $result = $this->run($conn, $plan, false);
            $result['backup_path'] = realpath($backupPath) ?: $backupPath;
            $result['backup_sha256'] = hash_file('sha256', $backupPath);
            $result['replayed'] = false;
            $runLedger->record($conn, self::REPAIR_TYPE, $reviewedManifestHash, $result);
            $conn->commit();

            return $result;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    private function run(mysqli $conn, array $plan, bool $rehearsal): array
    {
        $manifestHash = (string) $plan['manifest_hash'];
        $movements = [];
        foreach ($plan['entries'] as $entry) {
            $groupUuid = $this->uuidFromHash(hash('sha256', $manifestHash . ':' . $entry['pos_tenant'] . ':' . $entry['pos_branch'] . ':' . $entry['source_store_id'] . ':' . $entry['item_id']));
            $baseMetadata = [
                'repair_type' => self::REPAIR_TYPE,
                'reviewed_manifest_hash' => $manifestHash,
                'source_store_id' => (int) $entry['source_store_id'],
                'target_store_id' => (int) $entry['target_store_id'],
                'direction' => (string) $entry['direction'],
                'reason' => 'historical_single_store_scope_correction',
            ];
            $pair = $this->movementPair($entry, $manifestHash, $groupUuid, $baseMetadata);
            foreach ($pair as $movement) {
                $item = [
                    'id' => (int) $entry['item_id'],
                    'item_id' => (int) $entry['item_id'],
                    'item_type' => 'ingredient',
                    'track_stock' => 1,
                ];
                $recorded = $this->ledger->recordMovement($conn, $movement, $item, [
                    'manage_transaction' => false,
                    'enforce_negative_policy' => false,
                ]);
                $movements[] = [
                    'movement_id' => (int) ($recorded['movement_id'] ?? 0),
                    'store_id' => (int) $movement['scope']['store_id'],
                    'item_id' => (int) $entry['item_id'],
                    'movement_type' => (string) $movement['movement_type'],
                    'quantity' => (string) $entry['quantity'],
                    'total_cost' => (string) $entry['total_cost'],
                    'idempotent_replay' => !empty($recorded['idempotent_replay']),
                ];
            }
        }

        return [
            'ok' => true,
            'mode' => $rehearsal ? 'rehearse' : 'apply',
            'manifest_hash' => $manifestHash,
            'summary' => [
                'entry_count' => count($plan['entries']),
                'movement_count' => count($movements),
                'rehearsed_movement_count' => $rehearsal ? count($movements) : 0,
                'applied_movement_count' => $rehearsal ? 0 : count($movements),
            ],
            'movements' => $movements,
            'blockers' => [],
        ];
    }

    private function movementPair(array $entry, string $manifestHash, string $groupUuid, array $metadata): array
    {
        $scopeBase = [
            'pos_tenant' => (int) $entry['pos_tenant'],
            'pos_branch' => (int) $entry['pos_branch'],
            'branch_uuid' => $entry['branch_uuid'],
        ];
        $common = [
            'movement_group_uuid' => $groupUuid,
            'item_id' => (int) $entry['item_id'],
            'source_type' => 'manual',
            'source_uuid' => $groupUuid,
            'unit_conversion_to_base' => '1.00000000',
            'unit_cost' => (string) $entry['unit_cost'],
            'total_cost' => (string) $entry['total_cost'],
        ];
        $keyBase = 'scope-reclass:' . $manifestHash . ':' . $entry['pos_tenant'] . ':' . $entry['pos_branch'] . ':' . $entry['source_store_id'] . ':' . $entry['item_id'];

        if ($entry['direction'] === 'positive_to_target') {
            return [
                array_merge($common, [
                    'scope' => $scopeBase + ['store_id' => (int) $entry['source_store_id']],
                    'movement_type' => 'transfer_out',
                    'qty_out' => (string) $entry['quantity'],
                    'idempotency_key' => $keyBase . ':source-out',
                    'metadata' => $metadata + ['pair_role' => 'source_out'],
                ]),
                array_merge($common, [
                    'scope' => $scopeBase + ['store_id' => (int) $entry['target_store_id']],
                    'movement_type' => 'transfer_in',
                    'qty_in' => (string) $entry['quantity'],
                    'idempotency_key' => $keyBase . ':target-in',
                    'metadata' => $metadata + ['pair_role' => 'target_in'],
                ]),
            ];
        }

        return [
            array_merge($common, [
                'scope' => $scopeBase + ['store_id' => (int) $entry['source_store_id']],
                'movement_type' => 'transfer_in',
                'qty_in' => (string) $entry['quantity'],
                'idempotency_key' => $keyBase . ':source-in',
                'metadata' => $metadata + ['pair_role' => 'source_in'],
            ]),
            array_merge($common, [
                'scope' => $scopeBase + ['store_id' => (int) $entry['target_store_id']],
                'movement_type' => 'transfer_out',
                'qty_out' => (string) $entry['quantity'],
                'idempotency_key' => $keyBase . ':target-out',
                'metadata' => $metadata + ['pair_role' => 'target_out'],
            ]),
        ];
    }

    private function sourceBalances(mysqli $conn, int $operationalStoreId, array $sourceStoreIds): array
    {
        $placeholders = implode(',', array_fill(0, count($sourceStoreIds), '?'));
        $params = array_merge([$operationalStoreId], $sourceStoreIds);
        $types = str_repeat('i', count($params));
        $sql = "
SELECT
  b.pos_tenant,
  b.pos_branch,
  b.branch_uuid,
  b.store_id,
  b.item_id,
  b.qty_on_hand,
  b.qty_reserved,
  b.moving_average_cost,
  COALESCE(m.ledger_qty, 0) AS ledger_qty,
  COALESCE(target.qty_on_hand, 0) AS target_qty_on_hand,
  COALESCE(i.iname, '') AS item_name,
  COALESCE(i.track_stock, 1) AS track_stock
FROM inventory_item_balances b
LEFT JOIN (
  SELECT pos_tenant, pos_branch, store_id, item_id, SUM(qty_in - qty_out) AS ledger_qty
  FROM inventory_movements
  GROUP BY pos_tenant, pos_branch, store_id, item_id
) m
  ON m.pos_tenant = b.pos_tenant
 AND m.pos_branch = b.pos_branch
 AND m.store_id = b.store_id
 AND m.item_id = b.item_id
LEFT JOIN inventory_item_balances target
  ON target.pos_tenant = b.pos_tenant
 AND target.pos_branch = b.pos_branch
 AND target.store_id = ?
 AND target.item_id = b.item_id
LEFT JOIN myitems i ON i.id = b.item_id
WHERE b.store_id IN ({$placeholders})
  AND (b.qty_on_hand <> 0 OR b.qty_reserved <> 0 OR COALESCE(m.ledger_qty, 0) <> 0)
ORDER BY b.pos_tenant, b.pos_branch, b.store_id, b.item_id";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    private function lockAffectedBalances(mysqli $conn, array $options): void
    {
        $sourceStores = $this->sourceStoreIds($options['source_store_ids'] ?? []);
        $targetStore = (int) ($options['operational_store_id'] ?? posmain_operational_store_id($conn));
        if (!$sourceStores || $targetStore < 1) {
            return;
        }
        $storeIds = array_values(array_unique(array_merge([$targetStore], $sourceStores)));
        $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
        $types = str_repeat('i', count($storeIds));
        $stmt = $conn->prepare(
            "SELECT id FROM inventory_item_balances WHERE store_id IN ({$placeholders}) ORDER BY pos_tenant, pos_branch, store_id, item_id FOR UPDATE"
        );
        $stmt->bind_param($types, ...$storeIds);
        $stmt->execute();
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    private function assertPlanRunnable(array $plan): void
    {
        if (empty($plan['ok']) || !empty($plan['blockers'])) {
            throw new RuntimeException('INVENTORY_SCOPE_RECLASSIFICATION_PLAN_BLOCKED');
        }
    }

    private function sourceStoreIds($raw): array
    {
        if (!is_array($raw)) {
            $raw = preg_split('/,/', (string) $raw) ?: [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static fn(int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    private function blocker(string $code, array $row, array $extra = []): array
    {
        return array_merge([
            'code' => $code,
            'pos_tenant' => (int) ($row['pos_tenant'] ?? 0),
            'pos_branch' => (int) ($row['pos_branch'] ?? 0),
            'store_id' => (int) ($row['store_id'] ?? 0),
            'item_id' => (int) ($row['item_id'] ?? 0),
        ], $extra);
    }

    private function nullableString($value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function uuidFromHash(string $hash): string
    {
        $hex = substr($hash, 0, 32);
        $hex[12] = '4';
        $variant = hexdec($hex[16]);
        $hex[16] = dechex(($variant & 0x3) | 0x8);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private function canonicalJson(array $value): string
    {
        $normalize = static function ($entry) use (&$normalize) {
            if (!is_array($entry)) {
                return $entry;
            }
            if (array_keys($entry) !== range(0, count($entry) - 1)) {
                ksort($entry);
            }
            foreach ($entry as $key => $nested) {
                $entry[$key] = $normalize($nested);
            }

            return $entry;
        };

        return (string) json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
