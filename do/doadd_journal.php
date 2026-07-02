<?php

require_once __DIR__ . '/../includes/session_bootstrap.php';
$user = $_SESSION['userid'];
include '../includes/connect.php';

require_once __DIR__ . '/../classes/Accounting/JournalPostingGuard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../daily_journal.php');
    exit;
}

$journal_id = $_POST['journal_id'] ?? '';
$jdate = $_POST['jdate'] ?? date('Y-m-d');
$details = $_POST['details'] ?? '';
$rowdepit1 = $_POST['rowdepit'][0] ?? '0';
$rowdepit2 = $_POST['rowdepit'][1] ?? '';
$rowdepit3 = $_POST['rowdepit'][2] ?? '';
$creditrow1 = $_POST['creditrow'][0] ?? '0';
$creditrow2 = $_POST['creditrow'][1] ?? '';
$creditrow3 = $_POST['creditrow'][2] ?? '';

try {
    JournalPostingGuard::assertBalancedEntries([
        ['debit' => $rowdepit1, 'credit' => '0'],
        ['debit' => '0', 'credit' => $creditrow1],
    ]);
} catch (InvalidArgumentException $exception) {
    header('Location: ../warning.php?error=journal_not_balanced');
    exit;
}

$stmt = $conn->prepare('INSERT INTO journal_heads (journal_id, total, details, user, jdate) VALUES (?, ?, ?, ?, ?)');
$total = (float) $rowdepit1;
$stmt->bind_param('sdsss', $journal_id, $total, $details, $user, $jdate);
$stmt->execute();
$journal_lastid = (int) $conn->insert_id;
$stmt->close();

$debitStmt = $conn->prepare('INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, info) VALUES (?, ?, ?, 0, 0, ?)');
$debitStmt->bind_param('isds', $journal_lastid, $rowdepit2, $rowdepit1, $rowdepit3);
$debitStmt->execute();
$debitStmt->close();

$creditStmt = $conn->prepare('INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, info) VALUES (?, ?, 0, ?, 1, ?)');
$creditStmt->bind_param('isds', $journal_lastid, $creditrow2, $creditrow1, $creditrow3);
$creditStmt->execute();
$creditStmt->close();

$conn->query("INSERT INTO `process`(`type`) VALUES ('add journal')");
header('location:../daily_journal.php');
