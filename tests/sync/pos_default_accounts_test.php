<?php

require_once __DIR__ . '/../../includes/pos_default_accounts.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    posDefaultAccountsTestMain();
}

function posDefaultAccountsTestMain(): void
{
    $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
    $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
    $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
    $db = 'posmain_default_accounts_' . getmypid();

    $conn = @new mysqli($host, $user, $pass, '', $port);
    if ($conn->connect_errno) {
        echo "pos-default-accounts-skipped-db-unavailable\n";
        return;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $conn->select_db($db);
        posDefaultAccountsCreateSchema($conn);
        posDefaultAccountsSeed($conn);

        $settings = [
            'id' => 1,
            'def_pos_store' => 27,
            'def_pos_employee' => 131,
            'def_pos_fund' => 21,
            'def_pos_client' => 155,
        ];

        $resolved = posmain_resolve_pos_invoice_accounts($conn, $settings, [
            'store_id' => 27,
            'emp_id' => 131,
            'fund_id' => 21,
            'acc2_id' => 155,
            'payment_fund_id' => 21,
        ]);

        posDefaultAccountsAssert($resolved['acc2_id'] === 148, 'invalid posted customer should resolve to fallback client');
        posDefaultAccountsAssert($resolved['store_id'] === 27, 'valid store should remain unchanged');
        posDefaultAccountsAssert($resolved['payment_fund_id'] === 21, 'valid payment fund should remain unchanged');

        $synced = posmain_sync_pos_setting_defaults($conn, $settings);
        posDefaultAccountsAssert((int) $synced['def_pos_client'] === 148, 'stale settings customer should be repaired');

        $row = $conn->query('SELECT def_pos_client FROM settings WHERE id = 1')->fetch_assoc();
        posDefaultAccountsAssert((int) $row['def_pos_client'] === 148, 'settings row should persist repaired customer id');

        $defaults = posmain_resolve_pos_defaults($conn, $settings);
        posDefaultAccountsAssert((int) $defaults['client_id'] === 148, 'resolved defaults should expose repaired client id');

        echo "pos-default-accounts-ok\n";
    } finally {
        $conn->query("DROP DATABASE IF EXISTS `{$db}`");
        $conn->close();
    }
}

function posDefaultAccountsCreateSchema(mysqli $conn): void
{
    $conn->query('
        CREATE TABLE acc_head (
            id INT PRIMARY KEY,
            code VARCHAR(32) NOT NULL,
            aname VARCHAR(120) NOT NULL,
            parent_id INT NOT NULL DEFAULT 0,
            is_basic TINYINT NOT NULL DEFAULT 0,
            is_stock TINYINT NOT NULL DEFAULT 0,
            is_fund TINYINT NOT NULL DEFAULT 0,
            isdeleted TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $conn->query('
        CREATE TABLE settings (
            id INT PRIMARY KEY,
            def_pos_store INT NULL,
            def_pos_employee INT NULL,
            def_pos_fund INT NULL,
            def_pos_client INT NULL,
            isdeleted TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

function posDefaultAccountsSeed(mysqli $conn): void
{
    $conn->query("INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, is_fund, isdeleted) VALUES
        (27, '123001', 'Main Store', 0, 0, 1, 0, 0),
        (35, '213', 'Employees', 0, 1, 0, 0, 0),
        (131, '213001', 'Employee 1', 35, 0, 0, 0, 0),
        (21, '121001', 'Default Fund', 0, 0, 0, 1, 0),
        (148, '122001', 'Default Client', 0, 0, 0, 0, 0)
    ");
    $conn->query('INSERT INTO settings (id, def_pos_store, def_pos_employee, def_pos_fund, def_pos_client, isdeleted)
        VALUES (1, 27, 131, 21, 155, 0)');
}

function posDefaultAccountsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
