<?php

require_once __DIR__ . '/DeliveryCompensationService.php';
require_once __DIR__ . '/DeliveryWorkerService.php';
require_once __DIR__ . '/DeliveryAccountingService.php';
require_once __DIR__ . '/../../Sync/OperationalSyncEventService.php';
require_once __DIR__ . '/../../Financial/Money.php';

final class DeliverySettlementService
{
    public function preview(mysqli $conn, int $workerId, string $periodStart, string $periodEnd, array $options = []): array
    {
        [$periodStart, $periodEnd] = $this->period($periodStart, $periodEnd);
        $tenant = max(0, (int) ($options['tenant'] ?? 0));
        $branch = max(0, (int) ($options['branch'] ?? 0));
        $worker = (new DeliveryWorkerService())->getWorker($conn, $workerId, $tenant, $branch);
        $financials = $this->openFinancials($conn, $workerId, $periodStart, $periodEnd, $tenant, $branch, false);
        $deliveryEarnings = $this->sum($financials, 'compensation_amount');
        $tips = $this->sum($financials, 'tip_amount');
        $codHeld = $this->sum($financials, 'cod_amount');
        $basePay = $this->basePay($conn, $worker, $periodStart, $periodEnd, $tenant, $branch);
        $bonuses = $this->amount($options['bonuses'] ?? 0);
        $deductions = $this->amount($options['deductions'] ?? 0);
        $gross = $deliveryEarnings->add($tips)->add($basePay)->add($bonuses);
        if ($deductions->compare($gross) > 0) {
            throw new InvalidArgumentException('DELIVERY_DEDUCTIONS_EXCEED_EARNINGS');
        }
        $net = $gross->subtract($deductions)->subtract($codHeld);
        $direction = $net->isPositive() ? 'shop_pays' : ($net->isNegative() ? 'worker_pays' : 'balanced');

        return [
            'worker' => $worker,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'orders' => $financials,
            'order_count' => count($financials),
            'delivery_earnings' => $this->format($deliveryEarnings),
            'base_pay' => $this->format($basePay),
            'tips' => $this->format($tips),
            'bonuses' => $this->format($bonuses),
            'deductions' => $this->format($deductions),
            'cod_held' => $this->format($codHeld),
            'net_amount' => $this->format($net),
            'settlement_direction' => $direction,
        ];
    }

    public function finalize(mysqli $conn, int $workerId, string $periodStart, string $periodEnd, array $options = []): array
    {
        $conn->begin_transaction();
        try {
            $result = $this->finalizeInsideTransaction($conn, $workerId, $periodStart, $periodEnd, $options);
            $conn->commit();
            return $result;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    private function finalizeInsideTransaction(mysqli $conn, int $workerId, string $periodStart, string $periodEnd, array $options): array
    {
        [$periodStart, $periodEnd] = $this->period($periodStart, $periodEnd);
        $tenant = max(0, (int) ($options['tenant'] ?? 0));
        $branch = max(0, (int) ($options['branch'] ?? 0));
        $userId = max(0, (int) ($options['user_id'] ?? 0));
        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('DELIVERY_SETTLEMENT_IDEMPOTENCY_REQUIRED');
        }
        $existing = $conn->prepare('SELECT * FROM delivery_settlements WHERE idempotency_key = ? LIMIT 1');
        $existing->bind_param('s', $idempotencyKey);
        $existing->execute();
        $replay = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($replay) {
            return $this->normalizeSettlement($replay) + ['replayed' => true];
        }

        $overlap = $conn->prepare("SELECT id FROM delivery_settlements WHERE worker_id = ? AND tenant = ? AND branch = ? AND status = 'finalized' AND period_start <= ? AND period_end >= ? LIMIT 1 FOR UPDATE");
        $overlap->bind_param('iiiss', $workerId, $tenant, $branch, $periodEnd, $periodStart);
        $overlap->execute();
        $hasOverlap = (bool) $overlap->get_result()->fetch_assoc();
        $overlap->close();
        if ($hasOverlap) {
            throw new InvalidArgumentException('DELIVERY_SETTLEMENT_PERIOD_OVERLAP');
        }

        $preview = $this->preview($conn, $workerId, $periodStart, $periodEnd, $options);
        $financials = $this->openFinancials($conn, $workerId, $periodStart, $periodEnd, $tenant, $branch, true);
        $basePay = Money::from($preview['base_pay']);
        $bonuses = Money::from($preview['bonuses']);
        $netAmount = Money::from($preview['net_amount'], true);
        if ($financials === [] && !$basePay->isPositive() && !$bonuses->isPositive()) {
            throw new InvalidArgumentException('DELIVERY_SETTLEMENT_EMPTY');
        }
        $paymentMethod = strtolower(trim((string) ($options['payment_method'] ?? 'cash')));
        if (!in_array($paymentMethod, ['cash', 'bank', 'offset'], true)) {
            throw new InvalidArgumentException('DELIVERY_SETTLEMENT_PAYMENT_METHOD_INVALID');
        }
        $fundAccountId = max(0, (int) ($options['fund_account_id'] ?? 0));
        $drawerSessionId = max(0, (int) ($options['drawer_session_id'] ?? 0));
        if (($netAmount->isPositive() || $netAmount->isNegative()) && $paymentMethod !== 'offset' && $fundAccountId < 1) {
            throw new InvalidArgumentException('DELIVERY_SETTLEMENT_FUND_REQUIRED');
        }
        if (($netAmount->isPositive() || $netAmount->isNegative()) && $paymentMethod === 'offset') {
            throw new InvalidArgumentException('DELIVERY_SETTLEMENT_OFFSET_REQUIRES_ZERO_NET');
        }
        if (($netAmount->isPositive() || $netAmount->isNegative()) && $paymentMethod === 'cash') {
            $this->assertOpenDrawer($conn, $drawerSessionId, $tenant, $branch);
        }

        $uuid = $this->uuid();
        $notes = $this->nullableText($options['notes'] ?? null, 500);
        $stmt = $conn->prepare('INSERT INTO delivery_settlements (uuid, worker_id, period_start, period_end, delivery_earnings, base_pay, tips, bonuses, deductions, cod_held, net_amount, settlement_direction, payment_method, fund_account_id, drawer_session_id, idempotency_key, notes, finalized_by, tenant, branch) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, ?, ?, ?)');
        $stmt->bind_param('sisssssssssssiissiii', $uuid, $workerId, $periodStart, $periodEnd, $preview['delivery_earnings'], $preview['base_pay'], $preview['tips'], $preview['bonuses'], $preview['deductions'], $preview['cod_held'], $preview['net_amount'], $preview['settlement_direction'], $paymentMethod, $fundAccountId, $drawerSessionId, $idempotencyKey, $notes, $userId, $tenant, $branch);
        $stmt->execute();
        $settlementId = (int) $conn->insert_id;
        $stmt->close();

        foreach ($financials as $financial) {
            $lineAmount = $this->format(
                Money::from($financial['compensation_amount'])
                    ->add(Money::from($financial['tip_amount']))
                    ->subtract(Money::from($financial['cod_amount']))
            );
            $this->insertLine($conn, $settlementId, 'order', (int) $financial['id'], (int) $financial['order_id'], $lineAmount, 'Order #' . (int) $financial['order_id'], [
                'compensation' => $financial['compensation_amount'],
                'tip' => $financial['tip_amount'],
                'cod' => $financial['cod_amount'],
            ], $options);
        }
        foreach ([
            ['base_pay', Money::from($preview['base_pay']), 'Base pay'],
            ['bonus', Money::from($preview['bonuses']), 'Bonus'],
            ['deduction', Money::zero()->subtract(Money::from($preview['deductions'])), 'Deduction'],
        ] as [$type, $amount, $description]) {
            if ($amount->isPositive() || $amount->isNegative()) {
                $this->insertLine($conn, $settlementId, $type, null, null, $this->format($amount), $description, null, $options);
            }
        }

        if ($financials) {
            $ids = implode(',', array_map(static fn(array $row): int => (int) $row['id'], $financials));
            $conn->query("UPDATE delivery_order_financials SET status = 'settled', settlement_id = {$settlementId}, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$ids}) AND status = 'open'");
            foreach ($financials as $financial) {
                $this->sync($conn, 'delivery_order_financial', (int) $financial['id'], $options);
            }
        }

        $settlement = ['id' => $settlementId, 'idempotency_key' => $idempotencyKey, 'fund_account_id' => $fundAccountId] + $preview;
        $journalHeadId = (new DeliveryAccountingService())->postSettlement($conn, $settlement, $userId, $options);
        $drawerMovementId = null;
        if (($netAmount->isPositive() || $netAmount->isNegative()) && $paymentMethod === 'cash') {
            $drawerMovementId = $this->recordDrawerMovement($conn, $settlementId, $workerId, $drawerSessionId, $netAmount, $userId, $tenant, $branch, $options);
        }
        $update = $conn->prepare('UPDATE delivery_settlements SET journal_head_id = ?, drawer_movement_id = ? WHERE id = ?');
        $update->bind_param('iii', $journalHeadId, $drawerMovementId, $settlementId);
        $update->execute();
        $update->close();
        $this->sync($conn, 'delivery_settlement', $settlementId, $options);

        $row = $conn->query('SELECT * FROM delivery_settlements WHERE id = ' . $settlementId)->fetch_assoc();
        return $this->normalizeSettlement($row) + ['replayed' => false, 'orders' => $financials];
    }

    public function listSettlements(mysqli $conn, array $scope = [], int $limit = 100): array
    {
        $tenant = max(0, (int) ($scope['tenant'] ?? 0));
        $branch = max(0, (int) ($scope['branch'] ?? 0));
        $limit = max(1, min(250, $limit));
        $stmt = $conn->prepare("SELECT s.*, w.name AS worker_name FROM delivery_settlements s INNER JOIN delivery_workers w ON w.id = s.worker_id WHERE s.tenant = ? AND s.branch = ? ORDER BY s.finalized_at DESC LIMIT {$limit}");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map([$this, 'normalizeSettlement'], $rows);
    }

    public function reverse(mysqli $conn, int $settlementId, string $reason, array $options = []): array
    {
        $reason = trim($reason);
        if ($settlementId < 1 || $reason === '') {
            throw new InvalidArgumentException('DELIVERY_SETTLEMENT_REVERSAL_REASON_REQUIRED');
        }
        $tenant = max(0, (int) ($options['tenant'] ?? 0));
        $branch = max(0, (int) ($options['branch'] ?? 0));
        $userId = max(0, (int) ($options['user_id'] ?? 0));
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT * FROM delivery_settlements WHERE id = ? AND tenant = ? AND branch = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('iii', $settlementId, $tenant, $branch);
            $stmt->execute();
            $settlement = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$settlement) {
                throw new InvalidArgumentException('DELIVERY_SETTLEMENT_NOT_FOUND');
            }
            if ((string) $settlement['status'] === 'reversed') {
                $conn->commit();
                return $this->normalizeSettlement($settlement) + ['replayed' => true];
            }

            $drawerMovementId = null;
            $netAmount = Money::from($settlement['net_amount'], true);
            if ((string) $settlement['payment_method'] === 'cash' && ($netAmount->isPositive() || $netAmount->isNegative())) {
                $drawerSessionId = max(0, (int) ($options['drawer_session_id'] ?? 0));
                $this->assertOpenDrawer($conn, $drawerSessionId, $tenant, $branch);
                $drawerMovementId = $this->recordDrawerMovement($conn, $settlementId, (int) $settlement['worker_id'], $drawerSessionId, Money::zero()->subtract($netAmount), $userId, $tenant, $branch, $options);
            }
            $reversalJournalId = (new DeliveryAccountingService())->reverseSettlementJournal($conn, $settlement, $userId, $reason, $options);
            $reason = $this->nullableText($reason, 255);
            $update = $conn->prepare("UPDATE delivery_settlements SET status = 'reversed', reversed_by = ?, reversed_at = NOW(), reversal_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'finalized'");
            $update->bind_param('isi', $userId, $reason, $settlementId);
            $update->execute();
            $update->close();
            $conn->query("UPDATE delivery_order_financials SET status = 'open', settlement_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE settlement_id = {$settlementId} AND status = 'settled'");
            $financialRows = $conn->query('SELECT id FROM delivery_order_financials WHERE settlement_id IS NULL AND id IN (SELECT order_financial_id FROM delivery_settlement_lines WHERE settlement_id = ' . $settlementId . ' AND order_financial_id IS NOT NULL)');
            while ($financialRow = $financialRows->fetch_assoc()) {
                $this->sync($conn, 'delivery_order_financial', (int) $financialRow['id'], $options);
            }
            $this->sync($conn, 'delivery_settlement', $settlementId, $options);
            $conn->commit();
            $row = $conn->query('SELECT * FROM delivery_settlements WHERE id = ' . $settlementId)->fetch_assoc();
            return $this->normalizeSettlement($row) + [
                'replayed' => false,
                'reversal_journal_head_id' => $reversalJournalId,
                'reversal_drawer_movement_id' => $drawerMovementId,
            ];
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    private function openFinancials(mysqli $conn, int $workerId, string $start, string $end, int $tenant, int $branch, bool $lock): array
    {
        $lockSql = $lock ? ' FOR UPDATE' : '';
        $stmt = $conn->prepare("SELECT * FROM delivery_order_financials WHERE worker_id = ? AND tenant = ? AND branch = ? AND status = 'open' AND delivered_at >= CONCAT(?, ' 00:00:00') AND delivered_at <= CONCAT(?, ' 23:59:59') ORDER BY delivered_at, id{$lockSql}");
        $stmt->bind_param('iiiss', $workerId, $tenant, $branch, $start, $end);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function basePay(mysqli $conn, array $worker, string $start, string $end, int $tenant, int $branch): Money
    {
        $planId = (int) ($worker['compensation_plan_id'] ?? 0);
        if ($planId < 1) {
            return Money::zero();
        }
        $plan = (new DeliveryCompensationService())->getPlan($conn, $planId, $tenant, $branch);
        $amount = Money::from($plan['base_amount']);
        $eligibleStart = max($start, (string) $plan['effective_from']);
        $eligibleEnd = empty($plan['effective_to']) ? $end : min($end, (string) $plan['effective_to']);
        if ($eligibleEnd < $eligibleStart) {
            return Money::zero();
        }
        $from = new DateTimeImmutable($eligibleStart);
        $to = (new DateTimeImmutable($eligibleEnd))->modify('+1 day');
        $days = (int) $from->diff($to)->days;
        switch ($plan['base_period']) {
            case 'daily': return $this->prorate($amount, $days, 1);
            case 'weekly': return $this->prorate($amount, $days, 7);
            case 'monthly':
                $basePay = Money::zero();
                $cursor = $from->modify('first day of this month');
                $inclusiveEnd = $to->modify('-1 day');
                while ($cursor <= $inclusiveEnd) {
                    $monthEnd = $cursor->modify('last day of this month');
                    $overlapStart = $from > $cursor ? $from : $cursor;
                    $overlapEnd = $inclusiveEnd < $monthEnd ? $inclusiveEnd : $monthEnd;
                    $eligibleDays = (int) $overlapStart->diff($overlapEnd->modify('+1 day'))->days;
                    $basePay = $basePay->add($this->prorate($amount, $eligibleDays, (int) $cursor->format('t')));
                    $cursor = $cursor->modify('first day of next month');
                }
                return $basePay;
            default: return Money::zero();
        }
    }

    private function insertLine(mysqli $conn, int $settlementId, string $type, ?int $financialId, ?int $orderId, string $amount, string $description, ?array $metadata, array $context): void
    {
        $uuid = $this->uuid();
        $metadataJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stmt = $conn->prepare('INSERT INTO delivery_settlement_lines (uuid, settlement_id, line_type, order_financial_id, order_id, amount, description, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sisiisss', $uuid, $settlementId, $type, $financialId, $orderId, $amount, $description, $metadataJson);
        $stmt->execute();
        $lineId = (int) $conn->insert_id;
        $stmt->close();
        $this->sync($conn, 'delivery_settlement_line', $lineId, $context);
    }

    private function assertOpenDrawer(mysqli $conn, int $sessionId, int $tenant, int $branch): void
    {
        if ($sessionId < 1) {
            throw new InvalidArgumentException('DELIVERY_SETTLEMENT_OPEN_DRAWER_REQUIRED');
        }
        $stmt = $conn->prepare("SELECT id FROM drawer_sessions WHERE id = ? AND tenant = ? AND branch = ? AND status = 'open' LIMIT 1 FOR UPDATE");
        $stmt->bind_param('iii', $sessionId, $tenant, $branch);
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$found) {
            throw new InvalidArgumentException('DELIVERY_SETTLEMENT_OPEN_DRAWER_REQUIRED');
        }
    }

    private function recordDrawerMovement(mysqli $conn, int $settlementId, int $workerId, int $sessionId, Money $net, int $userId, int $tenant, int $branch, array $options): int
    {
        $type = $net->isPositive() ? 'paid_out' : 'paid_in';
        $absolute = $net->isNegative() ? Money::zero()->subtract($net) : $net;
        $amount = $this->format($absolute);
        $reason = $net->isPositive() ? 'صرف تسوية عامل توصيل' : 'توريد نقدية من عامل توصيل';
        $stmt = $conn->prepare('INSERT INTO drawer_movements (drawer_session_id, tenant, branch, movement_type, amount, delivery_worker_id, delivery_settlement_id, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iiissiisi', $sessionId, $tenant, $branch, $type, $amount, $workerId, $settlementId, $reason, $userId);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();
        $this->sync($conn, 'drawer_movement', $id, $options);
        return $id;
    }

    private function sum(array $rows, string $column): Money
    {
        $total = Money::zero();
        foreach ($rows as $row) {
            $total = $total->add(Money::from($row[$column] ?? '0'));
        }
        return $total;
    }

    private function amount($value): Money
    {
        return Money::from($value);
    }

    private function period(string $start, string $end): array
    {
        foreach ([$start, $end] as $value) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('DELIVERY_DATE_INVALID');
            }
        }
        if ($end < $start) {
            throw new InvalidArgumentException('DELIVERY_SETTLEMENT_PERIOD_INVALID');
        }
        return [$start, $end];
    }

    private function normalizeSettlement(array $row): array
    {
        foreach (['id', 'worker_id', 'fund_account_id', 'drawer_session_id', 'drawer_movement_id', 'journal_head_id', 'finalized_by', 'tenant', 'branch'] as $field) {
            $row[$field] = isset($row[$field]) && $row[$field] !== null ? (int) $row[$field] : null;
        }
        return $row;
    }

    private function format(Money $value): string { return $value->toString() . '0'; }

    private function prorate(Money $amount, int $units, int $divisor): Money
    {
        if ($units <= 0 || $divisor < 1) {
            return Money::zero();
        }
        [$whole, $fraction] = explode('.', $amount->toString(), 2);
        $cents = ((int) $whole * 100) + (int) $fraction;
        $roundedCents = intdiv(($cents * $units) + intdiv($divisor, 2), $divisor);
        return Money::from(intdiv($roundedCents, 100) . '.' . str_pad((string) ($roundedCents % 100), 2, '0', STR_PAD_LEFT));
    }
    private function nullableText($value, int $length): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') return null;
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
    private function sync(mysqli $conn, string $domain, int $id, array $context): void
    {
        (new OperationalSyncEventService())->recordRowSnapshot($conn, $domain, $id, ['source_system' => 'delivery_operations', 'config' => $context['config'] ?? null]);
    }
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
