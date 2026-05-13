<?php

class IdempotencyService
{
    public function resolveKey(array $request = [], array $server = [], array $context = []): string
    {
        $candidates = [
            $request['idempotency_key'] ?? null,
            $request['idempotencyKey'] ?? null,
            $request['request_id'] ?? null,
            $context['idempotency_key'] ?? null,
            $context['idempotencyKey'] ?? null,
            $server['HTTP_IDEMPOTENCY_KEY'] ?? null,
            $server['Idempotency-Key'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                $this->assertIdentifier($value, 'idempotency_key');
                return $value;
            }
        }

        throw new InvalidArgumentException('IDEMPOTENCY_REQUIRED');
    }

    public function requestHashForPayload(array $payload, array $excludeKeys = []): string
    {
        $exclude = array_fill_keys(array_merge([
            'idempotency_key',
            'idempotencyKey',
            'request_id',
        ], $excludeKeys), true);

        return $this->requestHash($this->stripKeys($payload, $exclude));
    }

    public function begin(mysqli $conn, string $scope, string $idempotencyKey, string $requestHash, array $options = []): array
    {
        $scope = trim($scope);
        $idempotencyKey = trim($idempotencyKey);
        $requestHash = strtolower(trim($requestHash));
        $this->assertIdentifier($scope, 'scope');
        $this->assertIdentifier($idempotencyKey, 'idempotency_key');
        $this->assertHash($requestHash);

        $userId = array_key_exists('user_id', $options) && $options['user_id'] !== null ? (int) $options['user_id'] : null;
        $tenant = (int) ($options['tenant'] ?? $options['pos_tenant'] ?? 0);
        $branch = (int) ($options['branch'] ?? $options['pos_branch'] ?? 0);
        $expiresAt = isset($options['expires_at']) ? trim((string) $options['expires_at']) : null;

        $stmt = $conn->prepare("
            INSERT IGNORE INTO pos_request_keys (
                scope, idempotency_key, request_hash, user_id, tenant, branch, status, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'processing', ?)
        ");
        $stmt->bind_param('sssiiis', $scope, $idempotencyKey, $requestHash, $userId, $tenant, $branch, $expiresAt);
        if (!$stmt->execute()) {
            $error = $stmt->error ?: 'IDEMPOTENCY_INSERT_FAILED';
            $stmt->close();
            throw new RuntimeException($error);
        }

        if ($stmt->affected_rows === 1) {
            $id = (int) $conn->insert_id;
            $stmt->close();

            return [
                'status' => 'started',
                'id' => $id,
                'response' => null,
                'row' => $this->fetch($conn, $scope, $idempotencyKey, true),
            ];
        }
        $stmt->close();

        $row = $this->fetch($conn, $scope, $idempotencyKey, true);
        if (!$row) {
            throw new RuntimeException('IDEMPOTENCY_LOOKUP_FAILED');
        }

        if (!hash_equals((string) $row['request_hash'], $requestHash)) {
            return [
                'status' => 'conflict',
                'code' => 'IDEMPOTENCY_CONFLICT',
                'response' => null,
                'row' => $row,
            ];
        }

        if ((string) $row['status'] === 'completed') {
            return [
                'status' => 'completed',
                'response' => $this->decodeJson($row['response_json'] ?? null),
                'row' => $row,
            ];
        }

        if ((string) $row['status'] === 'processing' && $this->canReclaim($row, $options)) {
            $stmt = $conn->prepare("
                UPDATE pos_request_keys
                   SET user_id = ?,
                       tenant = ?,
                       branch = ?,
                       error_code = NULL,
                       updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?
            ");
            $id = (int) $row['id'];
            $stmt->bind_param('iiii', $userId, $tenant, $branch, $id);
            $stmt->execute();
            $stmt->close();

            return [
                'status' => 'reclaimed',
                'id' => $id,
                'response' => null,
                'row' => $this->fetch($conn, $scope, $idempotencyKey, true),
            ];
        }

        return [
            'status' => (string) $row['status'],
            'response' => $this->decodeJson($row['response_json'] ?? null),
            'row' => $row,
        ];
    }

    public function complete(mysqli $conn, string $scope, string $idempotencyKey, string $requestHash, array $response): array
    {
        $responseJson = $this->encodeJson($response);

        return $this->finish($conn, $scope, $idempotencyKey, $requestHash, 'completed', $responseJson, null);
    }

    public function fail(mysqli $conn, string $scope, string $idempotencyKey, string $requestHash, string $errorCode, ?array $response = null): array
    {
        $errorCode = trim($errorCode) !== '' ? trim($errorCode) : 'REQUEST_FAILED';

        return $this->finish($conn, $scope, $idempotencyKey, $requestHash, 'failed', $response === null ? null : $this->encodeJson($response), $errorCode);
    }

    public function void(mysqli $conn, string $scope, string $idempotencyKey, string $requestHash, string $errorCode = 'REQUEST_VOIDED'): array
    {
        return $this->finish($conn, $scope, $idempotencyKey, $requestHash, 'voided', null, $errorCode);
    }

    public function requestHash(array $payload): string
    {
        return hash('sha256', $this->encodeJson($this->sortKeys($payload)));
    }

    private function finish(mysqli $conn, string $scope, string $idempotencyKey, string $requestHash, string $status, ?string $responseJson, ?string $errorCode): array
    {
        $scope = trim($scope);
        $idempotencyKey = trim($idempotencyKey);
        $requestHash = strtolower(trim($requestHash));
        $this->assertIdentifier($scope, 'scope');
        $this->assertIdentifier($idempotencyKey, 'idempotency_key');
        $this->assertHash($requestHash);

        $stmt = $conn->prepare("
            UPDATE pos_request_keys
               SET status = ?,
                   response_json = ?,
                   error_code = ?,
                   updated_at = CURRENT_TIMESTAMP
             WHERE scope = ?
               AND idempotency_key = ?
               AND request_hash = ?
        ");
        $stmt->bind_param('ssssss', $status, $responseJson, $errorCode, $scope, $idempotencyKey, $requestHash);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            throw new RuntimeException('IDEMPOTENCY_COMPLETE_FAILED');
        }

        return $this->fetch($conn, $scope, $idempotencyKey, false) ?: [];
    }

    private function fetch(mysqli $conn, string $scope, string $idempotencyKey, bool $forUpdate): ?array
    {
        $sql = "
            SELECT *
              FROM pos_request_keys
             WHERE scope = ?
               AND idempotency_key = ?
             LIMIT 1";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $scope, $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function canReclaim(array $row, array $options): bool
    {
        $ttl = isset($options['stale_after_seconds']) ? (int) $options['stale_after_seconds'] : 0;
        if ($ttl <= 0) {
            return false;
        }

        $updatedAt = strtotime((string) ($row['updated_at'] ?? ''));
        if (!$updatedAt) {
            return false;
        }

        return $updatedAt <= (time() - $ttl);
    }

    private function assertIdentifier(string $value, string $name): void
    {
        if ($value === '') {
            throw new InvalidArgumentException(strtoupper($name) . '_REQUIRED');
        }

        $limits = [
            'scope' => 80,
            'idempotency_key' => 128,
        ];
        if (isset($limits[$name]) && strlen($value) > $limits[$name]) {
            throw new InvalidArgumentException(strtoupper($name) . '_TOO_LONG');
        }
    }

    private function assertHash(string $requestHash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $requestHash)) {
            throw new InvalidArgumentException('REQUEST_HASH_INVALID');
        }
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('JSON_ENCODE_FAILED');
        }

        return $json;
    }

    private function decodeJson($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function sortKeys($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return array_map([$this, 'sortKeys'], $value);
        }

        ksort($value);
        foreach ($value as $key => $child) {
            $value[$key] = $this->sortKeys($child);
        }

        return $value;
    }

    private function stripKeys($value, array $exclude)
    {
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $child) {
            if (isset($exclude[(string) $key])) {
                continue;
            }
            $result[$key] = $this->stripKeys($child, $exclude);
        }

        return $result;
    }
}
