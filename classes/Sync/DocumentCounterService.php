<?php

class DocumentCounterService
{
    public function nextProId(mysqli $conn, $proTybe, $posTenant, $posBranch)
    {
        return $this->nextCounter($conn, $posTenant, $posBranch, 'pro_id', 'pro_tybe:' . (int) $proTybe);
    }

    public function nextJournalId(mysqli $conn, $posTenant, $posBranch, $key = 'default')
    {
        $key = (string) $key;
        if (strpos($key, 'journal:') !== 0) {
            $key = 'journal:' . $key;
        }

        return $this->nextCounter($conn, $posTenant, $posBranch, 'journal_id', $key);
    }

    public function nextCounter(mysqli $conn, $posTenant, $posBranch, $counterType, $counterKey)
    {
        $this->ensureCounterRow($conn, $posTenant, $posBranch, $counterType, $counterKey);
        $this->incrementCounter($conn, $posTenant, $posBranch, $counterType, $counterKey);

        $result = $conn->query('SELECT LAST_INSERT_ID() AS next_value');
        $row = $result->fetch_assoc();

        return (int) $row['next_value'];
    }

    public function nextValue(mysqli $conn, $tenantId, $branchId, $documentType)
    {
        return $this->nextCounter($conn, $tenantId, $branchId, 'generic', (string) $documentType);
    }

    public function ensureCounterRow(mysqli $conn, $posTenant, $posBranch, $counterType, $counterKey, $initialValue = 0)
    {
        $sql = "
            INSERT INTO document_counters (pos_tenant, pos_branch, counter_type, counter_key, current_value)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE current_value = GREATEST(current_value, VALUES(current_value))
        ";
        $posTenant = (int) $posTenant;
        $posBranch = (int) $posBranch;
        $counterType = (string) $counterType;
        $counterKey = (string) $counterKey;
        $initialValue = (int) $initialValue;

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iissi', $posTenant, $posBranch, $counterType, $counterKey, $initialValue);
        $stmt->execute();
        $stmt->close();
    }

    private function incrementCounter(mysqli $conn, $posTenant, $posBranch, $counterType, $counterKey)
    {
        $sql = "
            UPDATE document_counters
            SET current_value = LAST_INSERT_ID(current_value + 1)
            WHERE pos_tenant = ?
              AND pos_branch = ?
              AND counter_type = ?
              AND counter_key = ?
        ";
        $posTenant = (int) $posTenant;
        $posBranch = (int) $posBranch;
        $counterType = (string) $counterType;
        $counterKey = (string) $counterKey;

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiss', $posTenant, $posBranch, $counterType, $counterKey);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            throw new RuntimeException('Document counter row was not incremented.');
        }
    }
}
