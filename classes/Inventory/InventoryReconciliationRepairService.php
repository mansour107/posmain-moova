<?php

require_once __DIR__ . '/InventoryLegacyMirrorService.php';
require_once __DIR__ . '/../Recipe/RecipeReconciliationService.php';
require_once __DIR__ . '/../Maintenance/DataRepairRunLedger.php';

class InventoryReconciliationRepairService
{
    private InventoryLegacyMirrorService $legacyMirror;
    private RecipeReconciliationService $reconciliation;

    public function __construct(
        ?InventoryLegacyMirrorService $legacyMirror = null,
        ?RecipeReconciliationService $reconciliation = null
    ) {
        $this->legacyMirror = $legacyMirror ?: new InventoryLegacyMirrorService();
        $this->reconciliation = $reconciliation ?: new RecipeReconciliationService();
    }

    public function mirrorRepairPlan(mysqli $conn, array $filters = []): array
    {
        $filters['differences_only'] = true;
        $rows = $this->reconciliation->report($conn, $filters);
        $repairs = [];
        $unhandled = [];
        foreach ($rows as $row) {
            if ($this->isSafeLegacyMirrorRepair($row)) {
                $repairs[] = [
                    'item_id' => (int) ($row['item_id'] ?? 0),
                    'item_name' => (string) ($row['item_name'] ?? ''),
                    'current_legacy_qty' => (string) ($row['legacy_qty'] ?? '0.000000'),
                    'target_legacy_qty' => (string) ($row['ledger_qty'] ?? '0.000000'),
                    'fat_details_qty' => (string) ($row['fat_details_qty'] ?? '0.000000'),
                    'ledger_qty' => (string) ($row['ledger_qty'] ?? '0.000000'),
                    'difference_reasons' => $row['difference_reasons'] ?? [],
                    'repair_type' => 'legacy_mirror_qty_refresh',
                ];
                continue;
            }

            $unhandled[] = [
                'item_id' => (int) ($row['item_id'] ?? 0),
                'item_name' => (string) ($row['item_name'] ?? ''),
                'difference_reasons' => $row['difference_reasons'] ?? [],
                'recommended_action' => (string) ($row['recommended_action'] ?? ''),
            ];
        }

        $manifest = [
            'repair_candidates' => $repairs,
            'unhandled_differences' => $unhandled,
        ];

        return [
            'ok' => true,
            'mode' => 'dry_run',
            'manifest_hash' => hash('sha256', $this->canonicalJson($manifest)),
            'summary' => [
                'difference_count' => count($rows),
                'repair_candidate_count' => count($repairs),
                'unhandled_difference_count' => count($unhandled),
                'dry_run_only' => true,
            ],
            'repair_candidates' => $repairs,
            'unhandled_differences' => $unhandled,
            'blockers' => [],
        ];
    }

    public function rehearseMirrorRepair(mysqli $conn, array $filters = []): array
    {
        $conn->begin_transaction();
        try {
            $plan = $this->mirrorRepairPlan($conn, $filters);
            $result = $this->runMirrorRepair($conn, $plan, true);
            $conn->rollback();

            return $result;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    public function applyMirrorRepair(mysqli $conn, array $filters = [], string $reviewedManifestHash = ''): array
    {
        $reviewedManifestHash = strtolower(trim($reviewedManifestHash));
        if (!preg_match('/^[a-f0-9]{64}$/', $reviewedManifestHash)) {
            throw new RuntimeException('INVENTORY_REPAIR_REVIEWED_MANIFEST_REQUIRED');
        }
        $ledger = new DataRepairRunLedger();
        $prior = $ledger->find($conn, 'inventory_legacy_mirror_repair', $reviewedManifestHash);
        if ($prior !== null) {
            $prior['replayed'] = true;

            return $prior;
        }

        $conn->begin_transaction();
        try {
            $plan = $this->mirrorRepairPlan($conn, $filters);
            if (!hash_equals((string) $plan['manifest_hash'], $reviewedManifestHash)) {
                throw new RuntimeException('INVENTORY_REPAIR_MANIFEST_CHANGED');
            }
            $result = $this->runMirrorRepair($conn, $plan, false);
            $result['replayed'] = false;
            $ledger->record($conn, 'inventory_legacy_mirror_repair', $reviewedManifestHash, $result);
            $conn->commit();

            return $result;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    private function runMirrorRepair(mysqli $conn, array $plan, bool $rehearsal): array
    {
        $repaired = [];
        foreach ($plan['repair_candidates'] as $candidate) {
            $itemId = (int) ($candidate['item_id'] ?? 0);
            if ($itemId < 1) {
                continue;
            }
            $targetQty = (string) ($candidate['target_legacy_qty'] ?? '0.000000');
            $this->legacyMirror->refreshItemQtySummary($conn, $itemId, $targetQty);
            $repaired[] = [
                'item_id' => $itemId,
                'target_legacy_qty' => $targetQty,
                'repair_type' => 'legacy_mirror_qty_refresh',
            ];
        }

        return [
            'ok' => true,
            'mode' => $rehearsal ? 'rehearse' : 'apply',
            'manifest_hash' => (string) ($plan['manifest_hash'] ?? ''),
            'summary' => [
                'difference_count' => (int) ($plan['summary']['difference_count'] ?? 0),
                'repair_candidate_count' => (int) ($plan['summary']['repair_candidate_count'] ?? 0),
                'unhandled_difference_count' => (int) ($plan['summary']['unhandled_difference_count'] ?? 0),
                'repaired_count' => $rehearsal ? 0 : count($repaired),
                'rehearsed_count' => $rehearsal ? count($repaired) : 0,
                'dry_run_only' => $rehearsal,
            ],
            'repaired_items' => $rehearsal ? [] : $repaired,
            'rehearsed_items' => $rehearsal ? $repaired : [],
            'unhandled_differences' => $plan['unhandled_differences'],
            'blockers' => [],
        ];
    }

    private function isSafeLegacyMirrorRepair(array $row): bool
    {
        $reasons = array_values(array_map('strval', $row['difference_reasons'] ?? []));
        sort($reasons);
        $safeReasonSets = [
            ['legacy_summary_mismatch'],
            ['legacy_summary_mismatch', 'movement_scope_or_quantity_mismatch'],
        ];

        return in_array($reasons, $safeReasonSets, true)
            && (int) ($row['track_stock'] ?? 1) === 1
            && (string) ($row['fat_details_qty'] ?? '') === (string) ($row['ledger_qty'] ?? '')
            && (string) ($row['ledger_qty'] ?? '') === (string) ($row['balance_qty'] ?? '')
            && (string) ($row['legacy_qty'] ?? '') !== (string) ($row['ledger_qty'] ?? '');
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
