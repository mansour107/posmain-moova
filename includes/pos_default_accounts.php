<?php

if (!function_exists('posmain_insert_acc_head_if_missing')) {
    function posmain_insert_acc_head_if_missing(mysqli $conn, array $account): int
    {
        $code = trim((string) ($account['code'] ?? ''));
        if ($code === '') {
            return 0;
        }

        $lookup = $conn->prepare('SELECT id FROM acc_head WHERE code = ? AND isdeleted = 0 LIMIT 1');
        $lookup->bind_param('s', $code);
        $lookup->execute();
        $existing = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        $id = isset($account['id']) ? (int) $account['id'] : 0;
        $aname = (string) ($account['aname'] ?? $code);
        $parentId = (int) ($account['parent_id'] ?? 0);
        $isBasic = (int) ($account['is_basic'] ?? 0);
        $isStock = (int) ($account['is_stock'] ?? 0);
        $isFund = (int) ($account['is_fund'] ?? 0);
        $tenant = (int) ($account['tenant'] ?? 0);
        $branch = (int) ($account['branch'] ?? 0);

        if ($id > 0) {
            $stmt = $conn->prepare('
                INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, is_fund, isdeleted, tenant, branch)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
            ');
            $stmt->bind_param('issiiiiii', $id, $code, $aname, $parentId, $isBasic, $isStock, $isFund, $tenant, $branch);
        } else {
            $stmt = $conn->prepare('
                INSERT INTO acc_head (code, aname, parent_id, is_basic, is_stock, is_fund, isdeleted, tenant, branch)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)
            ');
            $stmt->bind_param('ssiiiiii', $code, $aname, $parentId, $isBasic, $isStock, $isFund, $tenant, $branch);
        }

        $stmt->execute();
        $insertId = $id > 0 ? $id : (int) $stmt->insert_id;
        $stmt->close();

        return $insertId;
    }
}

if (!function_exists('posmain_ensure_pos_default_accounts')) {
    /**
     * Create the minimum cashier accounts when a shop schema exists without acc_head rows.
     */
    function posmain_ensure_pos_default_accounts(mysqli $conn): void
    {
        $stockCount = $conn->query('SELECT COUNT(*) AS c FROM acc_head WHERE is_stock = 1 AND isdeleted = 0');
        $hasStock = $stockCount && (int) ($stockCount->fetch_assoc()['c'] ?? 0) > 0;
        if ($hasStock) {
            return;
        }

        posmain_insert_acc_head_if_missing($conn, [
            'id' => 35,
            'code' => '213',
            'aname' => 'الموظفين',
            'parent_id' => 0,
            'is_basic' => 1,
        ]);

        $storeId = posmain_insert_acc_head_if_missing($conn, [
            'code' => '123001',
            'aname' => 'المخزن الرئيسي',
            'parent_id' => 0,
            'is_stock' => 1,
        ]);
        $empId = posmain_insert_acc_head_if_missing($conn, [
            'code' => '213001',
            'aname' => 'الموظف 1',
            'parent_id' => 35,
        ]);
        $fundId = posmain_insert_acc_head_if_missing($conn, [
            'code' => '121001',
            'aname' => 'الصندوق الافتراضي',
            'parent_id' => 0,
            'is_fund' => 1,
        ]);
        $clientId = posmain_insert_acc_head_if_missing($conn, [
            'code' => '122001',
            'aname' => 'العميل الافتراضي',
            'parent_id' => 0,
        ]);

        if ($storeId < 1 && $empId < 1 && $fundId < 1 && $clientId < 1) {
            return;
        }

        $settings = $conn->query('SELECT id, def_pos_store, def_pos_employee, def_pos_fund, def_pos_client FROM settings ORDER BY id ASC LIMIT 1');
        if (!$settings || $settings->num_rows === 0) {
            return;
        }

        $row = $settings->fetch_assoc();
        $settingsId = (int) ($row['id'] ?? 0);
        if ($settingsId < 1) {
            return;
        }

        $defStore = (int) ($row['def_pos_store'] ?? 0);
        $defEmp = (int) ($row['def_pos_employee'] ?? 0);
        $defFund = (int) ($row['def_pos_fund'] ?? 0);
        $defClient = (int) ($row['def_pos_client'] ?? 0);

        $stmt = $conn->prepare('
            UPDATE settings
            SET def_pos_store = CASE WHEN def_pos_store > 0 THEN def_pos_store ELSE ? END,
                def_pos_employee = CASE WHEN def_pos_employee > 0 THEN def_pos_employee ELSE ? END,
                def_pos_fund = CASE WHEN def_pos_fund > 0 THEN def_pos_fund ELSE ? END,
                def_pos_client = CASE WHEN def_pos_client > 0 THEN def_pos_client ELSE ? END
            WHERE id = ?
        ');
        $stmt->bind_param('iiiii', $storeId, $empId, $fundId, $clientId, $settingsId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('posmain_resolve_default_account_id')) {
    /**
     * Resolve a default acc_head id, preferring settings when that account still exists.
     */
    function posmain_resolve_default_account_id(mysqli $conn, int $preferredId, string $whereSql): int
    {
        if ($preferredId > 0) {
            $result = $conn->query(
                'SELECT id FROM acc_head WHERE id = ' . $preferredId
                . ' AND isdeleted = 0 AND ' . $whereSql . ' LIMIT 1'
            );
            if ($result && $result->num_rows > 0) {
                return $preferredId;
            }
        }

        $result = $conn->query(
            'SELECT id FROM acc_head WHERE isdeleted = 0 AND ' . $whereSql . ' ORDER BY id ASC LIMIT 1'
        );
        if ($result && $result->num_rows > 0) {
            return (int) ($result->fetch_assoc()['id'] ?? 0);
        }

        return 0;
    }
}

if (!function_exists('posmain_resolve_pos_defaults')) {
    function posmain_resolve_pos_defaults(mysqli $conn, array $settings): array
    {
        posmain_ensure_pos_default_accounts($conn);

        $preferredStoreId = (int) ($settings['def_pos_store'] ?? 0);
        if ($preferredStoreId < 1) {
            $optionRow = $conn->query("SELECT cur_value FROM myoptions WHERE oname = 'def_store' LIMIT 1");
            if ($optionRow && $optionRow->num_rows > 0) {
                $preferredStoreId = (int) ($optionRow->fetch_assoc()['cur_value'] ?? 0);
            }
        }

        return [
            'store_id' => posmain_resolve_default_account_id(
                $conn,
                $preferredStoreId,
                'is_stock = 1'
            ),
            'emp_id' => posmain_resolve_default_account_id(
                $conn,
                (int) ($settings['def_pos_employee'] ?? 0),
                'parent_id = 35 AND is_basic = 0'
            ),
            'fund_id' => posmain_resolve_default_account_id(
                $conn,
                (int) ($settings['def_pos_fund'] ?? 0),
                'is_fund = 1 AND is_basic = 0'
            ),
        ];
    }
}
