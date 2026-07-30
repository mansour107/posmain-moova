<?php

require_once __DIR__ . '/../../classes/Release/CertificationReceipt.php';
require_once __DIR__ . '/../../classes/Release/CertificationReceiptRuntime.php';

function certificationRuntimeIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (getenv('POSMAIN_CERTIFICATION_TEST_DISPOSABLE') !== '1') {
    throw new RuntimeException('CERTIFICATION_TEST_DISPOSABLE_MARKER_REQUIRED');
}
$host = trim((string) (getenv('POSMAIN_CERTIFICATION_TEST_DB_HOST') ?: '127.0.0.1'));
if (!in_array(strtolower($host), ['127.0.0.1', 'localhost', 'mysql'], true)) {
    throw new RuntimeException('CERTIFICATION_TEST_LOCAL_DATABASE_REQUIRED');
}
$port = (int) (getenv('POSMAIN_CERTIFICATION_TEST_DB_PORT') ?: ($host === 'mysql' ? 3306 : 3307));
$user = (string) (getenv('POSMAIN_CERTIFICATION_TEST_DB_USER') ?: 'root');
$pass = (string) (getenv('POSMAIN_CERTIFICATION_TEST_DB_PASS') ?: '');
$database = 'posmain_certification_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (preg_match('/^posmain_certification_test_[0-9]+_[a-f0-9]{8}$/', $database) !== 1) {
    throw new RuntimeException('CERTIFICATION_TEST_DATABASE_NAME_REFUSED');
}

$directory = sys_get_temp_dir() . '/posmain-certification-runtime-' . bin2hex(random_bytes(8));
$admin = new mysqli($host, $user, $pass, '', $port);
$admin->set_charset('utf8mb4');
$previousKey = getenv('POSMAIN_CERTIFICATION_RECEIPT_KEY');

try {
    $admin->query(
        'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
    );
    $conn = new mysqli($host, $user, $pass, $database, $port);
    $conn->set_charset('utf8mb4');
    $conn->query(
        "CREATE TABLE schema_migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(191) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            checksum CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'applied',
            UNIQUE KEY uq_schema_migrations_version (version)
        ) ENGINE=InnoDB"
    );
    $conn->query(
        "INSERT INTO schema_migrations (version, filename, checksum, status)
         VALUES ('fixture-v1', 'fixture.sql', REPEAT('a', 64), 'applied')"
    );
    $conn->query(
        "CREATE TABLE certification_fixture (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            amount DECIMAL(18,6) NOT NULL
        ) ENGINE=InnoDB"
    );

    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('CERTIFICATION_TEST_TEMP_DIRECTORY_FAILED');
    }
    $runtimePath = $directory . '/runtime.php';
    file_put_contents($runtimePath, "<?php echo 'certified';\n", LOCK_EX);
    $runtimeContents = (string) file_get_contents($runtimePath);
    $manifestCore = [
        'schema' => 'posmain.release-artifact.v1',
        'policy_version' => 1,
        'source_commit' => str_repeat('b', 40),
        'source_commit_time' => '2026-07-29T08:00:00+00:00',
        'dependency_locks' => [],
        'file_count' => 1,
        'files' => [[
            'path' => 'runtime.php',
            'size' => strlen($runtimeContents),
            'sha256' => hash('sha256', $runtimeContents),
        ]],
    ];
    $manifest = $manifestCore + [
        'manifest_sha256' => hash(
            'sha256',
            (string) json_encode($manifestCore, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ),
    ];
    $manifestPath = $directory . '/release-manifest.json';
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );

    $databaseEvidence = CertificationReceipt::databaseEvidence($conn);
    $key = str_repeat('runtime-key-', 4);
    $receipt = CertificationReceipt::sign([
        'receipt_id' => 'runtime-integration',
        'issued_at' => '2026-07-29T07:00:00Z',
        'expires_at' => '2030-07-29T07:00:00Z',
        'revoked' => false,
        'subject' => [
            'artifact_manifest_sha256' => $manifest['manifest_sha256'],
            'source_commit' => $manifestCore['source_commit'],
            'migration_checksum' => $databaseEvidence['migration_checksum'],
            'schema_fingerprint' => $databaseEvidence['schema_fingerprint'],
            'branch_uuid' => 'fixture-branch',
            'pos_tenant' => '7',
            'pos_branch' => '9',
        ],
        'gates' => ['financial' => 1, 'sync' => 1, 'inventory' => 1, 'recipe' => 1],
    ], $key);
    $receiptPath = $directory . '/certification-receipt.json';
    file_put_contents(
        $receiptPath,
        json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );
    putenv('POSMAIN_CERTIFICATION_RECEIPT_KEY=' . $key);

    $config = [
        'router' => ['enabled' => false],
        'certification' => [
            'receipt_path' => $receiptPath,
            'release_manifest_path' => $manifestPath,
        ],
        'database' => [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'pass' => $pass,
            'name' => $database,
            'charset' => 'utf8mb4',
        ],
        'branch' => [
            'uuid' => 'fixture-branch',
            'pos_tenant' => 7,
            'pos_branch' => 9,
        ],
    ];

    $valid = CertificationReceiptRuntime::evaluate($config, true);
    certificationRuntimeIntegrationAssert(!empty($valid['valid']), 'matching runtime evidence should validate');

    $conn->query('ALTER TABLE certification_fixture ADD COLUMN note VARCHAR(50) NULL');
    $schemaMismatch = CertificationReceiptRuntime::evaluate($config, true);
    certificationRuntimeIntegrationAssert(empty($schemaMismatch['valid']), 'schema drift must invalidate receipt');
    certificationRuntimeIntegrationAssert(
        in_array(
            'CERTIFICATION_RECEIPT_SUBJECT_MISMATCH:schema_fingerprint',
            $schemaMismatch['errors'] ?? [],
            true
        ),
        'schema drift should report the schema fingerprint'
    );

    $conn->query("UPDATE schema_migrations SET checksum = REPEAT('c', 64) WHERE version = 'fixture-v1'");
    $migrationMismatch = CertificationReceiptRuntime::evaluate($config, true);
    certificationRuntimeIntegrationAssert(empty($migrationMismatch['valid']), 'migration drift must invalidate receipt');
    certificationRuntimeIntegrationAssert(
        in_array(
            'CERTIFICATION_RECEIPT_SUBJECT_MISMATCH:migration_checksum',
            $migrationMismatch['errors'] ?? [],
            true
        ),
        'migration drift should report the migration checksum'
    );
    $conn->close();
} finally {
    putenv(
        $previousKey === false
            ? 'POSMAIN_CERTIFICATION_RECEIPT_KEY'
            : 'POSMAIN_CERTIFICATION_RECEIPT_KEY=' . $previousKey
    );
    $admin->query('DROP DATABASE IF EXISTS `' . $database . '`');
    $admin->close();
    @unlink($directory . '/runtime.php');
    @unlink($directory . '/release-manifest.json');
    @unlink($directory . '/certification-receipt.json');
    @rmdir($directory);
}

echo "certification-receipt-runtime-integration-ok db={$database}\n";
