<?php

require_once __DIR__ . '/OrderEventService.php';
require_once __DIR__ . '/../../Sync/OperationalSyncEventService.php';

final class DeliveryWorkerService
{
    public function listWorkers(mysqli $conn, array $scope = [], bool $includeInactive = false, bool $includeFinancials = false): array
    {
        $tenant = max(0, (int) ($scope['tenant'] ?? 0));
        $branch = max(0, (int) ($scope['branch'] ?? 0));
        $activeSql = $includeInactive ? '' : ' AND w.is_active = 1';
        $financialSql = $includeFinancials ? ",
                   COALESCE(financials.open_delivery_earnings, 0) AS open_delivery_earnings,
                   COALESCE(financials.open_tips, 0) AS open_tips,
                   COALESCE(financials.cod_held, 0) AS cod_held,
                   COALESCE(financials.open_net_amount, 0) AS open_net_amount" : '';
        $financialJoin = $includeFinancials ? "
            LEFT JOIN (
                SELECT worker_id, tenant, branch,
                       SUM(compensation_amount) AS open_delivery_earnings,
                       SUM(tip_amount) AS open_tips,
                       SUM(cod_amount) AS cod_held,
                       SUM(compensation_amount + tip_amount - cod_amount) AS open_net_amount
                FROM delivery_order_financials
                WHERE status = 'open'
                GROUP BY worker_id, tenant, branch
            ) financials ON financials.worker_id = w.id
                        AND financials.tenant = w.tenant
                        AND financials.branch = w.branch" : '';
        $stmt = $conn->prepare("
            SELECT w.*, p.name AS compensation_plan_name,
                   COALESCE(active_orders.order_count, 0) AS active_orders
                   {$financialSql}
            FROM delivery_workers w
            LEFT JOIN delivery_compensation_plans p ON p.id = w.compensation_plan_id
            LEFT JOIN (
                SELECT delivery_worker_id, COUNT(*) AS order_count
                FROM order_fulfillment
                WHERE fulfillment_type = 'delivery'
                  AND delivery_status NOT IN ('delivered','cancelled','failed','none')
                  AND delivery_worker_id IS NOT NULL
                GROUP BY delivery_worker_id
            ) active_orders ON active_orders.delivery_worker_id = w.id
            {$financialJoin}
            WHERE w.tenant = ? AND w.branch = ? {$activeSql}
            ORDER BY w.is_active DESC, w.is_available DESC, w.name ASC
        ");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map([$this, 'normalizeWorker'], $rows);
    }

    public function saveWorker(mysqli $conn, array $data, array $context = []): array
    {
        $id = max(0, (int) ($data['id'] ?? 0));
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('DELIVERY_WORKER_NAME_REQUIRED');
        }
        $phone = $this->nullableText($data['phone'] ?? null, 60);
        $notes = $this->nullableText($data['notes'] ?? null, 500);
        $planId = max(0, (int) ($data['compensation_plan_id'] ?? 0)) ?: null;
        $isAvailable = !array_key_exists('is_available', $data) || !empty($data['is_available']) ? 1 : 0;
        $isActive = !array_key_exists('is_active', $data) || !empty($data['is_active']) ? 1 : 0;
        $tenant = max(0, (int) ($context['tenant'] ?? $data['tenant'] ?? 0));
        $branch = max(0, (int) ($context['branch'] ?? $data['branch'] ?? 0));
        $userId = max(0, (int) ($context['user_id'] ?? 0));

        if ($planId !== null) {
            $this->assertPlanInScope($conn, $planId, $tenant, $branch);
        }

        if ($id > 0) {
            $this->assertWorkerInScope($conn, $id, $tenant, $branch, true);
            $stmt = $conn->prepare('UPDATE delivery_workers SET name = ?, phone = ?, compensation_plan_id = ?, is_active = ?, is_available = ?, notes = ? WHERE id = ? AND tenant = ? AND branch = ?');
            $stmt->bind_param('ssiiisiii', $name, $phone, $planId, $isActive, $isAvailable, $notes, $id, $tenant, $branch);
            $stmt->execute();
            $stmt->close();
        } else {
            $uuid = $this->uuid();
            $stmt = $conn->prepare('INSERT INTO delivery_workers (uuid, name, phone, compensation_plan_id, is_active, is_available, notes, tenant, branch, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssiiisiii', $uuid, $name, $phone, $planId, $isActive, $isAvailable, $notes, $tenant, $branch, $userId);
            $stmt->execute();
            $id = (int) $conn->insert_id;
            $stmt->close();
        }

        $this->sync($conn, 'delivery_worker', $id, $context);

        return $this->getWorker($conn, $id, $tenant, $branch);
    }

    public function getWorker(mysqli $conn, int $id, int $tenant, int $branch, bool $forUpdate = false): array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $stmt = $conn->prepare('SELECT * FROM delivery_workers WHERE id = ? AND tenant = ? AND branch = ? LIMIT 1' . $lock);
        $stmt->bind_param('iii', $id, $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new InvalidArgumentException('DELIVERY_WORKER_NOT_FOUND');
        }

        return $this->normalizeWorker($row);
    }

    public function assignOrder(mysqli $conn, int $orderId, ?int $workerId, array $context = []): array
    {
        $ownsTransaction = empty($context['in_transaction']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $result = $this->assignOrderInsideTransaction($conn, $orderId, $workerId, $context);
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

    private function assignOrderInsideTransaction(mysqli $conn, int $orderId, ?int $workerId, array $context): array
    {
        if ($orderId < 1) {
            throw new InvalidArgumentException('ORDER_ID_REQUIRED');
        }
        $tenant = max(0, (int) ($context['tenant'] ?? 0));
        $branch = max(0, (int) ($context['branch'] ?? 0));
        $userId = max(0, (int) ($context['user_id'] ?? $context['actor_user_id'] ?? 0));
        $hasOrderScope = $this->tableColumnExists($conn, 'ot_head', 'tenant')
            && $this->tableColumnExists($conn, 'ot_head', 'branch');
        if ($hasOrderScope) {
            $stmt = $conn->prepare("SELECT f.* FROM order_fulfillment f INNER JOIN ot_head o ON o.id = f.order_id WHERE f.order_id = ? AND o.tenant = ? AND o.branch = ? AND f.fulfillment_type = 'delivery' LIMIT 1 FOR UPDATE");
            $stmt->bind_param('iii', $orderId, $tenant, $branch);
        } else {
            // Older routed shop databases do not carry tenant/branch on ot_head.
            // In that layout the database itself is the isolation boundary.
            $stmt = $conn->prepare("SELECT * FROM order_fulfillment WHERE order_id = ? AND fulfillment_type = 'delivery' LIMIT 1 FOR UPDATE");
            $stmt->bind_param('i', $orderId);
        }
        $stmt->execute();
        $fulfillment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fulfillment) {
            throw new InvalidArgumentException('DELIVERY_ORDER_NOT_FOUND');
        }
        if (in_array((string) $fulfillment['delivery_status'], ['picked_up', 'delivered', 'cancelled', 'failed'], true)) {
            throw new InvalidArgumentException('DELIVERY_ASSIGNMENT_LOCKED');
        }

        $worker = null;
        $workerId = $workerId !== null ? max(0, $workerId) : 0;
        if ($workerId > 0) {
            $worker = $this->getWorker($conn, $workerId, $tenant, $branch, true);
            if (empty($worker['is_active']) || empty($worker['is_available'])) {
                throw new InvalidArgumentException('DELIVERY_WORKER_UNAVAILABLE');
            }
        }
        $currentWorkerId = (int) ($fulfillment['delivery_worker_id'] ?? 0);
        if ($currentWorkerId === $workerId) {
            return ['order_id' => $orderId, 'worker' => $worker, 'changed' => false];
        }

        $reason = $workerId > 0 ? 'reassigned' : 'unassigned';
        $openAssignments = [];
        $assignmentRows = $conn->prepare('SELECT id FROM delivery_assignments WHERE order_id = ? AND tenant = ? AND branch = ? AND ended_at IS NULL FOR UPDATE');
        $assignmentRows->bind_param('iii', $orderId, $tenant, $branch);
        $assignmentRows->execute();
        $assignmentResult = $assignmentRows->get_result();
        while ($assignmentRow = $assignmentResult->fetch_assoc()) {
            $openAssignments[] = (int) $assignmentRow['id'];
        }
        $assignmentRows->close();
        $close = $conn->prepare('UPDATE delivery_assignments SET ended_at = NOW(), ended_by = ?, end_reason = ? WHERE order_id = ? AND tenant = ? AND branch = ? AND ended_at IS NULL');
        $close->bind_param('isiii', $userId, $reason, $orderId, $tenant, $branch);
        $close->execute();
        $close->close();
        foreach ($openAssignments as $closedAssignmentId) {
            $this->sync($conn, 'delivery_assignment', $closedAssignmentId, $context);
        }

        $assignedAt = null;
        if ($workerId > 0) {
            $uuid = $this->uuid();
            $insert = $conn->prepare('INSERT INTO delivery_assignments (uuid, order_id, worker_id, assigned_by, tenant, branch) VALUES (?, ?, ?, ?, ?, ?)');
            $insert->bind_param('siiiii', $uuid, $orderId, $workerId, $userId, $tenant, $branch);
            $insert->execute();
            $assignmentId = (int) $conn->insert_id;
            $insert->close();
            $assignedAt = date('Y-m-d H:i:s');
            $this->sync($conn, 'delivery_assignment', $assignmentId, $context);
        }

        $fulfillmentId = (int) $fulfillment['id'];
        $update = $conn->prepare('UPDATE order_fulfillment SET delivery_worker_id = NULLIF(?, 0), assigned_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->bind_param('isi', $workerId, $assignedAt, $fulfillmentId);
        $update->execute();
        $update->close();

        (new OrderEventService())->recordIfAvailable($conn, $orderId, 'delivery.worker_assigned', 'delivery_dispatch', [
            'actor_user_id' => $userId,
            'tenant' => $tenant,
            'branch' => $branch,
            'before_state' => ['delivery_worker_id' => $currentWorkerId ?: null],
            'after_state' => ['delivery_worker_id' => $workerId ?: null],
            'metadata' => ['worker_name' => $worker['name'] ?? null],
            'sync_config' => $context['config'] ?? null,
        ]);
        $this->sync($conn, 'order_fulfillment', (int) $fulfillment['id'], $context);

        return ['order_id' => $orderId, 'worker' => $worker, 'changed' => true];
    }

    private function assertPlanInScope(mysqli $conn, int $id, int $tenant, int $branch): void
    {
        $stmt = $conn->prepare('SELECT id FROM delivery_compensation_plans WHERE id = ? AND tenant = ? AND branch = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('iii', $id, $tenant, $branch);
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$found) {
            throw new InvalidArgumentException('DELIVERY_COMPENSATION_PLAN_NOT_FOUND');
        }
    }

    private function assertWorkerInScope(mysqli $conn, int $id, int $tenant, int $branch, bool $forUpdate): void
    {
        $this->getWorker($conn, $id, $tenant, $branch, $forUpdate);
    }

    private function normalizeWorker(array $row): array
    {
        foreach (['id', 'compensation_plan_id', 'is_active', 'is_available', 'tenant', 'branch', 'created_by', 'active_orders'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $row[$column] !== null ? (int) $row[$column] : null;
            }
        }
        foreach (['open_delivery_earnings', 'open_tips', 'cod_held', 'open_net_amount'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = number_format((float) $row[$column], 3, '.', '');
            }
        }
        return $row;
    }

    private function sync(mysqli $conn, string $domain, int $id, array $context): void
    {
        (new OperationalSyncEventService())->recordRowSnapshot($conn, $domain, $id, [
            'source_system' => 'delivery_operations',
            'config' => $context['config'] ?? null,
        ]);
    }

    private function nullableText($value, int $length): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private function tableColumnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
        $stmt->close();
        return $exists;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
