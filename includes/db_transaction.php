<?php

/**
 * Nested-safe transaction helpers for mysqli.
 * MySQL cannot nest START TRANSACTION — a second begin implicitly commits the outer TX.
 * Callers that may run inside an outer TX must use these helpers.
 */
function posmain_tx_connection_in_transaction(mysqli $conn): bool
{
    if (!defined('MYSQLI_SERVER_STATUS_IN_TRANS')) {
        return false;
    }

    return (((int) $conn->server_status) & MYSQLI_SERVER_STATUS_IN_TRANS) !== 0;
}

function posmain_tx_begin_if_needed(mysqli $conn, bool $alreadyInTransaction = false): bool
{
    if ($alreadyInTransaction || posmain_tx_connection_in_transaction($conn)) {
        return false;
    }

    $conn->begin_transaction();

    return true;
}

function posmain_tx_commit_if_owned(mysqli $conn, bool $ownsTransaction): void
{
    if ($ownsTransaction) {
        $conn->commit();
    }
}

function posmain_tx_rollback_if_owned(mysqli $conn, bool $ownsTransaction): void
{
    if ($ownsTransaction) {
        $conn->rollback();
    }
}

/**
 * @param array<string, mixed> $context
 */
function posmain_tx_context_in_transaction(array $context): bool
{
    return !empty($context['in_transaction']) || !empty($context['transaction_started']);
}
