<?php

$root = dirname(__DIR__, 2);
$mirror = file_get_contents($root . '/classes/Sync/CloudOperationalMirrorService.php');
$reports = file_get_contents($root . '/classes/Sync/CloudReportService.php');

cloudRefundMirrorAssert($mirror !== false, 'cloud mirror source must be readable');
cloudRefundMirrorAssert($reports !== false, 'cloud report source must be readable');
cloudRefundMirrorAssert(str_contains($mirror, "snapshotType === 'financial_refund_bundle'"), 'cloud mirror must route refund bundles');
cloudRefundMirrorAssert(str_contains($mirror, "upsertRow(\$conn, 'credit_notes'"), 'cloud mirror must persist immutable credit notes');
cloudRefundMirrorAssert(str_contains($mirror, "['credit_note_lines', 'payment_refunds']"), 'cloud mirror must persist refund line and tender evidence');
cloudRefundMirrorAssert(str_contains($reports, "cn.status = 'posted'"), 'cloud revenue must use posted credit notes only');
cloudRefundMirrorAssert(str_contains($reports, 'COALESCE(cn.business_day, DATE(cn.created_at))'), 'cloud refund period must use refund business day');
cloudRefundMirrorAssert(str_contains($reports, 'cn.created_by'), 'cloud cashier grouping must attribute refunds to the refunding operator');

echo "cloud-operational-refund-mirror-contract-ok\n";

function cloudRefundMirrorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "cloud-operational-refund-mirror-contract-fail: {$message}\n");
        exit(1);
    }
}
