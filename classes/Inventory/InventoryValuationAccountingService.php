<?php

declare(strict_types=1);

require_once __DIR__ . '/../Financial/Money.php';
require_once __DIR__ . '/../Financial/RoundingPolicy.php';

final class InventoryValuationAccountingService
{
    public function review(mysqli $conn, array $scope, int $inventoryAssetAccountId): array
    {
        foreach (['inventory_item_balances', 'journal_entries', 'acc_head'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                return [
                    'ok' => false,
                    'status' => 'missing_table',
                    'blockers' => ['inventory_valuation_accounting_requires_table:' . $table],
                ];
            }
        }
        if ($inventoryAssetAccountId < 1 || !$this->activeAccountExists($conn, $inventoryAssetAccountId)) {
            return [
                'ok' => false,
                'status' => 'invalid_account',
                'blockers' => ['inventory_asset_account_missing_or_inactive'],
            ];
        }

        $tenant = (int) ($scope['pos_tenant'] ?? 0);
        $branch = (int) ($scope['pos_branch'] ?? 0);
        $store = (int) ($scope['store_id'] ?? 0);
        $stmt = $conn->prepare("
SELECT
  COALESCE(SUM(qty_on_hand * moving_average_cost), 0) AS valuation,
  SUM(CASE WHEN qty_on_hand < 0 THEN 1 ELSE 0 END) AS negative_quantity_count,
  COUNT(*) AS balance_row_count
FROM inventory_item_balances
WHERE pos_tenant=? AND pos_branch=? AND store_id=?");
        $stmt->bind_param('iii', $tenant, $branch, $store);
        $stmt->execute();
        $balance = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $entryConditions = ['account_id=?'];
        $params = [$inventoryAssetAccountId];
        if ($this->columnExists($conn, 'journal_entries', 'tenant')) {
            $entryConditions[] = 'COALESCE(tenant,0)=?';
            $params[] = $tenant;
        }
        if ($this->columnExists($conn, 'journal_entries', 'branch')) {
            $entryConditions[] = 'COALESCE(branch,0)=?';
            $params[] = $branch;
        }
        $stmt = $conn->prepare(
            'SELECT COALESCE(SUM(debit-credit),0) AS gl_balance FROM journal_entries WHERE '
            . implode(' AND ', $entryConditions)
        );
        $types = str_repeat('i', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $gl = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $valuationRaw = (string) ($balance['valuation'] ?? '0');
        $valuation = Money::from(RoundingPolicy::halfUp($valuationRaw), true);
        $glBalance = Money::from(RoundingPolicy::halfUp((string) ($gl['gl_balance'] ?? '0')), true);
        $difference = $valuation->subtract($glBalance);
        $negativeCount = (int) ($balance['negative_quantity_count'] ?? 0);
        $blockers = [];
        if ($negativeCount > 0) {
            $blockers[] = 'negative_inventory_quantities_require_count_or_review';
        }
        if ($difference->compare(Money::zero()) !== 0) {
            $blockers[] = 'inventory_valuation_does_not_match_inventory_asset_gl';
        }

        return [
            'ok' => $blockers === [],
            'status' => $blockers === [] ? 'ready' : 'problems_found',
            'scope' => ['pos_tenant' => $tenant, 'pos_branch' => $branch, 'store_id' => $store],
            'inventory_asset_account_id' => $inventoryAssetAccountId,
            'valuation_raw_6dp' => number_format((float) $valuationRaw, 6, '.', ''),
            'valuation_rounded_2dp' => $valuation->toString(),
            'inventory_asset_gl_balance_2dp' => $glBalance->toString(),
            'difference_2dp' => $difference->toString(),
            'negative_quantity_count' => $negativeCount,
            'balance_row_count' => (int) ($balance['balance_row_count'] ?? 0),
            'rounding_policy' => 'half_up_2dp',
            'blockers' => $blockers,
        ];
    }

    private function activeAccountExists(mysqli $conn, int $accountId): bool
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM acc_head WHERE id=? AND COALESCE(isdeleted,0)=0');
        $stmt->bind_param('i', $accountId);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        return $count === 1;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        return $count > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        return $count > 0;
    }
}
