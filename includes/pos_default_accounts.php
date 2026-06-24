<?php

if (!function_exists('posmain_acc_head_has_column')) {
    function posmain_acc_head_has_column(mysqli $conn, string $column): bool
    {
        static $cache = [];
        $safeColumn = preg_replace('/[^a-z0-9_]/i', '', $column);
        if ($safeColumn === '') {
            return false;
        }

        $key = spl_object_hash($conn) . ':' . $safeColumn;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $result = $conn->query("SHOW COLUMNS FROM acc_head LIKE '{$safeColumn}'");
        $cache[$key] = $result && $result->num_rows > 0;

        return $cache[$key];
    }
}

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

        $columns = [];
        $placeholders = [];
        $types = '';
        $values = [];

        if ($id > 0) {
            $columns[] = 'id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $id;
        }

        foreach ([
            ['code', 's', $code],
            ['aname', 's', $aname],
            ['parent_id', 'i', $parentId],
            ['is_basic', 'i', $isBasic],
            ['is_stock', 'i', $isStock],
            ['is_fund', 'i', $isFund],
            ['isdeleted', 'i', 0],
        ] as $field) {
            $columns[] = $field[0];
            $placeholders[] = '?';
            $types .= $field[1];
            $values[] = $field[2];
        }

        if (posmain_acc_head_has_column($conn, 'tenant')) {
            $columns[] = 'tenant';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $tenant;
        }
        if (posmain_acc_head_has_column($conn, 'branch')) {
            $columns[] = 'branch';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $branch;
        }

        $sql = 'INSERT INTO acc_head (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
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
        posmain_ensure_sales_account($conn);

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

        if (!posmain_settings_column_exists($conn, 'def_pos_store')) {
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

if (!function_exists('posmain_normalize_post_age')) {
    function posmain_normalize_post_age($age): int
    {
        if (is_array($age)) {
            foreach ($age as $value) {
                $mode = (int) $value;
                if ($mode >= 1 && $mode <= 3) {
                    return $mode;
                }
            }

            return 1;
        }

        $mode = (int) $age;

        return ($mode >= 1 && $mode <= 3) ? $mode : 1;
    }
}

if (!function_exists('posmain_post_has_delivery_customer')) {
    function posmain_post_has_delivery_customer(array $post): bool
    {
        return trim((string) ($post['delivery_customer_phone'] ?? '')) !== ''
            && trim((string) ($post['delivery_customer_name'] ?? '')) !== ''
            && trim((string) ($post['delivery_customer_address'] ?? '')) !== '';
    }
}

if (!function_exists('posmain_resolve_invoice_order_context')) {
    /**
     * Resolve POS order mode/type from POST, recovering from stale table mode without a table.
     */
    function posmain_resolve_invoice_order_context(mysqli $conn, array $post): array
    {
        $order_mode = isset($post['age']) ? posmain_normalize_post_age($post['age']) : 1;
        $table_id = (int) ($post['table_id'] ?? 0);
        $selected_order_id = 0;
        foreach (['selected_order_id', 'edit', 'edit_id'] as $selectedOrderKey) {
            $candidate = (int) ($post[$selectedOrderKey] ?? 0);
            if ($candidate > 0) {
                $selected_order_id = $candidate;
                break;
            }
        }

        $order_type_db = 'takeaway';
        if ($order_mode === 3) {
            $order_type_db = 'delivery';
        } elseif ($order_mode === 2) {
            $order_type_db = 'table';
        }

        if ($order_type_db === 'table' && $table_id <= 0 && $selected_order_id > 0) {
            $stmt = $conn->prepare('SELECT table_id FROM ot_head WHERE id = ? AND isdeleted = 0 LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $selected_order_id);
                $stmt->execute();
                $orderRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $table_id = (int) ($orderRow['table_id'] ?? 0);
            }
        }

        if ($order_type_db === 'table' && $table_id <= 0 && $selected_order_id <= 0) {
            if (posmain_post_has_delivery_customer($post)) {
                $order_type_db = 'delivery';
                $order_mode = 3;
            } else {
                $order_type_db = 'takeaway';
                $order_mode = 1;
            }
        } elseif ($order_type_db === 'takeaway' && posmain_post_has_delivery_customer($post)) {
            $order_type_db = 'delivery';
            $order_mode = 3;
        }

        return [
            'order_mode' => $order_mode,
            'order_type_db' => $order_type_db,
            'table_id' => $table_id,
            'selected_order_id' => $selected_order_id,
        ];
    }
}

if (!function_exists('posmain_settings_column_exists')) {
    function posmain_settings_column_exists(mysqli $conn, string $column): bool
    {
        static $cache = [];
        $safeColumn = preg_replace('/[^a-z0-9_]/i', '', $column);
        if ($safeColumn === '') {
            return false;
        }

        $key = spl_object_hash($conn) . ':' . $safeColumn;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $result = $conn->query("SHOW COLUMNS FROM settings LIKE '{$safeColumn}'");
        $cache[$key] = $result && $result->num_rows > 0;

        return $cache[$key];
    }
}

if (!function_exists('posmain_ensure_pos_settings_columns')) {
    /**
     * Add cashier default columns when an older shop schema is missing them.
     */
    function posmain_ensure_pos_settings_columns(mysqli $conn): void
    {
        static $ensured = [];
        $key = spl_object_hash($conn);
        if (isset($ensured[$key])) {
            return;
        }
        $ensured[$key] = true;

        $definitions = [
            'def_pos_client' => 'INT NULL',
            'def_pos_store' => 'INT NULL',
            'def_pos_employee' => 'INT NULL',
            'def_pos_fund' => 'INT NULL',
        ];

        foreach ($definitions as $column => $definition) {
            if (posmain_settings_column_exists($conn, $column)) {
                continue;
            }

            @$conn->query("ALTER TABLE settings ADD COLUMN `{$column}` {$definition}");
        }
    }
}

if (!function_exists('posmain_load_pos_settings_row')) {
    function posmain_load_pos_settings_row(mysqli $conn): array
    {
        posmain_ensure_pos_settings_columns($conn);

        if (!posmain_settings_column_exists($conn, 'def_pos_store')) {
            $result = $conn->query('SELECT id FROM settings WHERE isdeleted = 0 ORDER BY id ASC LIMIT 1');

            return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : [];
        }

        $result = $conn->query(
            'SELECT id, def_pos_store, def_pos_employee, def_pos_fund, def_pos_client
             FROM settings
             WHERE isdeleted = 0
             ORDER BY id ASC
             LIMIT 1'
        );

        return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : [];
    }
}

if (!function_exists('posmain_truncate_invoice_info')) {
    function posmain_truncate_invoice_info(string $info, int $maxLength = 200): string
    {
        $info = trim($info);
        if ($info === '') {
            return $info;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($info, 'UTF-8') <= $maxLength) {
                return $info;
            }

            return mb_substr($info, 0, $maxLength, 'UTF-8');
        }

        if (strlen($info) <= $maxLength) {
            return $info;
        }

        return substr($info, 0, $maxLength);
    }
}

if (!function_exists('posmain_find_sales_account_id')) {
    function posmain_find_sales_account_id(mysqli $conn, int $preferredId = 91): int
    {
        foreach (array_unique([$preferredId, 93, 91]) as $candidateId) {
            if ($candidateId > 0 && posmain_acc_head_active_id($conn, $candidateId)) {
                return $candidateId;
            }
        }

        foreach ([
            "code = '3111'",
            "code = '311'",
            "code LIKE '31%'",
            "aname LIKE '%مبيع%'",
        ] as $whereSql) {
            $resolved = posmain_resolve_default_account_id(
                $conn,
                0,
                $whereSql . ' AND is_stock = 0 AND is_fund = 0 AND is_basic = 0'
            );
            if ($resolved > 0) {
                return $resolved;
            }
        }

        return 0;
    }
}

if (!function_exists('posmain_ensure_sales_account')) {
    /**
     * Shops bootstrapped for POS often have store/fund/client but no revenue account yet.
     */
    function posmain_ensure_sales_account(mysqli $conn, int $preferredId = 91): int
    {
        $existing = posmain_find_sales_account_id($conn, $preferredId);
        if ($existing > 0) {
            return $existing;
        }

        $parentId = posmain_insert_acc_head_if_missing($conn, [
            'code' => '311',
            'aname' => 'صافي المبيعات',
            'parent_id' => 0,
            'is_basic' => 1,
        ]);

        $salesId = posmain_insert_acc_head_if_missing($conn, [
            'code' => '3111',
            'aname' => 'المبيعات',
            'parent_id' => $parentId > 0 ? $parentId : 0,
        ]);
        if ($salesId > 0) {
            return $salesId;
        }

        return posmain_find_sales_account_id($conn, $preferredId);
    }
}

if (!function_exists('posmain_resolve_sales_account_id')) {
    function posmain_resolve_sales_account_id(mysqli $conn, int $preferredId = 91): int
    {
        return posmain_ensure_sales_account($conn, $preferredId);
    }
}

if (!function_exists('posmain_resolve_payment_bank_id')) {
    function posmain_resolve_payment_bank_id(mysqli $conn, int $preferredId): int
    {
        if ($preferredId > 0 && posmain_acc_head_active_id($conn, $preferredId)) {
            return $preferredId;
        }

        return posmain_resolve_default_account_id(
            $conn,
            0,
            '(parent_id = 124 OR code LIKE \'124%\') AND is_basic = 0'
        );
    }
}

if (!function_exists('posmain_acc_head_active_id')) {
    function posmain_acc_head_active_id(mysqli $conn, int $accountId): bool
    {
        if ($accountId <= 0) {
            return false;
        }

        $result = $conn->query(
            'SELECT id FROM acc_head WHERE id = ' . $accountId . ' AND isdeleted = 0 LIMIT 1'
        );

        return $result && $result->num_rows > 0;
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

if (!function_exists('posmain_resolve_pos_customer_id')) {
    function posmain_resolve_pos_customer_id(mysqli $conn, int $preferredId, array $settings = []): int
    {
        if ($preferredId <= 0) {
            $preferredId = (int) ($settings['def_pos_client'] ?? 0);
        }

        require_once __DIR__ . '/../classes/TableOrderService.php';

        return (new TableOrderService())->resolveDefaultCustomerId($conn, $preferredId);
    }
}

if (!function_exists('posmain_sync_pos_setting_defaults')) {
    /**
     * Repair settings rows that still point at deleted or missing acc_head ids.
     */
    function posmain_sync_pos_setting_defaults(mysqli $conn, array $settings): array
    {
        posmain_ensure_pos_settings_columns($conn);
        if (!posmain_settings_column_exists($conn, 'def_pos_store')) {
            return $settings;
        }

        $settingsId = (int) ($settings['id'] ?? 0);
        if ($settingsId < 1) {
            $settingsQuery = $conn->query(
                'SELECT id, def_pos_store, def_pos_employee, def_pos_fund, def_pos_client
                 FROM settings
                 ORDER BY id ASC
                 LIMIT 1'
            );
            if (!$settingsQuery || $settingsQuery->num_rows === 0) {
                return $settings;
            }
            $settings = $settingsQuery->fetch_assoc();
            $settingsId = (int) ($settings['id'] ?? 0);
        }
        if ($settingsId < 1) {
            return $settings;
        }

        $resolved = [
            'store_id' => posmain_resolve_default_account_id(
                $conn,
                (int) ($settings['def_pos_store'] ?? 0),
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
            'client_id' => posmain_resolve_pos_customer_id(
                $conn,
                (int) ($settings['def_pos_client'] ?? 0),
                $settings
            ),
        ];

        $nextStore = (int) ($settings['def_pos_store'] ?? 0);
        $nextEmp = (int) ($settings['def_pos_employee'] ?? 0);
        $nextFund = (int) ($settings['def_pos_fund'] ?? 0);
        $nextClient = (int) ($settings['def_pos_client'] ?? 0);
        $changed = false;

        if ($nextStore <= 0 || !posmain_acc_head_active_id($conn, $nextStore)) {
            if ($resolved['store_id'] > 0 && $nextStore !== $resolved['store_id']) {
                $nextStore = $resolved['store_id'];
                $changed = true;
            }
        }
        if ($nextEmp <= 0 || !posmain_acc_head_active_id($conn, $nextEmp)) {
            if ($resolved['emp_id'] > 0 && $nextEmp !== $resolved['emp_id']) {
                $nextEmp = $resolved['emp_id'];
                $changed = true;
            }
        }
        if ($nextFund <= 0 || !posmain_acc_head_active_id($conn, $nextFund)) {
            if ($resolved['fund_id'] > 0 && $nextFund !== $resolved['fund_id']) {
                $nextFund = $resolved['fund_id'];
                $changed = true;
            }
        }
        if ($nextClient <= 0 || !posmain_acc_head_active_id($conn, $nextClient)) {
            if ($resolved['client_id'] > 0 && $nextClient !== $resolved['client_id']) {
                $nextClient = $resolved['client_id'];
                $changed = true;
            }
        }

        if (!$changed) {
            return $settings;
        }

        $stmt = $conn->prepare('
            UPDATE settings
            SET def_pos_store = ?,
                def_pos_employee = ?,
                def_pos_fund = ?,
                def_pos_client = ?
            WHERE id = ?
        ');
        $stmt->bind_param('iiiii', $nextStore, $nextEmp, $nextFund, $nextClient, $settingsId);
        $stmt->execute();
        $stmt->close();

        $settings['def_pos_store'] = $nextStore;
        $settings['def_pos_employee'] = $nextEmp;
        $settings['def_pos_fund'] = $nextFund;
        $settings['def_pos_client'] = $nextClient;

        return $settings;
    }
}

if (!function_exists('posmain_resolve_pos_invoice_accounts')) {
    function posmain_resolve_pos_invoice_accounts(mysqli $conn, array $settings, array $posted): array
    {
        $defaults = posmain_resolve_pos_defaults($conn, $settings);

        $storeId = (int) ($posted['store_id'] ?? 0);
        if ($storeId <= 0 || posmain_resolve_default_account_id($conn, $storeId, 'is_stock = 1') !== $storeId) {
            $storeId = (int) ($defaults['store_id'] ?? 0);
        }

        $empId = (int) ($posted['emp_id'] ?? 0);
        if ($empId <= 0 || posmain_resolve_default_account_id($conn, $empId, 'parent_id = 35 AND is_basic = 0') !== $empId) {
            $empId = (int) ($defaults['emp_id'] ?? 0);
        }

        $fundId = (int) ($posted['fund_id'] ?? 0);
        if ($fundId <= 0 || posmain_resolve_default_account_id($conn, $fundId, 'is_fund = 1 AND is_basic = 0') !== $fundId) {
            $fundId = (int) ($defaults['fund_id'] ?? 0);
        }

        $clientId = posmain_resolve_pos_customer_id(
            $conn,
            (int) ($posted['acc2_id'] ?? 0),
            $settings
        );

        $paymentFundId = (int) ($posted['payment_fund_id'] ?? 0);
        if ($paymentFundId <= 0 || posmain_resolve_default_account_id($conn, $paymentFundId, 'is_fund = 1 AND is_basic = 0') !== $paymentFundId) {
            $paymentFundId = $fundId;
        }

        $paymentBankId = (int) ($posted['payment_bank_id'] ?? 0);
        $paidBank = (float) ($posted['paid_bank'] ?? 0);
        if ($paidBank > 0) {
            $paymentBankId = posmain_resolve_payment_bank_id($conn, $paymentBankId);
        }

        $salesAccountId = posmain_ensure_sales_account(
            $conn,
            (int) ($posted['sales_account_id'] ?? 91)
        );

        return [
            'store_id' => $storeId,
            'emp_id' => $empId,
            'fund_id' => $fundId,
            'acc2_id' => $clientId,
            'payment_fund_id' => $paymentFundId,
            'payment_bank_id' => $paymentBankId,
            'sales_account_id' => $salesAccountId,
        ];
    }
}

if (!function_exists('posmain_resolve_pos_defaults')) {
    function posmain_resolve_pos_defaults(mysqli $conn, array $settings): array
    {
        posmain_ensure_pos_default_accounts($conn);
        $settings = posmain_sync_pos_setting_defaults($conn, $settings);

        $preferredStoreId = (int) ($settings['def_pos_store'] ?? 0);
        if ($preferredStoreId < 1) {
            $tableCheck = $conn->query("SHOW TABLES LIKE 'myoptions'");
            if ($tableCheck && $tableCheck->num_rows > 0) {
                $optionRow = $conn->query("SELECT cur_value FROM myoptions WHERE oname = 'def_store' LIMIT 1");
                if ($optionRow && $optionRow->num_rows > 0) {
                    $preferredStoreId = (int) ($optionRow->fetch_assoc()['cur_value'] ?? 0);
                }
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
            'client_id' => posmain_resolve_pos_customer_id(
                $conn,
                (int) ($settings['def_pos_client'] ?? 0),
                $settings
            ),
        ];
    }
}
