<?php

require_once __DIR__ . '/../../Sync/OperationalSyncEventService.php';

final class DeliveryCompensationService
{
    private const BASE_PERIODS = ['none', 'daily', 'weekly', 'monthly'];
    private const METHODS = ['none', 'customer_fee', 'fixed', 'percentage', 'zone_rate'];
    private const TIP_MODES = ['none', 'pass_through'];

    public function listPlans(mysqli $conn, array $scope = [], bool $includeInactive = false): array
    {
        $tenant = max(0, (int) ($scope['tenant'] ?? 0));
        $branch = max(0, (int) ($scope['branch'] ?? 0));
        $activeSql = $includeInactive ? '' : ' AND p.is_active = 1';
        $stmt = $conn->prepare("SELECT p.* FROM delivery_compensation_plans p WHERE p.tenant = ? AND p.branch = ? {$activeSql} ORDER BY p.is_active DESC, p.effective_from DESC, p.name ASC");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $plans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($plans as &$plan) {
            $plan = $this->normalizePlan($plan);
            $plan['zone_rates'] = $this->zoneRates($conn, (int) $plan['id']);
        }
        unset($plan);
        return $plans;
    }

    public function savePlan(mysqli $conn, array $data, array $context = []): array
    {
        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $result = $this->savePlanInsideTransaction($conn, $data, $context);
            if ($ownsTransaction) {
                $conn->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    private function savePlanInsideTransaction(mysqli $conn, array $data, array $context): array
    {
        $id = max(0, (int) ($data['id'] ?? 0));
        $tenant = max(0, (int) ($context['tenant'] ?? $data['tenant'] ?? 0));
        $branch = max(0, (int) ($context['branch'] ?? $data['branch'] ?? 0));
        $userId = max(0, (int) ($context['user_id'] ?? 0));
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('DELIVERY_PLAN_NAME_REQUIRED');
        }
        $basePeriod = $this->enum($data['base_period'] ?? 'none', self::BASE_PERIODS, 'DELIVERY_PLAN_BASE_PERIOD_INVALID');
        $method = $this->enum($data['per_delivery_method'] ?? 'customer_fee', self::METHODS, 'DELIVERY_PLAN_METHOD_INVALID');
        $tipsMode = $this->enum($data['tips_mode'] ?? 'none', self::TIP_MODES, 'DELIVERY_PLAN_TIPS_MODE_INVALID');
        $baseAmount = $this->amount($data['base_amount'] ?? 0);
        $perValue = $this->amount($data['per_delivery_value'] ?? 0);
        $effectiveFrom = $this->date($data['effective_from'] ?? date('Y-m-d'));
        $effectiveTo = trim((string) ($data['effective_to'] ?? '')) !== '' ? $this->date($data['effective_to']) : null;
        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw new InvalidArgumentException('DELIVERY_PLAN_DATE_RANGE_INVALID');
        }
        $isActive = !array_key_exists('is_active', $data) || !empty($data['is_active']) ? 1 : 0;

        if ($id > 0) {
            $used = $conn->prepare('SELECT COUNT(*) AS c FROM delivery_order_financials WHERE plan_id = ?');
            $used->bind_param('i', $id);
            $used->execute();
            $count = (int) ($used->get_result()->fetch_assoc()['c'] ?? 0);
            $used->close();
            if ($count > 0) {
                throw new InvalidArgumentException('DELIVERY_PLAN_VERSION_LOCKED');
            }
            $stmt = $conn->prepare('UPDATE delivery_compensation_plans SET name = ?, base_period = ?, base_amount = ?, per_delivery_method = ?, per_delivery_value = ?, tips_mode = ?, effective_from = ?, effective_to = ?, is_active = ? WHERE id = ? AND tenant = ? AND branch = ?');
            $stmt->bind_param('ssssssssiiii', $name, $basePeriod, $baseAmount, $method, $perValue, $tipsMode, $effectiveFrom, $effectiveTo, $isActive, $id, $tenant, $branch);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                $check = $conn->prepare('SELECT id FROM delivery_compensation_plans WHERE id = ? AND tenant = ? AND branch = ?');
                $check->bind_param('iii', $id, $tenant, $branch);
                $check->execute();
                $exists = (bool) $check->get_result()->fetch_assoc();
                $check->close();
                if (!$exists) {
                    throw new InvalidArgumentException('DELIVERY_PLAN_NOT_FOUND');
                }
            }
            $stmt->close();
        } else {
            $uuid = $this->uuid();
            $stmt = $conn->prepare('INSERT INTO delivery_compensation_plans (uuid, name, base_period, base_amount, per_delivery_method, per_delivery_value, tips_mode, effective_from, effective_to, is_active, tenant, branch, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssssssssiiii', $uuid, $name, $basePeriod, $baseAmount, $method, $perValue, $tipsMode, $effectiveFrom, $effectiveTo, $isActive, $tenant, $branch, $userId);
            $stmt->execute();
            $id = (int) $conn->insert_id;
            $stmt->close();
        }

        if (isset($data['zone_rates']) && is_array($data['zone_rates'])) {
            foreach ($data['zone_rates'] as $zoneId => $rate) {
                $zoneId = is_array($rate) ? (int) ($rate['zone_id'] ?? 0) : (int) $zoneId;
                if ($zoneId < 1) {
                    continue;
                }
                $zone = $conn->prepare('SELECT id FROM delivery_zones WHERE id = ? AND tenant = ? AND branch = ? LIMIT 1');
                $zone->bind_param('iii', $zoneId, $tenant, $branch);
                $zone->execute();
                $zoneExists = (bool) $zone->get_result()->fetch_assoc();
                $zone->close();
                if (!$zoneExists) {
                    throw new InvalidArgumentException('DELIVERY_ZONE_INVALID');
                }
            }
            $removedRateIds = [];
            $oldRates = $conn->query('SELECT id FROM delivery_compensation_zone_rates WHERE plan_id = ' . $id);
            while ($oldRate = $oldRates->fetch_assoc()) {
                $removedRateIds[] = (int) $oldRate['id'];
            }
            $delete = $conn->prepare('DELETE FROM delivery_compensation_zone_rates WHERE plan_id = ?');
            $delete->bind_param('i', $id);
            $delete->execute();
            $delete->close();
            foreach ($removedRateIds as $removedRateId) {
                $this->syncDelete($conn, 'delivery_compensation_zone_rate', $removedRateId, $context);
            }
            foreach ($data['zone_rates'] as $zoneId => $rate) {
                $zoneId = is_array($rate) ? (int) ($rate['zone_id'] ?? 0) : (int) $zoneId;
                $amount = $this->amount(is_array($rate) ? ($rate['amount'] ?? 0) : $rate);
                if ($zoneId < 1) {
                    continue;
                }
                $uuid = $this->uuid();
                $insert = $conn->prepare('INSERT INTO delivery_compensation_zone_rates (uuid, plan_id, zone_id, amount, tenant, branch) VALUES (?, ?, ?, ?, ?, ?)');
                $insert->bind_param('siisii', $uuid, $id, $zoneId, $amount, $tenant, $branch);
                $insert->execute();
                $rateId = (int) $conn->insert_id;
                $insert->close();
                $this->sync($conn, 'delivery_compensation_zone_rate', $rateId, $context);
            }
        }
        $this->sync($conn, 'delivery_compensation_plan', $id, $context);

        return $this->getPlan($conn, $id, $tenant, $branch);
    }

    public function accrueDeliveredOrder(mysqli $conn, int $orderId, array $context = []): ?array
    {
        $existing = $conn->prepare('SELECT * FROM delivery_order_financials WHERE order_id = ? LIMIT 1');
        $existing->bind_param('i', $orderId);
        $existing->execute();
        $row = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($row) {
            return $this->normalizeFinancial($row);
        }

        $stmt = $conn->prepare("SELECT f.*, w.compensation_plan_id, w.tenant AS worker_tenant, w.branch AS worker_branch FROM order_fulfillment f LEFT JOIN delivery_workers w ON w.id = f.delivery_worker_id WHERE f.order_id = ? AND f.fulfillment_type = 'delivery' LIMIT 1 FOR UPDATE");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $fulfillment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fulfillment || (string) $fulfillment['delivery_status'] !== 'delivered') {
            throw new InvalidArgumentException('DELIVERY_NOT_DELIVERED');
        }
        $workerId = (int) ($fulfillment['delivery_worker_id'] ?? 0);
        if ($workerId < 1 || (string) ($fulfillment['courier_source'] ?? 'in_house') !== 'in_house') {
            return null;
        }
        $tenant = max(0, (int) ($fulfillment['worker_tenant'] ?? $context['tenant'] ?? 0));
        $branch = max(0, (int) ($fulfillment['worker_branch'] ?? $context['branch'] ?? 0));
        $planId = max(0, (int) ($fulfillment['compensation_plan_id'] ?? 0));
        $plan = $planId > 0 ? $this->getPlan($conn, $planId, $tenant, $branch) : null;
        $deliveredAt = (string) ($fulfillment['delivered_at'] ?: date('Y-m-d H:i:s'));
        $planApplies = $plan && $this->planAppliesOn($plan, substr($deliveredAt, 0, 10));
        $fee = $this->amount($fulfillment['delivery_fee'] ?? 0);
        $tip = $this->amount($fulfillment['driver_tip'] ?? 0);
        $cod = $this->amount($fulfillment['cod_amount'] ?? 0);
        $compensation = $this->calculateOrderCompensation($conn, $planApplies ? $plan : null, (int) ($fulfillment['delivery_zone_id'] ?? 0), $fee);
        $tipPayable = $planApplies && $plan['tips_mode'] === 'pass_through' ? $tip : '0.000';
        $snapshot = $plan ? json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $uuid = $this->uuid();
        $insert = $conn->prepare('INSERT INTO delivery_order_financials (uuid, order_id, worker_id, plan_id, customer_delivery_fee, compensation_amount, tip_amount, cod_amount, plan_snapshot_json, delivered_at, tenant, branch) VALUES (?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->bind_param('siiissssssii', $uuid, $orderId, $workerId, $planId, $fee, $compensation, $tipPayable, $cod, $snapshot, $deliveredAt, $tenant, $branch);
        $insert->execute();
        $id = (int) $conn->insert_id;
        $insert->close();
        $this->sync($conn, 'delivery_order_financial', $id, $context);

        $result = $conn->query('SELECT * FROM delivery_order_financials WHERE id = ' . $id)->fetch_assoc();
        return $this->normalizeFinancial($result);
    }

    public function calculateOrderCompensation(mysqli $conn, ?array $plan, int $zoneId, string $customerFee): string
    {
        if (!$plan) {
            return '0.000';
        }
        $value = (float) $plan['per_delivery_value'];
        switch ($plan['per_delivery_method']) {
            case 'customer_fee': return $this->amount($customerFee);
            case 'fixed': return $this->amount($value);
            case 'percentage': return $this->amount(((float) $customerFee) * $value / 100);
            case 'zone_rate':
                foreach ($plan['zone_rates'] as $rate) {
                    if ((int) $rate['zone_id'] === $zoneId) {
                        return $this->amount($rate['amount']);
                    }
                }
                return '0.000';
            default: return '0.000';
        }
    }

    public function getPlan(mysqli $conn, int $id, int $tenant, int $branch): array
    {
        $stmt = $conn->prepare('SELECT * FROM delivery_compensation_plans WHERE id = ? AND tenant = ? AND branch = ? LIMIT 1');
        $stmt->bind_param('iii', $id, $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new InvalidArgumentException('DELIVERY_PLAN_NOT_FOUND');
        }
        $row = $this->normalizePlan($row);
        $row['zone_rates'] = $this->zoneRates($conn, $id);
        return $row;
    }

    private function zoneRates(mysqli $conn, int $planId): array
    {
        $stmt = $conn->prepare('SELECT r.*, z.name AS zone_name FROM delivery_compensation_zone_rates r LEFT JOIN delivery_zones z ON z.id = r.zone_id WHERE r.plan_id = ? ORDER BY z.sort_order, z.name');
        $stmt->bind_param('i', $planId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['zone_id'] = (int) $row['zone_id'];
            $row['amount'] = $this->amount($row['amount']);
        }
        return $rows;
    }

    private function normalizePlan(array $row): array
    {
        foreach (['id', 'is_active', 'tenant', 'branch', 'created_by'] as $field) {
            $row[$field] = isset($row[$field]) ? (int) $row[$field] : 0;
        }
        $row['base_amount'] = $this->amount($row['base_amount'] ?? 0);
        $row['per_delivery_value'] = $this->amount($row['per_delivery_value'] ?? 0);
        return $row;
    }

    private function normalizeFinancial(array $row): array
    {
        foreach (['id', 'order_id', 'worker_id', 'plan_id', 'settlement_id', 'tenant', 'branch'] as $field) {
            $row[$field] = isset($row[$field]) && $row[$field] !== null ? (int) $row[$field] : null;
        }
        foreach (['customer_delivery_fee', 'compensation_amount', 'tip_amount', 'cod_amount'] as $field) {
            $row[$field] = $this->amount($row[$field] ?? 0);
        }
        $row['plan_snapshot'] = !empty($row['plan_snapshot_json']) ? json_decode((string) $row['plan_snapshot_json'], true) : null;
        return $row;
    }

    private function planAppliesOn(array $plan, string $date): bool
    {
        return $date >= (string) $plan['effective_from']
            && (empty($plan['effective_to']) || $date <= (string) $plan['effective_to']);
    }

    private function enum($value, array $allowed, string $errorCode): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($errorCode);
        }
        return $value;
    }

    private function amount($value): string
    {
        // POSMAIN journals use currency precision (Money::SCALE = 2). Store the
        // third schema decimal as zero so per-order accruals and settlements can
        // never accumulate a sub-cent payable that the ledger cannot represent.
        $number = round((float) $value, 2);
        if ($number < 0) {
            throw new InvalidArgumentException('DELIVERY_AMOUNT_INVALID');
        }
        return number_format($number, 3, '.', '');
    }

    private function date($value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) $value));
        if (!$date || $date->format('Y-m-d') !== trim((string) $value)) {
            throw new InvalidArgumentException('DELIVERY_DATE_INVALID');
        }
        return $date->format('Y-m-d');
    }

    private function sync(mysqli $conn, string $domain, int $id, array $context): void
    {
        (new OperationalSyncEventService())->recordRowSnapshot($conn, $domain, $id, ['source_system' => 'delivery_operations', 'config' => $context['config'] ?? null]);
    }

    private function syncDelete(mysqli $conn, string $domain, int $id, array $context): void
    {
        (new OperationalSyncEventService())->recordRowDelete($conn, $domain, $id, ['source_system' => 'delivery_operations', 'config' => $context['config'] ?? null]);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
