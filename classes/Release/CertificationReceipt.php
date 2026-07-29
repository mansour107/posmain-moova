<?php

final class CertificationReceipt
{
    public const SCHEMA = 'posmain.certification-receipt.v1';
    public const MINIMUM_KEY_BYTES = 32;

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function sign(array $payload, string $key): array
    {
        self::assertKey($key);
        unset($payload['signature']);
        $payload['schema'] = self::SCHEMA;
        $payload['signature'] = hash_hmac('sha256', self::canonicalJson($payload), $key);

        return $payload;
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array<string,string|int> $expectedSubject
     * @param array<string,int> $requiredGates
     * @return array{valid:bool,errors:list<string>,subject:array<string,mixed>,gates:array<string,int>}
     */
    public static function verify(
        array $receipt,
        string $key,
        array $expectedSubject,
        array $requiredGates = [],
        ?DateTimeImmutable $now = null
    ): array {
        $errors = [];
        try {
            self::assertKey($key);
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        if (($receipt['schema'] ?? null) !== self::SCHEMA) {
            $errors[] = 'CERTIFICATION_RECEIPT_SCHEMA_INVALID';
        }

        $signature = strtolower(trim((string) ($receipt['signature'] ?? '')));
        $unsigned = $receipt;
        unset($unsigned['signature']);
        if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            $errors[] = 'CERTIFICATION_RECEIPT_SIGNATURE_MISSING';
        } elseif ($errors === []) {
            $expectedSignature = hash_hmac('sha256', self::canonicalJson($unsigned), $key);
            if (!hash_equals($expectedSignature, $signature)) {
                $errors[] = 'CERTIFICATION_RECEIPT_SIGNATURE_INVALID';
            }
        }

        if (!empty($receipt['revoked'])) {
            $errors[] = 'CERTIFICATION_RECEIPT_REVOKED';
        }

        $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $issuedAt = self::parseTimestamp($receipt['issued_at'] ?? null, 'ISSUED_AT', $errors);
        $expiresAt = self::parseTimestamp($receipt['expires_at'] ?? null, 'EXPIRES_AT', $errors);
        if ($issuedAt && $issuedAt > $now->modify('+5 minutes')) {
            $errors[] = 'CERTIFICATION_RECEIPT_ISSUED_IN_FUTURE';
        }
        if ($expiresAt && $expiresAt <= $now) {
            $errors[] = 'CERTIFICATION_RECEIPT_EXPIRED';
        }
        if ($issuedAt && $expiresAt && $expiresAt <= $issuedAt) {
            $errors[] = 'CERTIFICATION_RECEIPT_EXPIRY_INVALID';
        }

        $subject = is_array($receipt['subject'] ?? null) ? $receipt['subject'] : [];
        foreach ($expectedSubject as $field => $expected) {
            $actual = $subject[$field] ?? null;
            if ($actual === null || !hash_equals((string) $expected, (string) $actual)) {
                $errors[] = 'CERTIFICATION_RECEIPT_SUBJECT_MISMATCH:' . $field;
            }
        }

        $gates = [];
        $receiptGates = is_array($receipt['gates'] ?? null) ? $receipt['gates'] : [];
        foreach ($receiptGates as $gate => $version) {
            if (is_string($gate) && preg_match('/^[a-z][a-z0-9_.-]*$/', $gate) === 1) {
                $gates[$gate] = (int) $version;
            }
        }
        foreach ($requiredGates as $gate => $minimumVersion) {
            if (!array_key_exists($gate, $gates)) {
                $errors[] = 'CERTIFICATION_RECEIPT_GATE_MISSING:' . $gate;
            } elseif ($gates[$gate] < $minimumVersion) {
                $errors[] = 'CERTIFICATION_RECEIPT_GATE_STALE:' . $gate;
            }
        }

        $errors = array_values(array_unique($errors));

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'subject' => $subject,
            'gates' => $gates,
        ];
    }

    /**
     * @return array{manifest_sha256:string,source_commit:string,file_count:int}
     */
    public static function verifyReleaseManifest(string $manifestPath): array
    {
        $resolved = realpath($manifestPath);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new RuntimeException('CERTIFICATION_RELEASE_MANIFEST_MISSING');
        }
        $raw = file_get_contents($resolved);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 'posmain.release-artifact.v1') {
            throw new RuntimeException('CERTIFICATION_RELEASE_MANIFEST_INVALID');
        }

        $recordedHash = strtolower(trim((string) ($manifest['manifest_sha256'] ?? '')));
        $core = $manifest;
        unset($core['manifest_sha256']);
        $coreJson = json_encode($core, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($coreJson)) {
            throw new RuntimeException('CERTIFICATION_RELEASE_MANIFEST_INVALID');
        }
        // ReleaseArtifactBuilder v1 hashes the manifest core in its emitted
        // insertion order. Decoding the sidecar preserves that order.
        $actualHash = hash('sha256', $coreJson);
        if (preg_match('/^[a-f0-9]{64}$/', $recordedHash) !== 1 || !hash_equals($recordedHash, $actualHash)) {
            throw new RuntimeException('CERTIFICATION_RELEASE_MANIFEST_HASH_MISMATCH');
        }

        $root = dirname($resolved);
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        foreach ($files as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('CERTIFICATION_RELEASE_MANIFEST_FILE_INVALID');
            }
            $path = str_replace('\\', '/', trim((string) ($entry['path'] ?? '')));
            if (
                $path === ''
                || str_starts_with($path, '/')
                || preg_match('#(^|/)\.\.(/|$)#', $path) === 1
            ) {
                throw new RuntimeException('CERTIFICATION_RELEASE_MANIFEST_PATH_INVALID');
            }
            $filePath = $root . '/' . $path;
            if (!is_file($filePath) || !is_readable($filePath)) {
                throw new RuntimeException('CERTIFICATION_RELEASE_FILE_MISSING:' . $path);
            }
            $expectedSize = (int) ($entry['size'] ?? -1);
            $expectedHash = strtolower(trim((string) ($entry['sha256'] ?? '')));
            if (
                filesize($filePath) !== $expectedSize
                || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1
                || !hash_equals($expectedHash, (string) hash_file('sha256', $filePath))
            ) {
                throw new RuntimeException('CERTIFICATION_RELEASE_FILE_MISMATCH:' . $path);
            }
        }
        if ((int) ($manifest['file_count'] ?? -1) !== count($files)) {
            throw new RuntimeException('CERTIFICATION_RELEASE_FILE_COUNT_MISMATCH');
        }

        return [
            'manifest_sha256' => $recordedHash,
            'source_commit' => strtolower(trim((string) ($manifest['source_commit'] ?? ''))),
            'file_count' => count($files),
        ];
    }

    /**
     * @return array{migration_checksum:string,schema_fingerprint:string}
     */
    public static function databaseEvidence(mysqli $conn): array
    {
        $databaseResult = $conn->query('SELECT DATABASE() AS db_name');
        $databaseRow = $databaseResult->fetch_assoc();
        $database = trim((string) ($databaseRow['db_name'] ?? ''));
        if ($database === '') {
            throw new RuntimeException('CERTIFICATION_DATABASE_NAME_MISSING');
        }

        $migrationRows = [];
        $tableCheck = $conn->prepare(
            "SELECT COUNT(*) AS table_count
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'schema_migrations'"
        );
        $tableCheck->bind_param('s', $database);
        $tableCheck->execute();
        $hasMigrations = (int) (($tableCheck->get_result()->fetch_assoc()['table_count'] ?? 0)) > 0;
        $tableCheck->close();
        if ($hasMigrations) {
            $result = $conn->query(
                "SELECT version, filename, checksum, COALESCE(status, 'applied') AS status
                 FROM schema_migrations
                 ORDER BY version ASC"
            );
            while ($row = $result->fetch_assoc()) {
                $migrationRows[] = [
                    'version' => (string) ($row['version'] ?? ''),
                    'filename' => (string) ($row['filename'] ?? ''),
                    'checksum' => strtolower((string) ($row['checksum'] ?? '')),
                    'status' => (string) ($row['status'] ?? ''),
                ];
            }
        }

        $schema = [
            'tables' => self::queryRows(
                $conn,
                "SELECT TABLE_NAME, TABLE_TYPE, COALESCE(ENGINE, '') AS ENGINE,
                        COALESCE(TABLE_COLLATION, '') AS TABLE_COLLATION
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = ?
                 ORDER BY TABLE_NAME"
            ),
            'columns' => self::queryRows(
                $conn,
                "SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE,
                        IS_NULLABLE, COALESCE(COLUMN_DEFAULT, '<NULL>') AS COLUMN_DEFAULT,
                        EXTRA, COALESCE(COLLATION_NAME, '') AS COLLATION_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                 ORDER BY TABLE_NAME, ORDINAL_POSITION"
            ),
            'indexes' => self::queryRows(
                $conn,
                "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX,
                        COALESCE(COLUMN_NAME, '') AS COLUMN_NAME,
                        COALESCE(SUB_PART, 0) AS SUB_PART, INDEX_TYPE
                 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = ?
                 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
            ),
        ];

        return [
            'migration_checksum' => hash('sha256', self::canonicalJson($migrationRows)),
            'schema_fingerprint' => hash('sha256', self::canonicalJson($schema)),
        ];
    }

    /**
     * @param array<mixed> $value
     */
    public static function canonicalJson(array $value): string
    {
        $normalized = self::canonicalize($value);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('CERTIFICATION_CANONICAL_JSON_FAILED');
        }

        return $json;
    }

    private static function assertKey(string $key): void
    {
        if (strlen($key) < self::MINIMUM_KEY_BYTES) {
            throw new InvalidArgumentException('CERTIFICATION_RECEIPT_KEY_TOO_SHORT');
        }
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = self::canonicalize($entry);
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @param list<string> $errors
     */
    private static function parseTimestamp($value, string $field, array &$errors): ?DateTimeImmutable
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            $errors[] = 'CERTIFICATION_RECEIPT_' . $field . '_MISSING';
            return null;
        }
        try {
            return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            $errors[] = 'CERTIFICATION_RECEIPT_' . $field . '_INVALID';
            return null;
        }
    }

    /**
     * @return list<array<string,string>>
     */
    private static function queryRows(mysqli $conn, string $sql): array
    {
        $databaseResult = $conn->query('SELECT DATABASE() AS db_name');
        $database = (string) ($databaseResult->fetch_assoc()['db_name'] ?? '');
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $database);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $normalized = [];
            foreach ($row as $key => $value) {
                $normalized[(string) $key] = (string) $value;
            }
            $rows[] = $normalized;
        }
        $stmt->close();

        return $rows;
    }
}
