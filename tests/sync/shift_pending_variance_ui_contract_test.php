<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$wizard = (string) file_get_contents($root . '/js/pos_shift_count_wizard.js');
$closeEndpoint = (string) file_get_contents($root . '/close_shift.php');
$workspace = (string) file_get_contents($root . '/cash_flow_report.php');
$detail = (string) file_get_contents($root . '/drawer_session.php');
$counts = (string) file_get_contents($root . '/classes/Pos/Service/ShiftCountService.php');

function pendingVarianceUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

pendingVarianceUiAssert(
    preg_match(
        '/data\\.status === \'counted_pending_review\'[\\s\\S]*?\\$message[\\s\\S]*?return;[\\s\\S]*?self\\.finalizeClose\\(\\);/',
        $wizard
    ) === 1,
    'POS close wizard must stop before final close when variance review is pending'
);
pendingVarianceUiAssert(
    strpos($closeEndpoint, "(\$result['status'] ?? '') === 'counted_pending_review'") !== false
        && strpos($closeEndpoint, "header('Location: pos_barcode.php');") !== false,
    'close endpoint must defensively keep a pending-review shift on the POS without logout'
);
pendingVarianceUiAssert(
    strpos($workspace, "['counted_pending_review', 'unresolved']") !== false
        && strpos($workspace, 'data-bs-target="#resolveDrawerModal"') !== false,
    'manager workspace must treat pending counts as actionable review work'
);
pendingVarianceUiAssert(
    strpos($detail, 'drawer-session-pending-variance-banner') !== false
        && strpos($detail, 'drawer-session-review-pending-close') !== false
        && strpos($detail, "in_array(\$varianceStatus, ['counted_pending_review', 'unresolved'], true)") !== false,
    'drawer detail must expose a permission-gated review action for a pending close count'
);
pendingVarianceUiAssert(
    strpos($counts, "CASE WHEN ds.variance_status = 'counted_pending_review' THEN 0 ELSE 1 END") !== false,
    'blocking open pending-count sessions must sort ahead of historical review backlog'
);
pendingVarianceUiAssert(
    strpos($counts, "'reviewed_counted_cash' => \$reviewedCountedCash") !== false
        && strpos($counts, "'close_token' => \$closeToken") !== false
        && strpos($wizard, 'if (this.closeState.reviewedVariance)') !== false
        && strpos($wizard, 'تأكيد الإغلاق') !== false,
    'approved variance must resume with immutable reviewed cash and a final-close token, not a third recount'
);

echo "shift_pending_variance_ui_contract_test: OK\n";
