<?php

require_once __DIR__ . '/../../classes/Release/CertificationReceipt.php';

function certificationReceiptAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$key = str_repeat('receipt-key-', 4);
$now = new DateTimeImmutable('2026-07-29T08:00:00Z');
$subject = [
    'artifact_manifest_sha256' => str_repeat('a', 64),
    'source_commit' => str_repeat('b', 40),
    'migration_checksum' => str_repeat('c', 64),
    'schema_fingerprint' => str_repeat('d', 64),
    'branch_uuid' => 'branch-001',
    'pos_tenant' => '1',
    'pos_branch' => '2',
];
$receipt = CertificationReceipt::sign([
    'receipt_id' => 'receipt-001',
    'issued_at' => '2026-07-29T07:00:00Z',
    'expires_at' => '2026-08-05T07:00:00Z',
    'revoked' => false,
    'subject' => $subject,
    'gates' => [
        'financial' => 1,
        'sync' => 1,
        'inventory' => 2,
        'recipe' => 1,
    ],
], $key);

$valid = CertificationReceipt::verify(
    $receipt,
    $key,
    $subject,
    ['financial' => 1, 'sync' => 1, 'inventory' => 2],
    $now
);
certificationReceiptAssert($valid['valid'], 'valid receipt should pass');

$cases = [];
$wrongArtifact = $subject;
$wrongArtifact['artifact_manifest_sha256'] = str_repeat('e', 64);
$cases['wrong_artifact'] = CertificationReceipt::verify($receipt, $key, $wrongArtifact, [], $now);

$wrongMigration = $subject;
$wrongMigration['migration_checksum'] = str_repeat('e', 64);
$cases['wrong_migration'] = CertificationReceipt::verify($receipt, $key, $wrongMigration, [], $now);

$wrongSchema = $subject;
$wrongSchema['schema_fingerprint'] = str_repeat('e', 64);
$cases['wrong_schema'] = CertificationReceipt::verify($receipt, $key, $wrongSchema, [], $now);

$wrongIdentity = $subject;
$wrongIdentity['branch_uuid'] = 'branch-002';
$cases['wrong_identity'] = CertificationReceipt::verify($receipt, $key, $wrongIdentity, [], $now);

$cases['missing_gate'] = CertificationReceipt::verify($receipt, $key, $subject, ['operational' => 1], $now);
$cases['stale_gate'] = CertificationReceipt::verify($receipt, $key, $subject, ['inventory' => 3], $now);

$tampered = $receipt;
$tampered['gates']['financial'] = 99;
$cases['tampered'] = CertificationReceipt::verify($tampered, $key, $subject, [], $now);

$revoked = CertificationReceipt::sign(array_merge($receipt, ['revoked' => true]), $key);
$cases['revoked'] = CertificationReceipt::verify($revoked, $key, $subject, [], $now);

$expired = CertificationReceipt::sign(array_merge($receipt, ['expires_at' => '2026-07-29T07:30:00Z']), $key);
$cases['expired'] = CertificationReceipt::verify($expired, $key, $subject, [], $now);

$malformed = CertificationReceipt::verify(['schema' => 'wrong'], $key, $subject, [], $now);
$cases['malformed'] = $malformed;

foreach ($cases as $name => $result) {
    certificationReceiptAssert(!$result['valid'], $name . ' must fail closed');
    certificationReceiptAssert($result['errors'] !== [], $name . ' should report a reason');
}

certificationReceiptAssert(
    in_array('CERTIFICATION_RECEIPT_SUBJECT_MISMATCH:artifact_manifest_sha256', $cases['wrong_artifact']['errors'], true),
    'artifact mismatch reason expected'
);
certificationReceiptAssert(
    in_array('CERTIFICATION_RECEIPT_GATE_MISSING:operational', $cases['missing_gate']['errors'], true),
    'missing gate reason expected'
);
certificationReceiptAssert(
    in_array('CERTIFICATION_RECEIPT_GATE_STALE:inventory', $cases['stale_gate']['errors'], true),
    'stale gate reason expected'
);

echo "certification-receipt-contract-ok cases=" . (count($cases) + 1) . "\n";
