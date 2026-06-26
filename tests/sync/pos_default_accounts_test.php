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

        posDefaultAccountsTestOrderContext($conn);
        posDefaultAccountsTestMinimalShopSalesAccount($conn);
        posDefaultAccountsTestSupplierSeedingWithExistingStore($conn);
        posDefaultAccountsTestMinimalAccHeadSchema($host, $port, $user, $pass);

        $legacyConn = @new mysqli($host, $user, $pass, '', $port);
        if (!$legacyConn->connect_errno) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $legacyDb = 'posmain_legacy_settings_' . getmypid();
            try {
                $legacyConn->query("CREATE DATABASE `{$legacyDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                $legacyConn->select_db($legacyDb);
                $legacyConn->query('
                    CREATE TABLE acc_head (
                        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        code VARCHAR(32) NOT NULL,
                        aname VARCHAR(120) NOT NULL,
                        parent_id INT NOT NULL DEFAULT 0,
                        is_basic TINYINT NOT NULL DEFAULT 0,
                        is_stock TINYINT NOT NULL DEFAULT 0,
                        is_fund TINYINT NOT NULL DEFAULT 0,
                        isdeleted TINYINT NOT NULL DEFAULT 0
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ');
                $legacyConn->query('
                    CREATE TABLE settings (
                        id INT PRIMARY KEY,
                        isdeleted TINYINT NOT NULL DEFAULT 0
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ');
                $legacyConn->query("INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, is_fund, isdeleted) VALUES
                    (27, '123001', 'Main Store', 0, 0, 1, 0, 0),
                    (148, '122001', 'Default Client', 0, 0, 0, 0, 0)
                ");
                $legacyConn->query('INSERT INTO settings (id, isdeleted) VALUES (1, 0)');

                $legacyResolved = posmain_resolve_pos_invoice_accounts($legacyConn, [], [
                    'store_id' => 0,
                    'emp_id' => 0,
                    'fund_id' => 0,
                    'acc2_id' => 155,
                ]);
                posDefaultAccountsAssert($legacyResolved['store_id'] === 27, 'legacy schema without def_pos columns should still resolve store');
                posDefaultAccountsAssert($legacyResolved['acc2_id'] === 148, 'legacy schema without def_pos columns should still resolve client');
            } finally {
                $legacyConn->query("DROP DATABASE IF EXISTS `{$legacyDb}`");
                $legacyConn->close();
            }
        }

        echo "pos-default-accounts-ok\n";
    } finally {
        $conn->query("DROP DATABASE IF EXISTS `{$db}`");
        $conn->close();
    }
}

function posDefaultAccountsTestOrderContext(mysqli $conn): void
{
    $takeaway = posmain_resolve_invoice_order_context($conn, [
        'age' => '2',
        'table_id' => '0',
    ]);
    posDefaultAccountsAssert($takeaway['order_type_db'] === 'takeaway', 'table mode without table should downgrade to takeaway');
    posDefaultAccountsAssert((int) $takeaway['order_mode'] === 1, 'table mode without table should reset order mode to takeaway');

    $delivery = posmain_resolve_invoice_order_context($conn, [
        'age' => '2',
        'table_id' => '0',
        'delivery_customer_name' => 'Ali',
        'delivery_customer_phone' => '01012345678',
        'delivery_customer_address' => 'Cairo',
    ]);
    posDefaultAccountsAssert($delivery['order_type_db'] === 'delivery', 'delivery fields should override stale table mode');
}

function posDefaultAccountsTestMinimalShopSalesAccount(mysqli $conn): void
{
    $conn->query('DELETE FROM acc_head');
    $conn->query("INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, is_fund, isdeleted) VALUES
        (35, '213', 'Employees', 0, 1, 0, 0, 0),
        (274, '123001', 'Main Store', 0, 0, 1, 0, 0),
        (275, '213001', 'Employee 1', 35, 0, 0, 0, 0),
        (276, '121001', 'Default Fund', 0, 0, 0, 1, 0),
        (277, '122001', 'Default Client', 0, 0, 0, 0, 0)
    ");

    $salesId = posmain_ensure_sales_account($conn, 91);
    posDefaultAccountsAssert($salesId > 0, 'minimal shop should bootstrap a sales account');
    $row = $conn->query("SELECT code, aname FROM acc_head WHERE id = {$salesId} AND isdeleted = 0")->fetch_assoc();
    posDefaultAccountsAssert(($row['code'] ?? '') === '3111', 'bootstrapped sales account should use code 3111');
}

function posDefaultAccountsTestSupplierSeedingWithExistingStore(mysqli $conn): void
{
    $conn->query('DELETE FROM acc_head');
    $conn->query("INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, is_fund, isdeleted) VALUES
        (274, '123001', 'Main Store', 0, 0, 1, 0, 0),
        (277, '122001', 'Default Client', 0, 0, 0, 0, 0)
    ");

    posmain_ensure_pos_default_accounts($conn);

    $supplierLeaf = $conn->query(
        "SELECT id, code, aname, is_basic FROM acc_head
         WHERE isdeleted = 0 AND is_basic = 0 AND code LIKE '211%' AND code != '211'
         ORDER BY id ASC LIMIT 1"
    )->fetch_assoc();
    posDefaultAccountsAssert(!empty($supplierLeaf), 'default supplier leaf should be seeded even when a stock account already exists');

    $supplierParent = $conn->query(
        "SELECT id, code, aname, is_basic FROM acc_head
         WHERE isdeleted = 0 AND code = '211' LIMIT 1"
    )->fetch_assoc();
    posDefaultAccountsAssert(!empty($supplierParent), 'default supplier parent group should be seeded');
    posDefaultAccountsAssert((int) ($supplierParent['is_basic'] ?? 0) === 1, 'supplier parent should be a basic group');
    posDefaultAccountsAssert((int) ($supplierLeaf['is_basic'] ?? 0) === 0, 'supplier leaf should be selectable (is_basic = 0)');

    $supplierLeaf2 = $conn->query(
        "SELECT id FROM acc_head WHERE isdeleted = 0 AND is_basic = 0 AND code LIKE '211%' ORDER BY id ASC LIMIT 1"
    )->fetch_assoc();
    posDefaultAccountsAssert(!empty($supplierLeaf2), 'goods-receipt supplier filter (code LIKE 211% AND is_basic=0) should return at least one row');
}

function posDefaultAccountsTestMinimalAccHeadSchema(string $host, int $port, string $user, string $pass): void{
    $minimalConn = @new mysqli($host, $user, $pass, '', $port);
    if ($minimalConn->connect_errno) {
        return;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $minimalDb = 'posmain_minimal_acc_head_' . getmypid();

    try {
        $minimalConn->query("CREATE DATABASE `{$minimalDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $minimalConn->select_db($minimalDb);
        $minimalConn->query('
            CREATE TABLE acc_head (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NOT NULL,
                aname VARCHAR(120) NOT NULL,
                isdeleted TINYINT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
        $minimalConn->query('
            CREATE TABLE settings (
                id INT PRIMARY KEY,
                isdeleted TINYINT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
        $minimalConn->query('INSERT INTO settings (id, isdeleted) VALUES (1, 0)');

        posmain_ensure_pos_default_accounts($minimalConn);
        $defaults = posmain_resolve_pos_defaults($minimalConn, []);

        posDefaultAccountsAssert($defaults['store_id'] > 0, 'minimal acc_head schema should resolve a fallback store');
        posDefaultAccountsAssert($defaults['client_id'] > 0, 'minimal acc_head schema should resolve a fallback client');
    } finally {
        $minimalConn->query("DROP DATABASE IF EXISTS `{$minimalDb}`");
        $minimalConn->close();
    }
}

function posDefaultAccountsCreateSchema(mysqli $conn): void
{
    $conn->query('
        CREATE TABLE acc_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
