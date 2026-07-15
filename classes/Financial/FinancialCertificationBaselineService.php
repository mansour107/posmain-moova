<?php

final class FinancialCertificationBaselineService
{
    public function active(mysqli $conn): ?array
    {
        if (!$this->tableExists($conn, 'financial_certification_baselines')) {
            return null;
        }
        $result = $conn->query(
            'SELECT * FROM financial_certification_baselines WHERE invalidated_at IS NULL ORDER BY approved_at DESC, id DESC LIMIT 1'
        );
        $row = $result->fetch_assoc();
        if (!$row) {
            return null;
        }
        $exceptions = json_decode((string) $row['historical_exceptions_json'], true, 512, JSON_THROW_ON_ERROR);
        $expected = $this->manifestHash((string) $row['cutoff_time'], $exceptions);
        if (!hash_equals((string) $row['manifest_hash'], $expected)) {
            throw new RuntimeException('FINANCIAL_BASELINE_MANIFEST_TAMPERED');
        }
        $row['historical_exceptions'] = $exceptions;

        return $row;
    }

    public function create(mysqli $conn, string $cutoffTime, array $historicalExceptions, string $approver): array
    {
        $timestamp = strtotime($cutoffTime);
        $approver = trim($approver);
        if ($timestamp === false || $approver === '') {
            throw new InvalidArgumentException('FINANCIAL_BASELINE_INPUT_INVALID');
        }
        $cutoff = date('Y-m-d H:i:s', $timestamp);
        $json = $this->canonicalJson($historicalExceptions);
        $hash = $this->manifestHash($cutoff, $historicalExceptions);
        $current = $this->active($conn);
        if ($current !== null && hash_equals((string) $current['manifest_hash'], $hash)) {
            return [
                'id' => (int) $current['id'],
                'cutoff_time' => (string) $current['cutoff_time'],
                'manifest_hash' => $hash,
                'approver' => (string) $current['approver'],
                'approved_at' => (string) $current['approved_at'],
                'replayed' => true,
            ];
        }
        $approvedAt = gmdate('Y-m-d H:i:s');

        $conn->begin_transaction();
        try {
            $conn->query("UPDATE financial_certification_baselines SET invalidated_at = UTC_TIMESTAMP(), invalidation_reason = 'superseded' WHERE invalidated_at IS NULL");
            $stmt = $conn->prepare(
                'INSERT INTO financial_certification_baselines (cutoff_time, manifest_hash, approver, historical_exceptions_json, approved_at) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sssss', $cutoff, $hash, $approver, $json, $approvedAt);
            $stmt->execute();
            $id = (int) $conn->insert_id;
            $stmt->close();
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        return ['id' => $id, 'cutoff_time' => $cutoff, 'manifest_hash' => $hash, 'approver' => $approver, 'approved_at' => $approvedAt, 'replayed' => false];
    }

    public function manifestHash(string $cutoffTime, array $historicalExceptions): string
    {
        return hash('sha256', $this->canonicalJson([
            'cutoff_time' => $cutoffTime,
            'historical_exceptions' => $historicalExceptions,
        ]));
    }

    private function canonicalJson(array $value): string
    {
        $value = $this->canonicalize($value);

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
