<?php

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
