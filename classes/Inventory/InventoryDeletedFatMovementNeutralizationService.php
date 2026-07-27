<?php

declare(strict_types=1);

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryLedgerService.php';
require_once __DIR__ . '/../Maintenance/DataRepairRunLedger.php';
require_once dirname(__DIR__, 2) . '/includes/pos_operational_store.php';

/**
 * Neutralizes inventory quantity that remains in the immutable ledger after
 * its source fat_details row was soft-deleted.
 *
 * The source movements are never changed or removed. One manifest-reviewed
 * compensating adjustment is added for each deleted source row whose linked
 * movement family still has a non-zero net quantity.
 */
final class InventoryDeletedFatMovementNeutralizationService
{
    private const REPAIR_TYPE = 'inventory_deleted_fat_movement_neutralization';

    private InventoryLedgerService $ledger;

    public function __construct(?InventoryLedgerService $ledger = null)
    {
        $this->ledger = $ledger ?: new InventoryLedgerService();
    }

    public function plan(mysqli $conn, array $options = []): array
    {
        if (!posmain_single_store_mode_enabled()) {
            throw new RuntimeException('INVENTORY_DELETED_FAT_REPAIR_REQUIRES_SINGLE_STORE_MODE');
        }
        $operationalStoreId = (int) ($options['operational_store_id'] ?? posmain_operational_store_id($conn));
        if ($operationalStoreId < 1) {
            throw new RuntimeException('INVENTORY_DELETED_FAT_REPAIR_OPERATIONAL_STORE_REQUIRED');
        }

        $entries = [];
        $blockers = [];
        $skipped = [];
        foreach ($this->residueRows($conn) as $row) {
            $identity = [
                'fat_detail_id' => (int) $row['fat_detail_id'],
                'item_id' => (int) $row['item_id'],
                'pos_tenant' => (int) $row['pos_tenant'],
                'pos_branch' => (int) $row['pos_branch'],
            ];
            if ((int) ($row['track_stock'] ?? 1) !== 1) {
                $skipped[] = $identity + ['code' => 'non_stock_residue_governed_by_non_stock_ledger_neutralization'];
                continue;
            }

            $netQuantity = InventoryDecimal::normalize($row['net_quantity'] ?? '0');
            if (InventoryDecimal::compare($netQuantity, '0') === 0) {
                continue;
            }
            $storeIds = array_values(array_unique(array_filter(array_map(
                'intval',
                explode(',', (string) ($row['movement_store_ids'] ?? ''))
            ))));
            $wasReclassified = false;
            foreach ($storeIds as $storeId) {
                if ($storeId !== $operationalStoreId
                    && $this->scopeReclassificationTargetExists($conn, $row, $storeId, $operationalStoreId)) {
                    $wasReclassified = true;
                }
            }
            if (!$wasReclassified && count($storeIds) !== 1) {
                $blockers[] = $identity + [
                    'code' => 'deleted_fat_residue_spans_unreconciled_stores',
                    'movement_store_ids' => $storeIds,
                ];
                continue;
            }
            $correctionStoreId = $wasReclassified ? $operationalStoreId : (int) ($storeIds[0] ?? 0);
            if ($correctionStoreId < 1) {
                $blockers[] = $identity + ['code' => 'deleted_fat_residue_correction_store_missing'];
                continue;
            }

            $signedCost = InventoryDecimal::normalize($row['signed_cost'] ?? '0');
            $absoluteQuantity = $this->absolute($netQuantity);
            $absoluteCost = $this->absolute($signedCost);
            $unitCost = InventoryDecimal::isPositive($absoluteCost)
                ? InventoryDecimal::divide($absoluteCost, $absoluteQuantity)
                : InventoryDecimal::zero();

            $entries[] = $identity + [
                'branch_uuid' => $this->nullableString($row['branch_uuid'] ?? null),
                'movement_ids' => (string) ($row['movement_ids'] ?? ''),
                'movement_store_ids' => $storeIds,
                'correction_store_id' => $correctionStoreId,
                'scope_reclassified' => $wasReclassified,
                'residue_direction' => InventoryDecimal::compare($netQuantity, '0') > 0 ? 'in' : 'out',
                'quantity' => $absoluteQuantity,
                'unit_cost' => $unitCost,
                'total_cost' => InventoryDecimal::multiply($absoluteQuantity, $unitCost),
            ];
        }

        usort($entries, static fn(array $left, array $right): int => [
            $left['pos_tenant'], $left['pos_branch'], $left['fat_detail_id'],
        ] <=> [
            $right['pos_tenant'], $right['pos_branch'], $right['fat_detail_id'],
        ]);
        usort($blockers, static fn(array $left, array $right): int => strcmp(
            (string) $left['code'] . ':' . $left['fat_detail_id'],
            (string) $right['code'] . ':' . $right['fat_detail_id']
        ));

        $manifest = [
            'repair_type' => self::REPAIR_TYPE,
            'operational_store_id' => $operationalStoreId,
            'entries' => $entries,
            'skipped' => $skipped,
            'blockers' => $blockers,
        ];

        return [
            'ok' => $blockers === [],
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

    public function apply(mysqli $conn, array $options, string $reviewedManifestHash, string $backupPath): array
    {
        $reviewedManifestHash = strtolower(trim($reviewedManifestHash));
        if (!preg_match('/^[a-f0-9]{64}$/', $reviewedManifestHash)) {
            throw new RuntimeException('INVENTORY_DELETED_FAT_REPAIR_REVIEWED_MANIFEST_REQUIRED');
        }
        $backupPath = trim($backupPath);
        if ($backupPath === '' || !is_file($backupPath) || !is_readable($backupPath) || filesize($backupPath) < 1) {
            throw new RuntimeException('INVENTORY_DELETED_FAT_REPAIR_READABLE_BACKUP_REQUIRED');
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
                throw new RuntimeException('INVENTORY_DELETED_FAT_REPAIR_MANIFEST_CHANGED');
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
            $direction = (string) $entry['residue_direction'];
            $recorded = $this->ledger->recordMovement($conn, [
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
                'source_uuid' => 'legacy-fat-details:' . $fatDetailId . ':deleted-neutralization',
                'fat_detail_id' => $fatDetailId,
                'qty_in' => $direction === 'out' ? (string) $entry['quantity'] : '0.000000',
                'qty_out' => $direction === 'in' ? (string) $entry['quantity'] : '0.000000',
                'unit_cost' => (string) $entry['unit_cost'],
                'total_cost' => (string) $entry['total_cost'],
                'idempotency_key' => 'migration:fat_details:deleted-neutralization:' . $fatDetailId . ':v1',
                'metadata' => [
                    'repair_type' => self::REPAIR_TYPE,
                    'reviewed_manifest_hash' => (string) $plan['manifest_hash'],
                    'reason' => 'neutralize_inventory_residue_for_soft_deleted_legacy_detail',
                    'source_movement_ids' => (string) $entry['movement_ids'],
                    'movement_store_ids' => $entry['movement_store_ids'],
                    'correction_store_id' => (int) $entry['correction_store_id'],
                    'scope_reclassified' => !empty($entry['scope_reclassified']),
                ],
            ], [
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

    private function residueRows(mysqli $conn): array
    {
        $sql = "
SELECT
  fd.id AS fat_detail_id,
  fd.item_id,
  COALESCE(fd.tenant, 0) AS pos_tenant,
  COALESCE(fd.branch, 0) AS pos_branch,
  MAX(COALESCE(im.branch_uuid, '')) AS branch_uuid,
  COALESCE(i.track_stock, 1) AS track_stock,
  CAST(SUM(im.qty_in - im.qty_out) AS DECIMAL(18,6)) AS net_quantity,
  CAST(SUM(CASE WHEN im.qty_in > 0 THEN im.total_cost ELSE -im.total_cost END) AS DECIMAL(18,6)) AS signed_cost,
  GROUP_CONCAT(im.id ORDER BY im.id SEPARATOR ',') AS movement_ids,
  GROUP_CONCAT(DISTINCT im.store_id ORDER BY im.store_id SEPARATOR ',') AS movement_store_ids
FROM fat_details fd
JOIN inventory_movements im
  ON im.fat_detail_id = fd.id
 AND im.item_id = fd.item_id
LEFT JOIN myitems i ON i.id = fd.item_id
WHERE COALESCE(fd.isdeleted, 0) = 1
  AND im.idempotency_key NOT LIKE 'migration:fat_details:deleted-neutralization:%'
  AND NOT EXISTS (
      SELECT 1
      FROM inventory_movements repair
      WHERE repair.pos_tenant = COALESCE(fd.tenant, 0)
        AND repair.pos_branch = COALESCE(fd.branch, 0)
        AND repair.idempotency_key = CONCAT('migration:fat_details:deleted-neutralization:', fd.id, ':v1')
  )
GROUP BY fd.id, fd.item_id, fd.tenant, fd.branch, i.track_stock
HAVING SUM(im.qty_in - im.qty_out) <> 0
ORDER BY pos_tenant, pos_branch, fat_detail_id";

        return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    private function scopeReclassificationTargetExists(
        mysqli $conn,
        array $row,
        int $sourceStoreId,
        int $operationalStoreId
    ): bool {
        $suffix = ':' . (int) $row['pos_tenant']
            . ':' . (int) $row['pos_branch']
            . ':' . $sourceStoreId
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
        $conn->query("SELECT id FROM fat_details WHERE COALESCE(isdeleted, 0) = 1 ORDER BY id FOR UPDATE")
            ->fetch_all(MYSQLI_ASSOC);
        $conn->query("SELECT id FROM inventory_movements WHERE fat_detail_id IS NOT NULL OR idempotency_key LIKE 'scope-reclass:%' ORDER BY id FOR UPDATE")
            ->fetch_all(MYSQLI_ASSOC);
        $conn->query('SELECT id FROM inventory_item_balances ORDER BY pos_tenant, pos_branch, store_id, item_id FOR UPDATE')
            ->fetch_all(MYSQLI_ASSOC);
    }

    private function assertRunnable(array $plan): void
    {
        if (empty($plan['ok']) || !empty($plan['blockers'])) {
            throw new RuntimeException('INVENTORY_DELETED_FAT_REPAIR_PLAN_BLOCKED');
        }
    }

    private function absolute(string $value): string
    {
        $value = InventoryDecimal::normalize($value);

        return strpos($value, '-') === 0
            ? InventoryDecimal::normalize(substr($value, 1))
            : $value;
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
