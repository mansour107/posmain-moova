<?php

declare(strict_types=1);

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryLedgerService.php';
require_once __DIR__ . '/../Maintenance/DataRepairRunLedger.php';
require_once dirname(__DIR__, 2) . '/includes/pos_operational_store.php';

/**
 * Compensates legacy fat_details movements that were replayed after the same
 * fat_detail had already been posted by InventoryInvoiceBridge.
 *
 * Original and canonical movements remain immutable. The compensating movement
 * is posted in the scope that currently carries the duplicated quantity:
 * - the operational store after an audited store-scope reclassification; or
 * - the original migration store when no reclassification exists.
 */
final class InventoryDuplicateFatBridgeNeutralizationService
{
    private const REPAIR_TYPE = 'inventory_duplicate_fat_bridge_neutralization';

    private InventoryLedgerService $ledger;

    public function __construct(?InventoryLedgerService $ledger = null)
    {
        $this->ledger = $ledger ?: new InventoryLedgerService();
    }

    public function plan(mysqli $conn, array $options = []): array
    {
        if (!posmain_single_store_mode_enabled()) {
            throw new RuntimeException('INVENTORY_DUPLICATE_FAT_REPAIR_REQUIRES_SINGLE_STORE_MODE');
        }
        $operationalStoreId = (int) ($options['operational_store_id'] ?? posmain_operational_store_id($conn));
        if ($operationalStoreId < 1) {
            throw new RuntimeException('INVENTORY_DUPLICATE_FAT_REPAIR_OPERATIONAL_STORE_REQUIRED');
        }

        $entries = [];
        $blockers = [];
        $skipped = [];
        foreach ($this->overlapRows($conn) as $row) {
            $canonicalCount = (int) ($row['canonical_count'] ?? 0);
            $identity = [
                'fat_detail_id' => (int) ($row['fat_detail_id'] ?? 0),
                'migration_movement_id' => (int) ($row['migration_movement_id'] ?? 0),
                'item_id' => (int) ($row['item_id'] ?? 0),
                'pos_tenant' => (int) ($row['pos_tenant'] ?? 0),
                'pos_branch' => (int) ($row['pos_branch'] ?? 0),
                'migration_store_id' => (int) ($row['migration_store_id'] ?? 0),
            ];
            if ($canonicalCount !== 1) {
                $blockers[] = $identity + [
                    'code' => 'duplicate_fat_bridge_canonical_match_not_unique',
                    'canonical_count' => $canonicalCount,
                    'canonical_movement_ids' => (string) ($row['canonical_movement_ids'] ?? ''),
                ];
                continue;
            }
            if ((int) ($row['track_stock'] ?? 1) !== 1) {
                $skipped[] = $identity + [
                    'code' => 'non_stock_overlap_governed_by_non_stock_ledger_neutralization',
                ];
                continue;
            }

            $qtyIn = InventoryDecimal::normalize($row['qty_in'] ?? '0');
            $qtyOut = InventoryDecimal::normalize($row['qty_out'] ?? '0');
            $hasIn = InventoryDecimal::isPositive($qtyIn);
            $hasOut = InventoryDecimal::isPositive($qtyOut);
            if ($hasIn === $hasOut) {
                $blockers[] = $identity + ['code' => 'duplicate_fat_bridge_ambiguous_quantity_direction'];
                continue;
            }
            $unitCost = InventoryDecimal::normalize($row['unit_cost'] ?? '0');
            $totalCost = InventoryDecimal::normalize($row['total_cost'] ?? '0');
            if (InventoryDecimal::compare($unitCost, '0') < 0 || InventoryDecimal::compare($totalCost, '0') < 0) {
                $blockers[] = $identity + ['code' => 'duplicate_fat_bridge_negative_cost'];
                continue;
            }

            $migrationStoreId = (int) $identity['migration_store_id'];
            $wasReclassified = $migrationStoreId !== $operationalStoreId
                && $this->scopeReclassificationTargetExists($conn, $row, $operationalStoreId);
            $correctionStoreId = $wasReclassified ? $operationalStoreId : $migrationStoreId;
            if ($correctionStoreId < 1) {
                $blockers[] = $identity + ['code' => 'duplicate_fat_bridge_correction_store_missing'];
                continue;
            }

            $entries[] = $identity + [
                'branch_uuid' => $this->nullableString($row['branch_uuid'] ?? null),
                'canonical_movement_id' => (int) ($row['canonical_movement_id'] ?? 0),
                'canonical_store_id' => (int) ($row['canonical_store_id'] ?? 0),
                'correction_store_id' => $correctionStoreId,
                'scope_reclassified' => $wasReclassified,
                'original_direction' => $hasIn ? 'in' : 'out',
                'quantity' => $hasIn ? $qtyIn : $qtyOut,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'migration_idempotency_key' => (string) ($row['migration_idempotency_key'] ?? ''),
                'canonical_idempotency_key' => (string) ($row['canonical_idempotency_key'] ?? ''),
            ];
        }

        usort($entries, static fn(array $left, array $right): int => [
            $left['pos_tenant'],
            $left['pos_branch'],
            $left['fat_detail_id'],
            $left['migration_movement_id'],
        ] <=> [
            $right['pos_tenant'],
            $right['pos_branch'],
            $right['fat_detail_id'],
            $right['migration_movement_id'],
        ]);
        usort($blockers, static fn(array $left, array $right): int => strcmp(
            (string) $left['code'] . ':' . $left['fat_detail_id'] . ':' . $left['migration_movement_id'],
            (string) $right['code'] . ':' . $right['fat_detail_id'] . ':' . $right['migration_movement_id']
        ));

        $manifest = [
            'repair_type' => self::REPAIR_TYPE,
            'operational_store_id' => $operationalStoreId,
            'entries' => $entries,
            'skipped' => $skipped,
            'blockers' => $blockers,
        ];

        return [
            'ok' => !$blockers,
            'mode' => 'plan',
            'manifest_hash' => hash('sha256', $this->canonicalJson($manifest)),
            'operational_store_id' => $operationalStoreId,
            'summary' => [
                'entry_count' => count($entries),
                'movement_count' => count($entries),
                'skipped_count' => count($skipped),
                'blocker_count' => count($blockers),
            ],
            'entries' => $entries,
            'skipped' => $skipped,
            'blockers' => $blockers,
        ];
    }

    public function rehearse(mysqli $conn, array $options = []): array
    {
        $conn->begin_transaction();
        try {
            $plan = $this->plan($conn, $options);
            $this->assertRunnable($plan);
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
            throw new RuntimeException('INVENTORY_DUPLICATE_FAT_REPAIR_REVIEWED_MANIFEST_REQUIRED');
        }
        $backupPath = trim($backupPath);
        if ($backupPath === '' || !is_file($backupPath) || !is_readable($backupPath) || filesize($backupPath) < 1) {
            throw new RuntimeException('INVENTORY_DUPLICATE_FAT_REPAIR_READABLE_BACKUP_REQUIRED');
        }

        $runLedger = new DataRepairRunLedger();
        $prior = $runLedger->find($conn, self::REPAIR_TYPE, $reviewedManifestHash);
        if ($prior !== null) {
            $prior['replayed'] = true;

            return $prior;
        }

        $conn->begin_transaction();
        try {
            $this->lockRepairState($conn);
            $plan = $this->plan($conn, $options);
            $this->assertRunnable($plan);
            if (!hash_equals((string) $plan['manifest_hash'], $reviewedManifestHash)) {
                throw new RuntimeException('INVENTORY_DUPLICATE_FAT_REPAIR_MANIFEST_CHANGED');
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
        $movements = [];
        foreach ($plan['entries'] as $entry) {
            $fatDetailId = (int) $entry['fat_detail_id'];
            $direction = (string) $entry['original_direction'];
            $movement = [
                'scope' => [
                    'pos_tenant' => (int) $entry['pos_tenant'],
                    'pos_branch' => (int) $entry['pos_branch'],
                    'branch_uuid' => $entry['branch_uuid'],
                    'store_id' => (int) $entry['correction_store_id'],
                ],
                'item_id' => (int) $entry['item_id'],
                'movement_type' => 'adjustment',
                'source_type' => 'fat_details',
                'source_id' => $fatDetailId,
                'source_uuid' => 'legacy-fat-details:' . $fatDetailId . ':duplicate-neutralization',
                'fat_detail_id' => $fatDetailId,
                'qty_in' => $direction === 'out' ? (string) $entry['quantity'] : '0.000000',
                'qty_out' => $direction === 'in' ? (string) $entry['quantity'] : '0.000000',
                'unit_cost' => (string) $entry['unit_cost'],
                'total_cost' => (string) $entry['total_cost'],
                'idempotency_key' => 'migration:fat_details:duplicate-neutralization:' . $fatDetailId . ':v1',
                'metadata' => [
                    'repair_type' => self::REPAIR_TYPE,
                    'reviewed_manifest_hash' => (string) $plan['manifest_hash'],
                    'reason' => 'neutralize_duplicate_historical_replay_after_canonical_invoice_bridge',
                    'migration_movement_id' => (int) $entry['migration_movement_id'],
                    'canonical_movement_id' => (int) $entry['canonical_movement_id'],
                    'migration_store_id' => (int) $entry['migration_store_id'],
                    'canonical_store_id' => (int) $entry['canonical_store_id'],
                    'correction_store_id' => (int) $entry['correction_store_id'],
                    'scope_reclassified' => !empty($entry['scope_reclassified']),
                ],
            ];
            $recorded = $this->ledger->recordMovement($conn, $movement, [
                'id' => (int) $entry['item_id'],
                'item_id' => (int) $entry['item_id'],
                'item_type' => 'ingredient',
                'track_stock' => 1,
            ], [
                'manage_transaction' => false,
                'enforce_negative_policy' => false,
            ]);
            $movements[] = [
                'movement_id' => (int) ($recorded['movement_id'] ?? 0),
                'fat_detail_id' => $fatDetailId,
                'item_id' => (int) $entry['item_id'],
                'store_id' => (int) $entry['correction_store_id'],
                'quantity' => (string) $entry['quantity'],
                'total_cost' => (string) $entry['total_cost'],
                'idempotent_replay' => !empty($recorded['idempotent_replay']),
            ];
        }

        return [
            'ok' => true,
            'mode' => $rehearsal ? 'rehearse' : 'apply',
            'manifest_hash' => (string) $plan['manifest_hash'],
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

    private function overlapRows(mysqli $conn): array
    {
        $sql = "
SELECT
  mig.id AS migration_movement_id,
  mig.fat_detail_id,
  mig.item_id,
  mig.pos_tenant,
  mig.pos_branch,
  mig.branch_uuid,
  mig.store_id AS migration_store_id,
  mig.qty_in,
  mig.qty_out,
  mig.unit_cost,
  mig.total_cost,
  mig.idempotency_key AS migration_idempotency_key,
  COALESCE(i.track_stock, 1) AS track_stock,
  COUNT(canon.id) AS canonical_count,
  MIN(canon.id) AS canonical_movement_id,
  MIN(canon.store_id) AS canonical_store_id,
  MIN(canon.idempotency_key) AS canonical_idempotency_key,
  GROUP_CONCAT(canon.id ORDER BY canon.id SEPARATOR ',') AS canonical_movement_ids
FROM inventory_movements mig
JOIN inventory_movements canon
  ON canon.fat_detail_id = mig.fat_detail_id
 AND canon.item_id = mig.item_id
 AND canon.pos_tenant = mig.pos_tenant
 AND canon.pos_branch = mig.pos_branch
 AND canon.qty_in = mig.qty_in
 AND canon.qty_out = mig.qty_out
 AND canon.id <> mig.id
 AND canon.idempotency_key LIKE 'inventory-invoice-bridge:%'
LEFT JOIN myitems i ON i.id = mig.item_id
WHERE mig.source_type = 'fat_details'
  AND mig.fat_detail_id IS NOT NULL
  AND mig.idempotency_key LIKE 'migration:fat_details:%'
  AND mig.idempotency_key NOT LIKE 'migration:fat_details:duplicate-neutralization:%'
  AND NOT EXISTS (
      SELECT 1
      FROM inventory_movements repair
      WHERE repair.pos_tenant = mig.pos_tenant
        AND repair.pos_branch = mig.pos_branch
        AND repair.idempotency_key = CONCAT('migration:fat_details:duplicate-neutralization:', mig.fat_detail_id, ':v1')
  )
GROUP BY
  mig.id,
  mig.fat_detail_id,
  mig.item_id,
  mig.pos_tenant,
  mig.pos_branch,
  mig.branch_uuid,
  mig.store_id,
  mig.qty_in,
  mig.qty_out,
  mig.unit_cost,
  mig.total_cost,
  mig.idempotency_key,
  i.track_stock
ORDER BY mig.pos_tenant, mig.pos_branch, mig.fat_detail_id, mig.id";

        return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    private function scopeReclassificationTargetExists(mysqli $conn, array $row, int $operationalStoreId): bool
    {
        $suffix = ':' . (int) $row['pos_tenant']
            . ':' . (int) $row['pos_branch']
            . ':' . (int) $row['migration_store_id']
            . ':' . (int) $row['item_id']
            . ':target-%';
        $stmt = $conn->prepare("
SELECT id
FROM inventory_movements
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND idempotency_key LIKE CONCAT('scope-reclass:%', ?)
LIMIT 1");
        $tenant = (int) $row['pos_tenant'];
        $branch = (int) $row['pos_branch'];
        $stmt->bind_param('iiis', $tenant, $branch, $operationalStoreId, $suffix);
        $stmt->execute();
        $match = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return is_array($match);
    }

    private function lockRepairState(mysqli $conn): void
    {
        $conn->query("
SELECT id
FROM inventory_movements
WHERE idempotency_key LIKE 'migration:fat_details:%'
   OR idempotency_key LIKE 'inventory-invoice-bridge:%'
   OR idempotency_key LIKE 'scope-reclass:%'
ORDER BY id
FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        $conn->query('SELECT id FROM inventory_item_balances ORDER BY pos_tenant, pos_branch, store_id, item_id FOR UPDATE')
            ->fetch_all(MYSQLI_ASSOC);
    }

    private function assertRunnable(array $plan): void
    {
        if (empty($plan['ok']) || !empty($plan['blockers'])) {
            throw new RuntimeException('INVENTORY_DUPLICATE_FAT_REPAIR_PLAN_BLOCKED');
        }
    }

    private function nullableString($value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
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

        return (string) json_encode(
            $normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
