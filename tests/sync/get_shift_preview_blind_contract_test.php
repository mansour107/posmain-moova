<?php

$source = file_get_contents(__DIR__ . '/../../do/get_shift_preview.php');
if ($source === false) {
    throw new RuntimeException('Unable to read get_shift_preview.php');
}

function previewContractAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

previewContractAssert(
    strpos($source, "auth_guard_has_permission('reports.cash_flow'") !== false,
    'preview should gate expected cash on cash_flow permission'
);
previewContractAssert(
    strpos($source, "auth_guard_has_permission('pos.shift.force_close'") === false,
    'preview must not reveal expected cash via force_close alone during handover'
);
previewContractAssert(
    preg_match("/'expected_cash'\\s*=>\\s*\\\$canSeeExpectedCash\\s*\\?/", $source) === 1,
    'preview must null expected_cash when canSeeExpectedCash is false'
);
previewContractAssert(
    strpos($source, "\$expenseSummary['expected_cash'] = null") !== false,
    'preview must null nested expenses.expected_cash'
);
previewContractAssert(
    strpos($source, "\$payInSummary['expected_cash'] = null") !== false,
    'preview must null nested payins.expected_cash'
);
previewContractAssert(
    strpos($source, "\$safeDropSummary['expected_cash'] = null") !== false,
    'preview must null nested safe_drops.expected_cash'
);

echo "get_shift_preview_blind_contract_test: OK\n";
