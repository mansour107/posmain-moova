<?php

require_once __DIR__ . '/CertificationReceipt.php';

final class CertificationReceiptRuntime
{
    /** @var array<string,array<string,mixed>> */
    private static array $requestCache = [];

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function evaluate(array $config, bool $fresh = false): array
    {
        $receiptPath = trim((string) ($config['certification']['receipt_path'] ?? ''));
        if ($receiptPath === '') {
            return self::invalid(false, ['CERTIFICATION_RECEIPT_PATH_MISSING']);
        }
        if (!self::isAbsolutePath($receiptPath)) {
            return self::invalid(true, ['CERTIFICATION_RECEIPT_PATH_NOT_ABSOLUTE']);
        }
        if (!empty($config['router']['enabled'])) {
            return self::invalid(true, ['CERTIFICATION_ROUTER_DATABASE_UNSUPPORTED']);
        }

        $key = (string) getenv('POSMAIN_CERTIFICATION_RECEIPT_KEY');
        if ($key === '') {
            return self::invalid(true, ['CERTIFICATION_RECEIPT_KEY_MISSING']);
        }

        $db = is_array($config['database'] ?? null) ? $config['database'] : [];
        $branch = is_array($config['branch'] ?? null) ? $config['branch'] : [];
        $manifestPath = trim((string) ($config['certification']['release_manifest_path'] ?? ''));
        $cacheKey = hash('sha256', json_encode([
            $receiptPath,
            @filemtime($receiptPath),
            $manifestPath,
            @filemtime($manifestPath),
            $db['host'] ?? '',
            $db['port'] ?? '',
            $db['name'] ?? '',
            $branch['uuid'] ?? '',
            $branch['pos_tenant'] ?? '',
            $branch['pos_branch'] ?? '',
            hash('sha256', $key),
        ], JSON_UNESCAPED_SLASHES));
        if (!$fresh && isset(self::$requestCache[$cacheKey])) {
            return self::$requestCache[$cacheKey];
        }

        try {
            $raw = file_get_contents($receiptPath);
            $receipt = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($receipt)) {
                throw new RuntimeException('CERTIFICATION_RECEIPT_JSON_INVALID');
            }

            if (!self::isAbsolutePath($manifestPath)) {
                throw new RuntimeException('CERTIFICATION_RELEASE_MANIFEST_PATH_NOT_ABSOLUTE');
            }
            $manifest = CertificationReceipt::verifyReleaseManifest($manifestPath);
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $conn = new mysqli(
                (string) ($db['host'] ?? ''),
                (string) ($db['user'] ?? ''),
                (string) ($db['pass'] ?? ''),
                (string) ($db['name'] ?? ''),
                (int) ($db['port'] ?? 3306)
            );
            try {
                $conn->set_charset((string) ($db['charset'] ?? 'utf8mb4'));
                $database = CertificationReceipt::databaseEvidence($conn);
            } finally {
                $conn->close();
            }

            $expected = [
                'artifact_manifest_sha256' => $manifest['manifest_sha256'],
                'source_commit' => $manifest['source_commit'],
                'migration_checksum' => $database['migration_checksum'],
                'schema_fingerprint' => $database['schema_fingerprint'],
                'branch_uuid' => trim((string) ($branch['uuid'] ?? '')),
                'pos_tenant' => (string) ($branch['pos_tenant'] ?? ''),
                'pos_branch' => (string) ($branch['pos_branch'] ?? ''),
            ];
            foreach (['branch_uuid', 'pos_tenant', 'pos_branch'] as $identityField) {
                if ($expected[$identityField] === '') {
                    throw new RuntimeException('CERTIFICATION_RUNTIME_IDENTITY_MISSING:' . $identityField);
                }
            }

            $verification = CertificationReceipt::verify($receipt, $key, $expected);

            $result = [
                'requested' => true,
                'valid' => $verification['valid'],
                'errors' => $verification['errors'],
                'gates' => $verification['gates'],
                'subject' => $verification['subject'],
                'receipt_id' => trim((string) ($receipt['receipt_id'] ?? '')),
                'manifest_file_count' => $manifest['file_count'],
            ];
            self::$requestCache[$cacheKey] = $result;

            return $result;
        } catch (Throwable $exception) {
            $result = self::invalid(true, [$exception->getMessage()]);
            self::$requestCache[$cacheKey] = $result;

            return $result;
        }
    }

    /**
     * @param list<string> $errors
     * @return array<string,mixed>
     */
    private static function invalid(bool $requested, array $errors): array
    {
        return [
            'requested' => $requested,
            'valid' => false,
            'errors' => array_values(array_unique($errors)),
            'gates' => [],
            'subject' => [],
            'receipt_id' => '',
        ];
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
