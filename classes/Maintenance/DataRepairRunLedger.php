<?php

declare(strict_types=1);

final class DataRepairRunLedger
{
    public function find(mysqli $conn, string $repairType, string $manifestHash): ?array
    {
        $stmt = $conn->prepare('SELECT result_json FROM data_repair_runs WHERE repair_type = ? AND manifest_hash = ? LIMIT 1');
        $stmt->bind_param('ss', $repairType, $manifestHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        $result = json_decode((string) $row['result_json'], true, 512, JSON_THROW_ON_ERROR);

        return is_array($result) ? $result : null;
    }

    public function record(mysqli $conn, string $repairType, string $manifestHash, array $result): void
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $stmt = $conn->prepare(
            'INSERT INTO data_repair_runs (repair_type, manifest_hash, result_json) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE result_json = VALUES(result_json)'
        );
        $stmt->bind_param('sss', $repairType, $manifestHash, $json);
        $stmt->execute();
        $stmt->close();
    }
}
