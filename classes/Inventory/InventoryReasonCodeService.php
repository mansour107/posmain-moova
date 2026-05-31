<?php

class InventoryReasonCodeService
{
    private const GROUPS = ['count', 'waste', 'adjustment', 'transfer_variance', 'purchase_return', 'production_variance', 'manual'];
    private const DIRECTIONS = ['in', 'out', 'both', 'none'];

    public function listAll(mysqli $conn, array $scope, bool $includeInactive = true): array
    {
        if (!$this->tableExists($conn, 'inventory_reason_codes')) {
            return [];
        }

        $posTenant = (int) ($scope['pos_tenant'] ?? 0);
        $posBranch = (int) ($scope['pos_branch'] ?? 0);
        $activeSql = $includeInactive ? '' : ' AND is_active = 1';
        $stmt = $conn->prepare("
SELECT id, pos_tenant, pos_branch, reason_code, reason_name, reason_group, direction, requires_approval, is_system, is_active, created_at, updated_at
FROM inventory_reason_codes
WHERE ((pos_tenant = ? AND pos_branch = ?) OR (pos_tenant = 0 AND pos_branch = 0))
{$activeSql}
ORDER BY is_active DESC, is_system DESC, reason_group ASC, direction ASC, reason_name ASC, id ASC");
        $stmt->bind_param('ii', $posTenant, $posBranch);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $row['editable'] = (int) ($row['is_system'] ?? 0) === 0
                && (int) ($row['pos_tenant'] ?? 0) === $posTenant
                && (int) ($row['pos_branch'] ?? 0) === $posBranch;
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    public function save(mysqli $conn, array $scope, array $request, array $context = []): array
    {
        $this->assertReady($conn);

        $id = (int) ($request['id'] ?? 0);
        $posTenant = (int) ($scope['pos_tenant'] ?? 0);
        $posBranch = (int) ($scope['pos_branch'] ?? 0);
        $reasonCode = $this->normalizeReasonCode((string) ($request['reason_code'] ?? ''));
        $reasonName = trim((string) ($request['reason_name'] ?? ''));
        $reasonGroup = strtolower(trim((string) ($request['reason_group'] ?? 'manual')));
        $direction = strtolower(trim((string) ($request['direction'] ?? 'both')));
        $requiresApproval = !empty($request['requires_approval']) ? 1 : 0;
        $isActive = array_key_exists('is_active', $request) ? (!empty($request['is_active']) ? 1 : 0) : 1;

        if ($reasonCode === '') {
            throw new InvalidArgumentException('REASON_CODE_REQUIRED');
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9_:-]{0,63}$/', $reasonCode)) {
            throw new InvalidArgumentException('REASON_CODE_INVALID');
        }
        if ($reasonName === '') {
            throw new InvalidArgumentException('REASON_NAME_REQUIRED');
        }
        if (!in_array($reasonGroup, self::GROUPS, true)) {
            throw new InvalidArgumentException('REASON_GROUP_INVALID');
        }
        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new InvalidArgumentException('REASON_DIRECTION_INVALID');
        }

        if ($id > 0) {
            $current = $this->adminRow($conn, $id, $posTenant, $posBranch);
            if (!$current) {
                throw new InvalidArgumentException('REASON_CODE_NOT_FOUND');
            }
            if ((int) ($current['is_system'] ?? 0) === 1) {
                throw new RuntimeException('SYSTEM_REASON_CODE_LOCKED');
            }
            $this->assertUniqueReasonCode($conn, $posTenant, $posBranch, $reasonCode, $id);

            $stmt = $conn->prepare("
UPDATE inventory_reason_codes
SET reason_code = ?,
    reason_name = ?,
    reason_group = ?,
    direction = ?,
    requires_approval = ?,
    is_active = ?
WHERE id = ?
  AND pos_tenant = ?
  AND pos_branch = ?
  AND is_system = 0");
            $stmt->bind_param('ssssiiiii', $reasonCode, $reasonName, $reasonGroup, $direction, $requiresApproval, $isActive, $id, $posTenant, $posBranch);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            return [
                'success' => true,
                'action' => 'updated',
                'id' => $id,
                'affected_rows' => $affected,
            ];
        }

        $this->assertUniqueReasonCode($conn, $posTenant, $posBranch, $reasonCode, 0);
        $stmt = $conn->prepare("
INSERT INTO inventory_reason_codes (pos_tenant, pos_branch, reason_code, reason_name, reason_group, direction, requires_approval, is_system, is_active)
VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)");
        $stmt->bind_param('iissssii', $posTenant, $posBranch, $reasonCode, $reasonName, $reasonGroup, $direction, $requiresApproval, $isActive);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        return [
            'success' => true,
            'action' => 'created',
            'id' => $newId,
        ];
    }

    public function setActive(mysqli $conn, array $scope, int $id, bool $isActive): array
    {
        $this->assertReady($conn);

        $posTenant = (int) ($scope['pos_tenant'] ?? 0);
        $posBranch = (int) ($scope['pos_branch'] ?? 0);
        $current = $this->adminRow($conn, $id, $posTenant, $posBranch);
        if (!$current) {
            throw new InvalidArgumentException('REASON_CODE_NOT_FOUND');
        }
        if ((int) ($current['is_system'] ?? 0) === 1) {
            throw new RuntimeException('SYSTEM_REASON_CODE_LOCKED');
        }

        $active = $isActive ? 1 : 0;
        $stmt = $conn->prepare("
UPDATE inventory_reason_codes
SET is_active = ?
WHERE id = ?
  AND pos_tenant = ?
  AND pos_branch = ?
  AND is_system = 0");
        $stmt->bind_param('iiii', $active, $id, $posTenant, $posBranch);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return [
            'success' => true,
            'action' => $isActive ? 'reactivated' : 'retired',
            'id' => $id,
            'affected_rows' => $affected,
        ];
    }

    public function listForOperation(mysqli $conn, array $scope, string $operation, string $direction = 'both'): array
    {
        if (!$this->tableExists($conn, 'inventory_reason_codes')) {
            return [];
        }

        $groups = $this->groupsForOperation($operation);
        $directions = $this->directionsForOperation($operation, $direction);
        $posTenant = (int) ($scope['pos_tenant'] ?? 0);
        $posBranch = (int) ($scope['pos_branch'] ?? 0);

        $groupMarks = implode(',', array_fill(0, count($groups), '?'));
        $directionMarks = implode(',', array_fill(0, count($directions), '?'));
        $sql = "
SELECT id, pos_tenant, pos_branch, reason_code, reason_name, reason_group, direction, requires_approval, is_system
FROM inventory_reason_codes
WHERE is_active = 1
  AND ((pos_tenant = ? AND pos_branch = ?) OR (pos_tenant = 0 AND pos_branch = 0))
  AND reason_group IN ({$groupMarks})
  AND direction IN ({$directionMarks})
ORDER BY is_system DESC, reason_group ASC, reason_name ASC, id ASC";

        $types = 'ii' . str_repeat('s', count($groups) + count($directions));
        $values = array_merge([$posTenant, $posBranch], $groups, $directions);
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    public function validateForOperation(mysqli $conn, $reasonCodeId, array $scope, string $operation, string $direction, bool $canApprove = false): ?array
    {
        $id = (int) $reasonCodeId;
        if ($id < 1) {
            return null;
        }
        if (!$this->tableExists($conn, 'inventory_reason_codes')) {
            throw new InvalidArgumentException('REASON_CODE_NOT_FOUND');
        }

        $posTenant = (int) ($scope['pos_tenant'] ?? 0);
        $posBranch = (int) ($scope['pos_branch'] ?? 0);
        $stmt = $conn->prepare("
SELECT id, pos_tenant, pos_branch, reason_code, reason_name, reason_group, direction, requires_approval, is_active, is_system
FROM inventory_reason_codes
WHERE id = ?
  AND ((pos_tenant = ? AND pos_branch = ?) OR (pos_tenant = 0 AND pos_branch = 0))
LIMIT 1");
        $stmt->bind_param('iii', $id, $posTenant, $posBranch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || (int) ($row['is_active'] ?? 0) !== 1) {
            throw new InvalidArgumentException('REASON_CODE_NOT_FOUND');
        }
        if (!in_array((string) ($row['reason_group'] ?? ''), $this->groupsForOperation($operation), true)) {
            throw new InvalidArgumentException('REASON_CODE_GROUP_INVALID');
        }
        if (!in_array((string) ($row['direction'] ?? ''), $this->directionsForOperation($operation, $direction), true)) {
            throw new InvalidArgumentException('REASON_CODE_DIRECTION_INVALID');
        }
        if ((int) ($row['requires_approval'] ?? 0) === 1 && !$canApprove) {
            throw new RuntimeException('REASON_CODE_APPROVAL_REQUIRED');
        }

        return $row;
    }

    private function groupsForOperation(string $operation): array
    {
        $operation = strtolower(trim($operation));
        if ($operation === 'waste') {
            return ['waste', 'manual'];
        }
        if ($operation === 'adjustment') {
            return ['adjustment', 'manual'];
        }
        if ($operation === 'transfer_variance') {
            return ['transfer_variance', 'manual'];
        }

        return ['manual'];
    }

    private function directionsForOperation(string $operation, string $direction): array
    {
        $operation = strtolower(trim($operation));
        $direction = strtolower(trim($direction));
        if ($operation === 'waste') {
            return ['out', 'both'];
        }
        if ($direction === 'increase' || $direction === 'in') {
            return ['in', 'both'];
        }
        if ($direction === 'decrease' || $direction === 'out') {
            return ['out', 'both'];
        }

        return ['both'];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['table_count'] ?? 0) > 0;
    }

    private function assertReady(mysqli $conn): void
    {
        if (!$this->tableExists($conn, 'inventory_reason_codes')) {
            throw new RuntimeException('INVENTORY_REASON_CODES_NOT_READY');
        }
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        $reasonCode = preg_replace('/\s+/', '_', $reasonCode) ?? $reasonCode;
        return strtoupper($reasonCode);
    }

    private function adminRow(mysqli $conn, int $id, int $posTenant, int $posBranch): ?array
    {
        $stmt = $conn->prepare("
SELECT id, pos_tenant, pos_branch, reason_code, reason_name, reason_group, direction, requires_approval, is_system, is_active
FROM inventory_reason_codes
WHERE id = ?
  AND pos_tenant = ?
  AND pos_branch = ?
LIMIT 1");
        $stmt->bind_param('iii', $id, $posTenant, $posBranch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : null;
    }

    private function assertUniqueReasonCode(mysqli $conn, int $posTenant, int $posBranch, string $reasonCode, int $ignoreId): void
    {
        $stmt = $conn->prepare("
SELECT id
FROM inventory_reason_codes
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND reason_code = ?
  AND id <> ?
LIMIT 1");
        $stmt->bind_param('iisi', $posTenant, $posBranch, $reasonCode, $ignoreId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            throw new RuntimeException('REASON_CODE_DUPLICATE');
        }
    }
}
