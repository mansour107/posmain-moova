<?php

class ManagerApprovalService
{
    private const TERMINAL_STATUSES = ['approved', 'declined', 'expired'];

    public function requestApproval(mysqli $conn, array $data): array
    {
        $actionType = $this->requiredText($data['action_type'] ?? '', 80, 'APPROVAL_ACTION_REQUIRED');
        $targetType = $this->requiredText($data['target_type'] ?? '', 80, 'APPROVAL_TARGET_REQUIRED');
        $targetId = $this->optionalPositiveInt($data['target_id'] ?? null);
        $requestedBy = $this->positiveInt($data['requested_by'] ?? 0, 'APPROVAL_REQUESTER_REQUIRED');
        $reason = $this->nullableText($data['reason'] ?? null, 500);
        $metadataJson = $this->jsonOrNull($data['metadata'] ?? $data['metadata_json'] ?? null);

        $stmt = $conn->prepare("
            INSERT INTO manager_approvals (
                action_type, target_type, target_id, requested_by, reason, metadata_json
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssiiss', $actionType, $targetType, $targetId, $requestedBy, $reason, $metadataJson);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $this->approvalById($conn, $id, false);
    }

    public function decide(mysqli $conn, int $approvalId, array $data): array
    {
        $approvalId = $this->positiveInt($approvalId, 'APPROVAL_ID_REQUIRED');
        $approvedBy = $this->positiveInt($data['approved_by'] ?? $data['manager_id'] ?? 0, 'APPROVAL_MANAGER_REQUIRED');
        $status = strtolower(trim((string) ($data['status'] ?? 'approved')));
        if (!in_array($status, self::TERMINAL_STATUSES, true)) {
            throw new InvalidArgumentException('APPROVAL_STATUS_INVALID');
        }
        $reason = $this->nullableText($data['reason'] ?? null, 500);

        $stmt = $conn->prepare("
            UPDATE manager_approvals
            SET approved_by = ?,
                status = ?,
                reason = COALESCE(?, reason),
                decided_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->bind_param('issi', $approvedBy, $status, $reason, $approvalId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected < 1) {
            throw new RuntimeException('APPROVAL_NOT_FOUND');
        }

        return $this->approvalById($conn, $approvalId, false);
    }

    public function requireApprovedIfNeeded(
        mysqli $conn,
        string $actionType,
        string $targetType,
        ?int $targetId,
        float $amount,
        array $request = [],
        array $context = []
    ): ?array {
        if (!$this->shouldEnforce($actionType, $context)) {
            return null;
        }

        $threshold = $this->threshold($context);
        if ($amount <= $threshold) {
            return null;
        }

        $approvalId = $this->approvalIdFrom($request, $context);
        if ($approvalId === null) {
            throw new RuntimeException('MANAGER_APPROVAL_REQUIRED');
        }

        $approval = $this->approvalById($conn, $approvalId, true);
        if ((string) $approval['action_type'] !== $actionType || (string) $approval['target_type'] !== $targetType) {
            throw new RuntimeException('MANAGER_APPROVAL_SCOPE_MISMATCH');
        }
        if ((string) $approval['status'] !== 'approved' || (int) ($approval['approved_by'] ?? 0) <= 0) {
            throw new RuntimeException('MANAGER_APPROVAL_NOT_APPROVED');
        }

        $approvalTargetId = $approval['target_id'] !== null ? (int) $approval['target_id'] : null;
        if ($approvalTargetId !== null && ($targetId === null || $approvalTargetId !== $targetId)) {
            throw new RuntimeException('MANAGER_APPROVAL_TARGET_MISMATCH');
        }

        return $approval;
    }

    public function approvalById(mysqli $conn, int $approvalId, bool $forUpdate = false): array
    {
        $approvalId = $this->positiveInt($approvalId, 'APPROVAL_ID_REQUIRED');
        $sql = "SELECT * FROM manager_approvals WHERE id = ? LIMIT 1";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $approvalId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('APPROVAL_NOT_FOUND');
        }

        if (isset($row['metadata_json']) && $row['metadata_json'] !== null) {
            $row['metadata'] = json_decode((string) $row['metadata_json'], true);
        } else {
            $row['metadata'] = null;
        }

        return $row;
    }

    private function shouldEnforce(string $actionType, array $context): bool
    {
        if (array_key_exists('require_manager_approval', $context)) {
            return (bool) $context['require_manager_approval'];
        }
        if (array_key_exists('require_discount_approval', $context) && strpos($actionType, 'discount') !== false) {
            return (bool) $context['require_discount_approval'];
        }

        $global = getenv('POSMAIN_REQUIRE_MANAGER_APPROVAL');
        if ($this->truthy($global)) {
            return true;
        }
        $discount = getenv('POSMAIN_REQUIRE_DISCOUNT_APPROVAL');
        return strpos($actionType, 'discount') !== false && $this->truthy($discount);
    }

    private function threshold(array $context): float
    {
        if (array_key_exists('discount_approval_threshold', $context)) {
            return max(0, (float) $context['discount_approval_threshold']);
        }

        $value = getenv('POSMAIN_DISCOUNT_APPROVAL_THRESHOLD');
        return $value === false || $value === '' ? 0.0 : max(0, (float) $value);
    }

    private function approvalIdFrom(array $request, array $context): ?int
    {
        foreach (['manager_approval_id', 'approval_id', 'discount_approval_id'] as $key) {
            if (isset($request[$key]) && (int) $request[$key] > 0) {
                return (int) $request[$key];
            }
            if (isset($context[$key]) && (int) $context[$key] > 0) {
                return (int) $context[$key];
            }
        }

        return null;
    }

    private function truthy($value): bool
    {
        if ($value === false || $value === null || $value === '') {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function positiveInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 1) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function optionalPositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private function requiredText($value, int $maxLength, string $code): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException($code);
        }

        return $this->truncate($text, $maxLength);
    }

    private function nullableText($value, int $maxLength): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $this->truncate($text, $maxLength);
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function jsonOrNull($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('APPROVAL_METADATA_INVALID');
            }
            $value = $decoded;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('APPROVAL_METADATA_INVALID');
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new InvalidArgumentException('APPROVAL_METADATA_INVALID');
        }

        return $json;
    }
}
