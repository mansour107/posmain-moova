<?php

declare(strict_types=1);

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryLedgerService.php';
require_once __DIR__ . '/InventoryLegacyMirrorService.php';
require_once __DIR__ . '/../Maintenance/DataRepairRunLedger.php';

/**
 * Neutralizes invalid historical ledger balances for items that are explicitly
 * configured as non-stock. Original movements remain immutable.
 */
final class InventoryNonStockLedgerNeutralizationService
{
    private const REPAIR_TYPE = 'inventory_non_stock_ledger_neutralization';

    private InventoryLedgerService $ledger;
    private InventoryLegacyMirrorService $legacyMirror;

    public function __construct(
        ?InventoryLedgerService $ledger = null,
        ?InventoryLegacyMirrorService $legacyMirror = null
    )
    {
        $this->ledger = $ledger ?: new InventoryLedgerService();
        $this->legacyMirror = $legacyMirror ?: new InventoryLegacyMirrorService();
    }

    public function plan(mysqli $conn): array
    {
        $rows = $conn->query("
SELECT
  b.pos_tenant,
  b.pos_branch,
  b.branch_uuid,
  b.store_id,
  b.item_id,
  i.iname AS item_name,
  b.qty_on_hand,
  b.qty_reserved,
  b.moving_average_cost,
  COALESCE(m.ledger_qty, 0) AS ledger_qty
FROM inventory_item_balances b
JOIN myitems i ON i.id = b.item_id AND COALESCE(i.track_stock, 1) = 0
LEFT JOIN (
  SELECT pos_tenant, pos_branch, store_id, item_id, SUM(qty_in - qty_out) AS ledger_qty
  FROM inventory_movements
  GROUP BY pos_tenant, pos_branch, store_id, item_id
) m
  ON m.pos_tenant = b.pos_tenant
 AND m.pos_branch = b.pos_branch
 AND m.store_id = b.store_id
 AND m.item_id = b.item_id
WHERE b.qty_on_hand <> 0 OR b.qty_reserved <> 0 OR COALESCE(m.ledger_qty, 0) <> 0
ORDER BY b.pos_tenant, b.pos_branch, b.store_id, b.item_id")->fetch_all(MYSQLI_ASSOC);

        $entries = [];
        $blockers = [];
        foreach ($rows as $row) {
            $qty = InventoryDecimal::normalize($row['qty_on_hand'] ?? '0');
            $ledgerQty = InventoryDecimal::normalize($row['ledger_qty'] ?? '0');
            $reserved = InventoryDecimal::normalize($row['qty_reserved'] ?? '0');
            $identity = [
                'pos_tenant' => (int) $row['pos_tenant'],
                'pos_branch' => (int) $row['pos_branch'],
                'store_id' => (int) $row['store_id'],
                'item_id' => (int) $row['item_id'],
            ];
            if (InventoryDecimal::compare($reserved, '0') < 0) {
                $blockers[] = $identity + ['code' => 'non_stock_item_has_negative_reserved_quantity'];
                continue;
            }
            if (InventoryDecimal::compare($qty, $ledgerQty) !== 0) {
                $blockers[] = $identity + [
                    'code' => 'non_stock_ledger_balance_mismatch',
                    'balance_qty' => $qty,
                    'ledger_qty' => $ledgerQty,
                ];
                continue;
            }
            if (InventoryDecimal::compare($qty, '0') === 0
                && InventoryDecimal::compare($reserved, '0') === 0) {
                continue;
            }
            $entries[] = $identity + [
                'branch_uuid' => $this->nullableString($row['branch_uuid'] ?? null),
                'item_name' => (string) ($row['item_name'] ?? ''),
                'quantity_before' => $qty,
                'reserved_before' => $reserved,
                'direction' => InventoryDecimal::compare($qty, '0') === 0
                    ? 'none'
                    : (InventoryDecimal::compare($qty, '0') > 0 ? 'decrease' : 'increase'),
                'quantity' => InventoryDecimal::compare($qty, '0') === 0
                    ? '0.000000'
                    : (InventoryDecimal::compare($qty, '0') > 0
                        ? $qty
                        : InventoryDecimal::subtract('0', $qty)),
                'unit_cost' => '0.000000',
                'total_cost' => '0.000000',
            ];
        }

        $legacyItems = $conn->query("
SELECT id AS item_id, iname AS item_name, itmqty AS legacy_qty_before
FROM myitems
WHERE COALESCE(track_stock, 1) = 0
  AND COALESCE(itmqty, 0) <> 0
ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        $legacyItems = array_map(static fn(array $row): array => [
            'item_id' => (int) $row['item_id'],
            'item_name' => (string) ($row['item_name'] ?? ''),
            'legacy_qty_before' => InventoryDecimal::normalize($row['legacy_qty_before'] ?? '0'),
            'legacy_qty_after' => '0.000000',
        ], $legacyItems);

        $manifest = [
            'repair_type' => self::REPAIR_TYPE,
            'entries' => $entries,
            'legacy_mirror_resets' => $legacyItems,
            'blockers' => $blockers,
        ];

        return [
            'ok' => !$blockers,
            'mode' => 'plan',
            'manifest_hash' => hash('sha256', $this->canonicalJson($manifest)),
            'summary' => [
                'entry_count' => count($entries),
                'movement_count' => array_sum(array_map(static function (array $entry): int {
                    return (InventoryDecimal::isPositive($entry['reserved_before'] ?? '0') ? 1 : 0)
                        + (InventoryDecimal::isPositive($entry['quantity'] ?? '0') ? 1 : 0);
                }, $entries)),
                'legacy_mirror_reset_count' => count($legacyItems),
                'blocker_count' => count($blockers),
            ],
            'entries' => $entries,
            'legacy_mirror_resets' => $legacyItems,
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
            throw new RuntimeException('INVENTORY_NON_STOCK_REVIEWED_MANIFEST_REQUIRED');
        }
        if ($backupPath === '' || !is_file($backupPath) || !is_readable($backupPath) || filesize($backupPath) < 1) {
            throw new RuntimeException('INVENTORY_NON_STOCK_READABLE_BACKUP_REQUIRED');
        }

        $runLedger = new DataRepairRunLedger();
        $prior = $runLedger->find($conn, self::REPAIR_TYPE, $reviewedManifestHash);
        if ($prior !== null) {
            $prior['replayed'] = true;

            return $prior;
        }

        $conn->begin_transaction();
        try {
            $conn->query("
SELECT b.id
FROM inventory_item_balances b
JOIN myitems i ON i.id = b.item_id AND COALESCE(i.track_stock, 1) = 0
ORDER BY b.pos_tenant, b.pos_branch, b.store_id, b.item_id
FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
            $plan = $this->plan($conn);
            $this->assertRunnable($plan);
            if (!hash_equals((string) $plan['manifest_hash'], $reviewedManifestHash)) {
                throw new RuntimeException('INVENTORY_NON_STOCK_MANIFEST_CHANGED');
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
            $direction = (string) $entry['direction'];
            $scope = [
                'pos_tenant' => (int) $entry['pos_tenant'],
                'pos_branch' => (int) $entry['pos_branch'],
                'branch_uuid' => $entry['branch_uuid'],
                'store_id' => (int) $entry['store_id'],
            ];
            $itemOverride = [
                'id' => (int) $entry['item_id'],
                'item_type' => 'ingredient',
                'track_stock' => 1,
            ];
            $keyBase = 'non-stock-neutralize:' . $plan['manifest_hash'] . ':' . $entry['pos_tenant'] . ':' . $entry['pos_branch'] . ':' . $entry['store_id'] . ':' . $entry['item_id'];
            $metadata = [
                'repair_type' => self::REPAIR_TYPE,
                'reviewed_manifest_hash' => (string) $plan['manifest_hash'],
                'reason' => 'remove_invalid_historical_stock_state_from_non_stock_item',
                'quantity_before' => (string) $entry['quantity_before'],
                'reserved_before' => (string) $entry['reserved_before'],
            ];
            if (InventoryDecimal::isPositive($entry['reserved_before'] ?? '0')) {
                $release = $this->ledger->recordMovement($conn, [
                    'scope' => $scope,
                    'item_id' => (int) $entry['item_id'],
                    'movement_type' => 'reservation_release',
                    'source_type' => 'reservation',
                    'qty_reserved' => (string) $entry['reserved_before'],
                    'unit_cost' => '0.000000',
                    'total_cost' => '0.000000',
                    'idempotency_key' => $keyBase . ':reservation-release',
                    'metadata' => $metadata + ['correction_part' => 'reservation_release'],
                ], $itemOverride, [
                    'manage_transaction' => false,
                    'enforce_negative_policy' => false,
                ]);
                $movements[] = [
                    'movement_id' => (int) ($release['movement_id'] ?? 0),
                    'store_id' => (int) $entry['store_id'],
                    'item_id' => (int) $entry['item_id'],
                    'direction' => 'reservation_release',
                    'quantity' => (string) $entry['reserved_before'],
                ];
            }
            if (!InventoryDecimal::isPositive($entry['quantity'] ?? '0')) {
                continue;
            }
            $movement = [
                'scope' => [
                    'pos_tenant' => $scope['pos_tenant'],
                    'pos_branch' => $scope['pos_branch'],
                    'branch_uuid' => $scope['branch_uuid'],
                    'store_id' => $scope['store_id'],
                ],
                'item_id' => (int) $entry['item_id'],
                'movement_type' => 'adjustment',
                'source_type' => 'manual',
                'qty_in' => $direction === 'increase' ? (string) $entry['quantity'] : '0.000000',
                'qty_out' => $direction === 'decrease' ? (string) $entry['quantity'] : '0.000000',
                'unit_cost' => '0.000000',
                'total_cost' => '0.000000',
                'idempotency_key' => $keyBase . ':on-hand',
                'metadata' => $metadata + ['correction_part' => 'on_hand'],
            ];
            // The explicit item policy override is limited to this reviewed repair:
            // normal non-stock runtime writes remain no-ops.
            $result = $this->ledger->recordMovement($conn, $movement, $itemOverride, [
                'manage_transaction' => false,
                'enforce_negative_policy' => false,
            ]);
            $movements[] = [
                'movement_id' => (int) ($result['movement_id'] ?? 0),
                'store_id' => (int) $entry['store_id'],
                'item_id' => (int) $entry['item_id'],
                'direction' => $direction,
                'quantity' => (string) $entry['quantity'],
            ];
        }
        $legacyResets = [];
        foreach (($plan['legacy_mirror_resets'] ?? []) as $reset) {
            $itemId = (int) ($reset['item_id'] ?? 0);
            if ($itemId < 1) {
                continue;
            }
            $this->legacyMirror->refreshItemQtySummary($conn, $itemId, '0.000000');
            $legacyResets[] = [
                'item_id' => $itemId,
                'legacy_qty_before' => (string) $reset['legacy_qty_before'],
                'legacy_qty_after' => '0.000000',
            ];
        }

        return [
            'ok' => true,
            'mode' => $rehearsal ? 'rehearse' : 'apply',
            'manifest_hash' => (string) $plan['manifest_hash'],
            'summary' => [
                'entry_count' => count($plan['entries']),
                'legacy_mirror_reset_count' => count($legacyResets),
                'rehearsed_count' => $rehearsal ? count($movements) : 0,
                'applied_count' => $rehearsal ? 0 : count($movements),
            ],
            'movements' => $movements,
            'legacy_mirror_resets' => $legacyResets,
            'blockers' => [],
        ];
    }

    private function assertRunnable(array $plan): void
    {
        if (empty($plan['ok']) || !empty($plan['blockers'])) {
            throw new RuntimeException('INVENTORY_NON_STOCK_NEUTRALIZATION_PLAN_BLOCKED');
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

        return (string) json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
