<?php

/**
 * Accepted drawer count variances must be booked into the ledger and
 * carry a structured reason code.
 *
 * Money rules this locks in:
 *  - over  (counted > expected): debit fund, credit over/short account
 *  - short (counted < expected): debit over/short account, credit fund
 *  - posting happens in the same transaction as the resolution row
 *  - zero variances post nothing; installs without accounting skip posting
 *  - reason code is mandatory; free text mandatory only for "other"
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$ledger = file_get_contents($root . '/classes/Pos/Service/DrawerLedgerPostingService.php');
$counts = file_get_contents($root . '/classes/Pos/Service/ShiftCountService.php');
$schema = file_get_contents($root . '/classes/Sync/SchemaManager.php');
$endpoint = file_get_contents($root . '/do/do_resolve_drawer_session.php');
$sessionPage = file_get_contents($root . '/drawer_session.php');
$closedPage = file_get_contents($root . '/closed_sessions.php');

function drawerVarianceLedgerAssert(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "OK: {$msg}\n";
}

// --- Ledger posting service ---

drawerVarianceLedgerAssert(
    strpos($ledger, 'public function postCashOverShort(') !== false,
    'DrawerLedgerPostingService must expose postCashOverShort'
);

drawerVarianceLedgerAssert(
    strpos($ledger, "SHIFT_OVER_SHORT_ACCOUNT_CODE = '511902'") !== false,
    'over/short postings must use a dedicated account (511902)'
);

drawerVarianceLedgerAssert(
    strpos($ledger, "'POS-SHIFT-CASH-OVER'") !== false
        && strpos($ledger, "'POS-SHIFT-CASH-SHORT'") !== false,
    'over and short must produce distinct voucher references'
);

// Direction: over debits the fund; short credits the fund.
$overCall = strpos($ledger, 'return $this->postVoucher($conn, 1, $amount, $fundAccountId, $overShortAccountId');
$shortCall = strpos($ledger, 'return $this->postVoucher($conn, 2, $amount, $overShortAccountId, $fundAccountId');
drawerVarianceLedgerAssert(
    $overCall !== false && $shortCall !== false,
    'over must debit fund / credit over-short; short must debit over-short / credit fund'
);

drawerVarianceLedgerAssert(
    strpos($ledger, "throw new RuntimeException('LEDGER_AMOUNT_REQUIRED')") !== false,
    'zero-amount over/short postings must be rejected'
);

// --- Resolution flow ---

drawerVarianceLedgerAssert(
    strpos($counts, 'public static function resolutionReasonCodes(): array') !== false
        && strpos($counts, "'recount_confirmed'") !== false
        && strpos($counts, "'previous_shift'") !== false
        && strpos($counts, "'under_investigation'") !== false
        && strpos($counts, "'other'") !== false,
    'resolution reason codes must exist with real-life causes'
);

drawerVarianceLedgerAssert(
    strpos($counts, "throw new RuntimeException('RESOLUTION_REASON_CODE_REQUIRED')") !== false,
    'resolveSession must require a reason code from the list'
);

drawerVarianceLedgerAssert(
    strpos($counts, "\$notes === '' && \$reasonCode === 'other'") !== false,
    'free-text notes must be mandatory only for the "other" reason'
);

drawerVarianceLedgerAssert(
    strpos($counts, '$ledgerPosting->postCashOverShort(') !== false
        && strpos($counts, '$ledgerPosting->canPost($conn)') !== false,
    'resolveSession must book the variance via postCashOverShort when accounting is available'
);

// Posting must sit inside the resolution transaction (before commit).
$postPos = strpos($counts, '$ledgerPosting->postCashOverShort(');
$commitPos = strpos($counts, 'posmain_tx_commit_if_owned($conn, $ownsTransaction);', (int) strpos($counts, 'public function resolveSession'));
drawerVarianceLedgerAssert(
    $postPos !== false && $commitPos !== false && $postPos < $commitPos,
    'ledger posting must happen inside the resolution transaction'
);

drawerVarianceLedgerAssert(
    strpos($counts, "round(abs(\$varianceAmount), 3) >= 0.001") !== false,
    'zero variances must not create ledger entries'
);

// Legacy installs without the code column must still persist the cause in notes.
drawerVarianceLedgerAssert(
    strpos($counts, '$hasReasonCodeColumn') !== false
        && strpos($counts, "trim(\$reasonCodes[\$reasonCode] . (\$notes !== '' ? ' — ' . \$notes : ''))") !== false,
    'reason must never be lost on schemas without the reason-code column'
);

// --- Schema ---

drawerVarianceLedgerAssert(
    strpos($schema, 'resolution_reason_code VARCHAR(40) NULL') !== false
        && strpos($schema, 'ledger_ot_head_id BIGINT UNSIGNED NULL') !== false,
    'fresh installs must create reason-code and ledger link columns'
);

drawerVarianceLedgerAssert(
    strpos($schema, 'ALTER TABLE drawer_session_resolutions ADD COLUMN resolution_reason_code') !== false
        && strpos($schema, 'ALTER TABLE drawer_session_resolutions ADD COLUMN ledger_ot_head_id') !== false,
    'existing installs must be migrated to the new resolution columns'
);

// --- Endpoint & UI ---

drawerVarianceLedgerAssert(
    strpos($endpoint, "'resolution_reason_code' => \$_POST['resolution_reason_code']") !== false,
    'resolve endpoint must forward the reason code'
);

drawerVarianceLedgerAssert(
    strpos($sessionPage, 'name="resolution_reason_code"') !== false
        && strpos($closedPage, 'name="resolution_reason_code"') !== false,
    'both resolve modals must offer the reason list'
);

drawerVarianceLedgerAssert(
    strpos($sessionPage, 'حساب فروقات عد الدرج') !== false
        && strpos($closedPage, 'حساب فروقات عد الدرج') !== false,
    'both resolve modals must disclose the automatic ledger entry'
);

echo "drawer_variance_ledger_contract_test: PASS\n";
