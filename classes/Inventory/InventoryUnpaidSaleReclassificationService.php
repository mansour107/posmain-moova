<?php

declare(strict_types=1);

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryLedgerService.php';
require_once __DIR__ . '/InventoryLegacyMirrorService.php';
require_once __DIR__ . '/../Maintenance/DataRepairRunLedger.php';
require_once dirname(__DIR__, 2) . '/includes/pos_operational_store.php';

/**
 * Corrects the historical defect where an unpaid POS draft was recorded as a
 * real sale. Source movements remain immutable: an exact inbound compensation
 * restores stock, then a neutral reservation preserves the draft commitment.
 */
final class InventoryUnpaidSaleReclassificationService
{
    private const REPAIR_TYPE = 'inventory_unpaid_sale_reclassification';

    private InventoryLedgerService $ledger;
    private InventoryLegacyMirrorService $legacyMirror;

    public function __construct(
        ?InventoryLedgerService $ledger = null,
        ?InventoryLegacyMirrorService $legacyMirror = null
    ) {
        $this->ledger = $ledger ?: new InventoryLedgerService();
        $this->legacyMirror = $legacyMirror ?: new InventoryLegacyMirrorService();
    }

    public function plan(mysqli $conn): array
    {
        foreach (['inventory_movements', 'inventory_item_balances', 'myitems', 'ot_head', 'order_payments'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                return $this->blockedPlan('required_table_missing:' . $table);
            }
        }
        foreach ([
            ['ot_head', 'payment_status'],
            ['ot_head', 'invoice_status'],
            ['ot_head', 'order_status'],
            ['ot_head', 'closed'],
            ['ot_head', 'isdeleted'],
            ['order_payments', 'order_id'],
            ['order_payments', 'amount'],
            ['myitems', 'track_stock'],
            ['myitems', 'item_type'],
        ] as $column) {
            if (!$this->columnExists($conn, $column[0], $column[1])) {
                return $this->blockedPlan('required_column_missing:' . $column[0] . '.' . $column[1]);
            }
        }

        $voidPredicate = $this->columnExists($conn, 'order_payments', 'is_voided')
            ? ' AND COALESCE(op.is_voided, 0) = 0'
            : '';
        $rows = $conn->query("
SELECT
  im.id AS source_movement_id,
  im.movement_uuid AS source_movement_uuid,
  im.pos_tenant,
  im.pos_branch,
  im.branch_uuid,
  im.store_id,
  im.item_id,
  im.source_id,
  im.source_uuid,
  im.order_id,
  im.fat_detail_id,
  im.qty_out,
  im.unit_cost,
  im.total_cost,
  im.created_at,
  COALESCE(i.track_stock, 1) AS track_stock,
  LOWER(COALESCE(i.item_type, '')) AS item_type,
  LOWER(COALESCE(oh.payment_status, '')) AS payment_status,
  COALESCE((
      SELECT SUM(op.amount)
      FROM order_payments op
      WHERE op.order_id = im.order_id{$voidPredicate}
  ), 0) AS captured_amount
FROM inventory_movements im
JOIN ot_head oh ON oh.id = im.order_id
JOIN myitems i ON i.id = im.item_id
WHERE im.movement_type = 'sale_direct'
  AND im.qty_out > 0
  AND LOWER(COALESCE(oh.payment_status, '')) IN ('unpaid', 'partial')
  AND LOWER(COALESCE(oh.invoice_status, '')) = 'draft'
  AND LOWER(COALESCE(oh.order_status, '')) IN ('draft', 'active')
  AND COALESCE(oh.closed, 0) = 0
  AND COALESCE(oh.isdeleted, 0) = 0
  AND NOT EXISTS (
      SELECT 1
      FROM inventory_movements correction
      WHERE correction.idempotency_key = CONCAT(
          'inventory-unpaid-sale-reclass:v1:', im.id, ':restore'
      )
  )
ORDER BY im.id")->fetch_all(MYSQLI_ASSOC);

        $entries = [];
        $blockers = [];
        $skipped = [];
        foreach ($rows as $row) {
            if ((int) ($row['track_stock'] ?? 1) !== 1
                || (string) ($row['item_type'] ?? '') === 'service') {
                $skipped[] = [
                    'code' => 'non_stock_source_governed_by_non_stock_neutralization',
                    'source_movement_id' => (int) $row['source_movement_id'],
                    'order_id' => (int) $row['order_id'],
                    'item_id' => (int) $row['item_id'],
                ];
                continue;
            }
            $captured = InventoryDecimal::normalize($row['captured_amount'] ?? '0', 2);
            if (InventoryDecimal::compare($captured, '0', 2) > 0) {
                $blockers[] = [
                    'code' => 'unpaid_order_has_captured_payment',
                    'source_movement_id' => (int) $row['source_movement_id'],
                    'order_id' => (int) $row['order_id'],
                    'captured_amount' => $captured,
                ];
                continue;
            }
            $quantity = InventoryDecimal::normalize($row['qty_out'] ?? '0');
            $totalCost = InventoryDecimal::normalize($row['total_cost'] ?? '0');
            $unitCost = InventoryDecimal::normalize($row['unit_cost'] ?? '0');
            if (!InventoryDecimal::isPositive($quantity)
                || InventoryDecimal::compare($totalCost, '0') < 0
                || InventoryDecimal::compare($unitCost, '0') < 0) {
                $blockers[] = [
                    'code' => 'invalid_source_sale_quantity_or_cost',
                    'source_movement_id' => (int) $row['source_movement_id'],
                ];
                continue;
            }
            $entries[] = [
                'source_movement_id' => (int) $row['source_movement_id'],
                'source_movement_uuid' => (string) $row['source_movement_uuid'],
                'pos_tenant' => (int) $row['pos_tenant'],
                'pos_branch' => (int) $row['pos_branch'],
                'branch_uuid' => $this->nullableString($row['branch_uuid'] ?? null),
                'store_id' => (int) $row['store_id'],
                'item_id' => (int) $row['item_id'],
                'source_id' => (int) ($row['source_id'] ?? 0),
                'source_uuid' => $this->nullableString($row['source_uuid'] ?? null),
                'order_id' => (int) $row['order_id'],
                'fat_detail_id' => (int) ($row['fat_detail_id'] ?? 0),
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'payment_status' => (string) $row['payment_status'],
                'source_created_at' => (string) $row['created_at'],
            ];
        }

        $manifest = [
            'repair_type' => self::REPAIR_TYPE,
            'entries' => $entries,
            'skipped' => $skipped,
            'blockers' => $blockers,
        ];

        return [
            'ok' => $blockers === [],
            'mode' => 'plan',
            'manifest_hash' => hash('sha256', $this->canonicalJson($manifest)),
            'summary' => [
                'entry_count' => count($entries),
                'movement_count' => count($entries) * 2,
                'skipped_count' => count($skipped),
                'blocker_count' => count($blockers),
            ],
            'entries' => $entries,
            'skipped' => $skipped,
            'blockers' => $blockers,
        ];
    }

    public function rehearse(mysqli $conn): array
    {
        $conn->begin_transaction();
        try {
            $plan = $this->plan($conn);
            $this->assertRunnable($plan);
            $result = $this->run($conn, $plan, true);
            $conn->rollback();

            return $result;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    public function apply(mysqli $conn, string $reviewedManifestHash, string $backupPath): array
    {
        $reviewedManifestHash = strtolower(trim($reviewedManifestHash));
        if (!preg_match('/^[a-f0-9]{64}$/', $reviewedManifestHash)) {
            throw new RuntimeException('INVENTORY_UNPAID_SALE_RECLASS_REVIEWED_MANIFEST_REQUIRED');
        }
        $backupPath = trim($backupPath);
        if ($backupPath === '' || !is_file($backupPath) || !is_readable($backupPath) || filesize($backupPath) < 1) {
            throw new RuntimeException('INVENTORY_UNPAID_SALE_RECLASS_READABLE_BACKUP_REQUIRED');
        }

        $runLedger = new DataRepairRunLedger();
        $prior = $runLedger->find($conn, self::REPAIR_TYPE, $reviewedManifestHash);
        if ($prior !== null) {
            // Replays are also a bounded compatibility-mirror self-check. This
            // repairs deployments that ran an older revision which refreshed
            // myitems from all stores instead of the configured operational
            // store, without changing immutable movement history.
            $conn->begin_transaction();
            try {
                $restoreIds = [];
                foreach (($prior['movements'] ?? []) as $movement) {
                    $id = (int) ($movement['restore_movement_id'] ?? 0);
                    if ($id > 0) {
                        $restoreIds[] = $id;
                    }
                }
                $prior['mirror_refreshed_count'] = $this->refreshMirrorsForMovementIds($conn, $restoreIds);
                $conn->commit();
            } catch (Throwable $exception) {
                $conn->rollback();
                throw $exception;
            }
            $prior['replayed'] = true;

            return $prior;
        }

        $conn->begin_transaction();
        try {
            $this->lockState($conn);
            $plan = $this->plan($conn);
            $this->assertRunnable($plan);
            if (!hash_equals((string) $plan['manifest_hash'], $reviewedManifestHash)) {
                throw new RuntimeException('INVENTORY_UNPAID_SALE_RECLASS_MANIFEST_CHANGED');
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
        $items = [];
        foreach ($plan['entries'] as $entry) {
            $baseKey = 'inventory-unpaid-sale-reclass:v1:' . $entry['source_movement_id'];
            $metadata = [
                'repair_type' => self::REPAIR_TYPE,
                'reviewed_manifest_hash' => (string) $plan['manifest_hash'],
                'source_movement_id' => (int) $entry['source_movement_id'],
                'source_movement_uuid' => (string) $entry['source_movement_uuid'],
                'reason' => 'replace_unpaid_draft_stock_depletion_with_reservation',
            ];
            $scope = [
                'pos_tenant' => (int) $entry['pos_tenant'],
                'pos_branch' => (int) $entry['pos_branch'],
                'branch_uuid' => $entry['branch_uuid'],
                'store_id' => (int) $entry['store_id'],
            ];
            $item = [
                'id' => (int) $entry['item_id'],
                'item_id' => (int) $entry['item_id'],
                'item_type' => 'ingredient',
                'track_stock' => 1,
            ];
            $restore = $this->ledger->recordMovement($conn, [
                'scope' => $scope,
                'item_id' => (int) $entry['item_id'],
                'movement_type' => 'refund_reversal',
                'source_type' => 'fat_details',
                'source_id' => (int) $entry['source_id'],
                'source_uuid' => ($entry['source_uuid'] ?: 'inventory-movement:' . $entry['source_movement_id']) . ':unpaid-restore',
                'order_id' => (int) $entry['order_id'],
                'fat_detail_id' => (int) $entry['fat_detail_id'],
                'qty_in' => (string) $entry['quantity'],
                'unit_cost' => (string) $entry['unit_cost'],
                'total_cost' => (string) $entry['total_cost'],
                'reversed_movement_id' => (int) $entry['source_movement_id'],
                'idempotency_key' => $baseKey . ':restore',
                'metadata' => $metadata + ['correction_part' => 'restore'],
            ], $item, [
                'manage_transaction' => false,
                'enforce_negative_policy' => false,
            ]);
            $reservation = $this->ledger->recordMovement($conn, [
                'scope' => $scope,
                'item_id' => (int) $entry['item_id'],
                'movement_type' => 'reservation',
                'source_type' => 'reservation',
                'source_id' => (int) $entry['source_id'],
                'source_uuid' => ($entry['source_uuid'] ?: 'inventory-movement:' . $entry['source_movement_id']) . ':unpaid-reservation',
                'order_id' => (int) $entry['order_id'],
                'fat_detail_id' => (int) $entry['fat_detail_id'],
                'qty_reserved' => (string) $entry['quantity'],
                'unit_cost' => (string) $entry['unit_cost'],
                'total_cost' => '0.000000',
                'idempotency_key' => $baseKey . ':reservation',
                'metadata' => $metadata + ['correction_part' => 'reservation'],
            ], $item, [
                'manage_transaction' => false,
                'enforce_negative_policy' => false,
            ]);
            $items[(int) $entry['item_id']] = true;
            $movements[] = [
                'source_movement_id' => (int) $entry['source_movement_id'],
                'restore_movement_id' => (int) ($restore['movement_id'] ?? 0),
                'reservation_movement_id' => (int) ($reservation['movement_id'] ?? 0),
            ];
        }
        $this->refreshItemMirrors($conn, array_keys($items));

        return [
            'ok' => true,
            'mode' => $rehearsal ? 'rehearse' : 'apply',
            'manifest_hash' => (string) $plan['manifest_hash'],
            'summary' => [
                'entry_count' => count($plan['entries']),
                'movement_count' => count($movements) * 2,
                'rehearsed_entry_count' => $rehearsal ? count($movements) : 0,
                'applied_entry_count' => $rehearsal ? 0 : count($movements),
            ],
            'movements' => $movements,
            'blockers' => [],
        ];
    }

    private function lockState(mysqli $conn): void
    {
        $conn->query("SELECT id FROM ot_head WHERE LOWER(COALESCE(payment_status,'')) IN ('unpaid','partial') ORDER BY id FOR UPDATE");
        $conn->query('SELECT id FROM order_payments ORDER BY id FOR UPDATE');
        $conn->query("SELECT id FROM inventory_movements WHERE movement_type IN ('sale_direct','refund_reversal','reservation') ORDER BY id FOR UPDATE");
        $conn->query('SELECT id FROM inventory_item_balances ORDER BY id FOR UPDATE');
    }

    private function refreshMirrorsForMovementIds(mysqli $conn, array $movementIds): int
    {
        $movementIds = array_values(array_unique(array_filter(array_map('intval', $movementIds), static fn(int $id): bool => $id > 0)));
        if ($movementIds === []) {
            return 0;
        }
        $rows = $conn->query(
            'SELECT DISTINCT item_id FROM inventory_movements WHERE id IN (' . implode(',', $movementIds) . ') ORDER BY item_id'
        )->fetch_all(MYSQLI_ASSOC);

        return $this->refreshItemMirrors($conn, array_map('intval', array_column($rows, 'item_id')));
    }

    private function refreshItemMirrors(mysqli $conn, array $itemIds): int
    {
        $operationalStoreId = posmain_single_store_mode_enabled() ? posmain_operational_store_id($conn) : 0;
        $storePredicate = $operationalStoreId > 0 ? ' AND store_id=' . $operationalStoreId : '';
        $refreshed = 0;
        foreach (array_values(array_unique(array_filter(array_map('intval', $itemIds)))) as $itemId) {
            $row = $conn->query(
                'SELECT COALESCE(SUM(qty_on_hand),0) AS qty FROM inventory_item_balances'
                . ' WHERE item_id=' . $itemId . $storePredicate
                . ' FOR UPDATE'
            )->fetch_assoc();
            if (!$this->legacyMirror->refreshItemQtySummary(
                $conn,
                $itemId,
                InventoryDecimal::normalize($row['qty'] ?? '0')
            )) {
                throw new RuntimeException('INVENTORY_UNPAID_SALE_RECLASS_MIRROR_REFRESH_FAILED');
            }
            $refreshed++;
        }

        return $refreshed;
    }

    private function assertRunnable(array $plan): void
    {
        if (empty($plan['ok']) || !empty($plan['blockers'])) {
            throw new RuntimeException('INVENTORY_UNPAID_SALE_RECLASS_PLAN_BLOCKED');
        }
    }

    private function blockedPlan(string $code): array
    {
        $manifest = ['repair_type' => self::REPAIR_TYPE, 'entries' => [], 'skipped' => [], 'blockers' => [['code' => $code]]];

        return [
            'ok' => false,
            'mode' => 'plan',
            'manifest_hash' => hash('sha256', $this->canonicalJson($manifest)),
            'summary' => ['entry_count' => 0, 'movement_count' => 0, 'skipped_count' => 0, 'blocker_count' => 1],
            'entries' => [],
            'skipped' => [],
            'blockers' => $manifest['blockers'],
        ];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
        $stmt->close();

        return $exists;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
        $stmt->close();

        return $exists;
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

        return (string) json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
