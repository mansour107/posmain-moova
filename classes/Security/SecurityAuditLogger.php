<?php

class SecurityAuditLogger
{
    public function record(mysqli $conn, string $eventType, array $options = []): array
    {
        $eventType = trim($eventType);
        if ($eventType === '' || strlen($eventType) > 80) {
            throw new InvalidArgumentException('SECURITY_AUDIT_EVENT_TYPE_INVALID');
        }

        $userId = array_key_exists('user_id', $options) && $options['user_id'] !== null ? (int) $options['user_id'] : $this->sessionUserId();
        $tenant = $this->scopeValue($options, 'tenant', 'pos_tenant');
        $branch = $this->scopeValue($options, 'branch', 'pos_branch');
        $ip = $this->truncate((string) ($options['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')), 64);
        $userAgent = $this->truncate((string) ($options['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 255);
        $targetType = $this->nullableString($options['target_type'] ?? null, 80);
        $targetId = array_key_exists('target_id', $options) && $options['target_id'] !== null ? (int) $options['target_id'] : null;
        $metadataJson = $this->encodeMetadata($options['metadata'] ?? $options['metadata_json'] ?? null);

        $stmt = $conn->prepare("
            INSERT INTO security_audit_log (
                event_type, user_id, tenant, branch, ip, user_agent, target_type, target_id, metadata_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'siiisssis',
            $eventType,
            $userId,
            $tenant,
            $branch,
            $ip,
            $userAgent,
            $targetType,
            $targetId,
            $metadataJson
        );
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return [
            'id' => $id,
            'event_type' => $eventType,
        ];
    }

    public function recordPermissionDenied(mysqli $conn, string $permission, array $options = []): array
    {
        $options['target_type'] = $options['target_type'] ?? 'permission';
        $options['metadata'] = array_merge((array) ($options['metadata'] ?? []), [
            'permission' => $permission,
        ]);

        return $this->record($conn, 'permission_denied', $options);
    }

    private function sessionUserId(): ?int
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            foreach (['userid', 'user_id'] as $key) {
                if (isset($_SESSION[$key]) && (int) $_SESSION[$key] > 0) {
                    return (int) $_SESSION[$key];
                }
            }
        }

        return null;
    }

    /**
     * Use an explicit trusted event scope when supplied; otherwise inherit the
     * authenticated operational scope so audit rows cannot silently lose branch
     * attribution after login.
     *
     * @param array<string,mixed> $options
     */
    private function scopeValue(array $options, string $primary, string $alternate): int
    {
        if (array_key_exists($primary, $options)) {
            return max(0, (int) $options[$primary]);
        }
        if (array_key_exists($alternate, $options)) {
            return max(0, (int) $options[$alternate]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return max(0, (int) ($_SESSION[$alternate] ?? 0));
        }

        return 0;
    }

    private function nullableString($value, int $limit): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $this->truncate($value, $limit);
    }

    private function truncate(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
    }

    private function encodeMetadata($metadata): ?string
    {
        if ($metadata === null || $metadata === '') {
            return null;
        }

        if (is_string($metadata)) {
            json_decode($metadata, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $metadata;
            }

            $metadata = ['message' => $metadata];
        }

        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('SECURITY_AUDIT_METADATA_INVALID');
        }

        return $json;
    }
}
