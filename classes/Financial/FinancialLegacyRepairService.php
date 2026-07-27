<?php

require_once __DIR__ . '/../Sync/PosOrderSnapshotBuilder.php';
require_once __DIR__ . '/../Maintenance/DataRepairRunLedger.php';
require_once __DIR__ . '/Decimal.php';
require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/UnitPrice.php';
require_once __DIR__ . '/DecimalQuantity.php';
require_once __DIR__ . '/RoundingPolicy.php';

final class FinancialLegacyRepairService
{
    public function plan(mysqli $conn): array
    {
        $paymentCandidates = $this->rows($conn, "
            SELECT oh.id AS order_id, oh.fat_net AS amount,
                   COALESCE(oh.emp_id, oh.user, 0) AS employee_id,
                   oh.payment_date AS payment_timestamp
            FROM ot_head oh
            WHERE oh.pro_tybe = 9
              AND COALESCE(oh.isdeleted, 0) = 0
              AND oh.payment_status = 'paid'
              AND COALESCE(oh.invoice_status, 'completed') = 'completed'
              AND COALESCE(oh.order_status, 'completed') = 'completed'
              AND COALESCE(oh.payment_method, '') = 'cash'
              AND oh.payment_date IS NOT NULL
              AND COALESCE(oh.fat_net, 0) > 0
              AND NOT EXISTS (SELECT 1 FROM order_payments op WHERE op.order_id = oh.id)
            ORDER BY oh.id
        ");
        foreach ($paymentCandidates as &$row) {
            $row['payment_uuid'] = PosOrderSnapshotBuilder::deterministicUuid('legacy-order-payment', 'order:' . (int) $row['order_id']);
            $row['payment_method'] = 'cash';
        }
        unset($row);

        $snapshotCandidates = [];
        $ambiguousOrders = [];
        $orders = $this->rows($conn, "
            SELECT oh.id AS order_id, oh.fat_net, COALESCE(oh.fat_tax, 0) AS fat_tax,
                   ROUND(COALESCE(SUM(CASE WHEN COALESCE(fd.isdeleted,0)=0 THEN fd.det_value ELSE 0 END),0),2) AS line_net,
                   SUM(CASE WHEN COALESCE(fd.isdeleted,0)=0 AND fd.posted_net IS NULL THEN 1 ELSE 0 END) AS missing_snapshots
            FROM ot_head oh
            LEFT JOIN fat_details fd ON fd.fatid = oh.id
            WHERE oh.pro_tybe = 9
              AND COALESCE(oh.isdeleted,0)=0
              AND oh.payment_status='paid'
              AND COALESCE(oh.invoice_status, 'completed') = 'completed'
              AND COALESCE(oh.order_status, 'completed') = 'completed'
            GROUP BY oh.id, oh.fat_net, oh.fat_tax
            HAVING missing_snapshots > 0
            ORDER BY oh.id
        ");
        foreach ($orders as $order) {
            $headerNet = Money::from((string) $order['fat_net']);
            $lineNet = Money::from((string) $order['line_net']);
            $tax = Money::from((string) $order['fat_tax']);
            if ($headerNet->compare($lineNet) !== 0 || $tax->compare(Money::zero()) !== 0) {
                $ambiguousOrders[] = $order;
                continue;
            }
            $snapshotCandidates = array_merge($snapshotCandidates, $this->rows($conn, '
                SELECT id AS line_id, fatid AS order_id, qty_in, qty_out, price, discount, det_value, cost_price
                FROM fat_details
                WHERE fatid = ' . (int) $order['order_id'] . ' AND COALESCE(isdeleted,0)=0 AND posted_net IS NULL ORDER BY id'));
        }

        $accountCandidates = $this->rows($conn, "
            SELECT ah.id AS account_id, ah.balance AS cached_balance,
                   ROUND(COALESCE(SUM(je.debit)-SUM(je.credit),0),6) AS journal_balance
            FROM acc_head ah
            LEFT JOIN journal_entries je ON je.account_id = ah.id
            GROUP BY ah.id, ah.balance
            HAVING ROUND(COALESCE(ah.balance,0),6) <> ROUND(COALESCE(SUM(je.debit)-SUM(je.credit),0),6)
            ORDER BY ah.id
        ");
        $demoTenders = $this->rows($conn, "
            SELECT pm.id, pm.code,
                   (SELECT COUNT(*) FROM order_payments op WHERE op.payment_method = pm.code) AS usage_count
            FROM payment_methods pm
            WHERE pm.is_active = 1 AND pm.code LIKE 'P6-DEMO%'
            ORDER BY pm.id
        ");
        $demoTenderBlockers = array_values(array_filter($demoTenders, static fn (array $row): bool => (int) $row['usage_count'] > 0));
        $journalImbalanceCount = (int) ($this->rows($conn, "
            SELECT COUNT(*) AS c FROM (
                SELECT journal_id
                FROM journal_entries
                GROUP BY journal_id
                HAVING ROUND(SUM(debit), 6) <> ROUND(SUM(credit), 6)
            ) imbalanced
        ")[0]['c'] ?? 0);

        $manifest = [
            'payment_candidates' => $paymentCandidates,
            'snapshot_candidates' => $snapshotCandidates,
            'ambiguous_orders' => $ambiguousOrders,
            'account_candidates' => $accountCandidates,
            'demo_tenders' => $demoTenders,
            'journal_imbalance_count' => $journalImbalanceCount,
        ];

        $blockers = [];
        if ($journalImbalanceCount > 0) {
            $blockers[] = 'journal_imbalance_must_be_zero';
        }
        if ($demoTenderBlockers) {
            $blockers[] = 'used_demo_tenders_require_account_mapping_manifest';
        }

        return $manifest + [
            'manifest_hash' => hash('sha256', $this->canonicalJson($manifest)),
            'blockers' => $blockers,
        ];
    }

    public function apply(mysqli $conn, string $reviewedManifestHash, string $backupFile): array
    {
        if ($backupFile === '' || !is_file($backupFile) || !is_readable($backupFile) || filesize($backupFile) < 1) {
            throw new RuntimeException('READABLE_DATABASE_BACKUP_REQUIRED');
        }
        $reviewedManifestHash = strtolower(trim($reviewedManifestHash));
        if (!preg_match('/^[a-f0-9]{64}$/', $reviewedManifestHash)) {
            throw new RuntimeException('FINANCIAL_REPAIR_REVIEWED_MANIFEST_REQUIRED');
        }
        $ledger = new DataRepairRunLedger();
        $prior = $ledger->find($conn, 'financial_legacy_repair', $reviewedManifestHash);
        if ($prior !== null) {
            $prior['replayed'] = true;

            return $prior;
        }
        $counts = ['payments' => 0, 'snapshots' => 0, 'account_caches' => 0, 'demo_tenders' => 0];
        $conn->begin_transaction();
        try {
            $plan = $this->plan($conn);
            if (!hash_equals((string) $plan['manifest_hash'], $reviewedManifestHash)) {
                throw new RuntimeException('FINANCIAL_REPAIR_LIVE_ROWS_CHANGED');
            }
            if ($plan['blockers']) {
                throw new RuntimeException((string) $plan['blockers'][0]);
            }
            foreach ($plan['payment_candidates'] as $row) {
                $stmt = $conn->prepare("INSERT INTO order_payments (uuid, order_id, amount, payment_method, created_by, created_at) SELECT ?, ?, ?, 'cash', ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM order_payments WHERE order_id = ?)");
                $orderId = (int) $row['order_id'];
                $employeeId = (int) $row['employee_id'];
                $amount = (string) $row['amount'];
                $timestamp = (string) $row['payment_timestamp'];
                $uuid = (string) $row['payment_uuid'];
                $stmt->bind_param('sisisi', $uuid, $orderId, $amount, $employeeId, $timestamp, $orderId);
                $stmt->execute();
                $counts['payments'] += $stmt->affected_rows;
                $stmt->close();
            }
            foreach ($plan['snapshot_candidates'] as $row) {
                $qtyIn = DecimalQuantity::from((string) $row['qty_in'])->toString();
                $qtyOut = DecimalQuantity::from((string) $row['qty_out'])->toString();
                $qtyDelta = FinancialDecimal::subtract($qtyOut, $qtyIn, DecimalQuantity::SCALE);
                if (FinancialDecimal::compare($qtyDelta, '0', DecimalQuantity::SCALE) < 0) {
                    $qtyDelta = FinancialDecimal::subtract('0', $qtyDelta, DecimalQuantity::SCALE);
                }
                $qty = DecimalQuantity::from($qtyDelta)->toString();
                $price = UnitPrice::from((string) $row['price'])->toString();
                $unitCost = UnitPrice::from((string) $row['cost_price'])->toString();
                $gross = RoundingPolicy::halfUp(
                    FinancialDecimal::multiply($qty, $price, 12),
                    Money::SCALE,
                    12
                );
                $cost = RoundingPolicy::halfUp(
                    FinancialDecimal::multiply($qty, $unitCost, 12),
                    UnitPrice::SCALE,
                    12
                );
                $stmt = $conn->prepare("UPDATE fat_details SET posted_qty=?, posted_unit_price=?, posted_line_discount=?, posted_order_discount=0, posted_taxable=?, posted_tax=0, posted_gross=?, posted_net=?, posted_unit_cost=?, posted_total_cost=?, tax_rate_snapshot=0 WHERE id=? AND posted_net IS NULL");
                $lineId = (int) $row['line_id'];
                $discount = Money::from((string) $row['discount'])->toString();
                $net = Money::from((string) $row['det_value'])->toString();
                $stmt->bind_param('ssssssssi', $qty, $price, $discount, $net, $gross, $net, $unitCost, $cost, $lineId);
                $stmt->execute();
                $counts['snapshots'] += $stmt->affected_rows;
                $stmt->close();
            }
            foreach ($plan['account_candidates'] as $row) {
                $stmt = $conn->prepare('UPDATE acc_head SET balance = ? WHERE id = ? AND ROUND(COALESCE(balance,0),6) = ROUND(?,6)');
                $id = (int) $row['account_id'];
                $journalBalance = (string) $row['journal_balance'];
                $cachedBalance = (string) $row['cached_balance'];
                $stmt->bind_param('sis', $journalBalance, $id, $cachedBalance);
                $stmt->execute();
                $counts['account_caches'] += $stmt->affected_rows;
                $stmt->close();
            }
            foreach ($plan['demo_tenders'] as $row) {
                $stmt = $conn->prepare('UPDATE payment_methods SET is_active = 0 WHERE id = ? AND is_active = 1 AND NOT EXISTS (SELECT 1 FROM order_payments WHERE payment_method = ?)');
                $id = (int) $row['id'];
                $code = (string) $row['code'];
                $stmt->bind_param('is', $id, $code);
                $stmt->execute();
                $counts['demo_tenders'] += $stmt->affected_rows;
                $stmt->close();
            }
            $result = ['manifest_hash' => $plan['manifest_hash'], 'applied' => $counts, 'ambiguous_orders' => $plan['ambiguous_orders'], 'replayed' => false];
            $ledger->record($conn, 'financial_legacy_repair', $reviewedManifestHash, $result);
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        return $result;
    }

    private function rows(mysqli $conn, string $sql): array
    {
        $result = $conn->query($sql);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    private function canonicalJson(array $value): string
    {
        $walk = function (array $data) use (&$walk): array {
            if (!array_is_list($data)) {
                ksort($data);
            }
            foreach ($data as $key => $item) {
                if (is_array($item)) {
                    $data[$key] = $walk($item);
                }
            }
            return $data;
        };
        return json_encode($walk($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
