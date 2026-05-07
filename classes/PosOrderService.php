<?php

class PosOrderService
{
    const TYPE_POS = 9;
    const TYPE_RECEIPT = 1;
    const SALES_ACCOUNT = 91;

    public function createOrMergeMoovaTableOrder(mysqli $conn, array $scope, array $payload)
    {
        $tenant = (int) ($scope['tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? 0);
        $userId = (int) ($scope['user_id'] ?? 1);
        if ($userId < 1) {
            $userId = 1;
        }

        $branchId = trim((string) ($payload['branchId'] ?? ''));
        $tableToken = trim((string) ($payload['tableId'] ?? $payload['tableNumber'] ?? ''));
        if ($tableToken === '') {
            throw new Exception('TABLE_REQUIRED');
        }

        $defaults = $this->loadTenantDefaults($conn, $tenant, $branch);
        $table = $this->resolveTable($conn, $tenant, $branch, $branchId, $tableToken);
        $incomingItems = $this->resolveIncomingItems($conn, $tenant, $branch, $payload['items'] ?? []);

        if (!$incomingItems) {
            throw new Exception('NO_VALID_ITEMS');
        }

        $existingOrder = $this->findActiveTableOrderForUpdate($conn, $tenant, $branch, (int) $table['id']);
        $lines = [];
        $isMerge = false;

        if ($existingOrder) {
            $isMerge = true;
            $lines = $this->loadExistingOrderLines($conn, $tenant, $branch, (int) $existingOrder['id']);
        }

        foreach ($incomingItems as $item) {
            $id = (int) $item['id'];
            if (isset($lines[$id])) {
                $lines[$id]['qty'] += (float) $item['qty'];
                continue;
            }

            $lines[$id] = [
                'item_id' => $id,
                'name' => $item['name'],
                'barcode' => $item['barcode'],
                'qty' => (float) $item['qty'],
                'price' => (float) $item['price'],
                'discount' => 0.0,
                'u_val' => 1.0,
                'cost_price' => (float) $item['cost_price'],
                'itmqty' => (float) $item['itmqty'],
            ];
        }

        if (!$lines) {
            throw new Exception('NO_VALID_ITEMS');
        }

        $totals = $this->calculateTotals($lines);
        $proDate = date('Y-m-d');
        $info = $this->buildOrderInfo($payload, $table);

        if ($existingOrder) {
            $orderId = (int) $existingOrder['id'];
            $proId = (int) ($existingOrder['pro_id'] ?: $orderId);
            $this->updateOrderHeader($conn, $tenant, $branch, $orderId, $defaults, $totals, $info, $proDate, $userId, (int) $table['id']);
            $this->clearOrderAccountingAndDetails($conn, $tenant, $branch, $orderId);
        } else {
            $proId = $this->getNextInvoiceNumber($conn, self::TYPE_POS, $tenant, $branch);
            $orderId = $this->insertOrderHeader($conn, $tenant, $branch, $proId, $defaults, $totals, $info, $proDate, $userId, (int) $table['id']);
        }

        $journalHeadId = $this->insertMainJournal($conn, $tenant, $branch, $orderId, $proId, $defaults, $totals, $proDate, $userId);
        $this->insertOrderDetails($conn, $tenant, $branch, $orderId, $lines, $defaults['store_id']);
        $profit = $this->refreshOrderProfit($conn, $tenant, $branch, $orderId);
        $this->markTableBusy($conn, (int) $table['id']);
        $this->logProcess($conn, 'add cash');

        return [
            'order_id' => $orderId,
            'pro_id' => $proId,
            'table_id' => (int) $table['id'],
            'table_name' => $table['tname'],
            'total' => $totals['total'],
            'discount' => $totals['discount'],
            'net' => $totals['net'],
            'profit' => $profit,
            'journal_head_id' => $journalHeadId,
            'merged' => $isMerge,
        ];
    }

    private function loadTenantDefaults(mysqli $conn, $tenant, $branch)
    {
        $settings = $this->queryOne($conn, "
            SELECT *
            FROM settings
            WHERE isdeleted = 0
              AND tenant = ?
              AND branch = ?
            ORDER BY id ASC
            LIMIT 1
        ", [$tenant, $branch]);

        if (!$settings) {
            throw new Exception('MISSING_TENANT_SETTINGS');
        }

        $storeId = $this->resolveAccountDefault($conn, (int) ($settings['def_pos_store'] ?? 0), $tenant, $branch, "is_stock = 1");
        $empId = $this->resolveAccountDefault($conn, (int) ($settings['def_pos_employee'] ?? 0), $tenant, $branch, "parent_id = 35 AND is_basic = 0");
        $clientId = $this->resolveAccountDefault($conn, (int) ($settings['def_pos_client'] ?? 0), $tenant, $branch, "code LIKE '122%' AND is_basic = 0");
        $fundId = $this->resolveAccountDefault($conn, (int) ($settings['def_pos_fund'] ?? 0), $tenant, $branch, "is_fund = 1 AND is_basic = 0");

        if ($storeId < 1 || $empId < 1 || $clientId < 1 || $fundId < 1) {
            throw new Exception('MISSING_DEFAULTS');
        }

        return [
            'store_id' => $storeId,
            'emp_id' => $empId,
            'client_id' => $clientId,
            'fund_id' => $fundId,
        ];
    }

    private function resolveAccountDefault(mysqli $conn, $preferredId, $tenant, $branch, $whereSql)
    {
        if ($preferredId > 0) {
            $row = $this->queryOne($conn, "
                SELECT id
                FROM acc_head
                WHERE id = ?
                  AND isdeleted = 0
                  AND tenant = ?
                  AND branch = ?
                  AND {$whereSql}
                LIMIT 1
            ", [$preferredId, $tenant, $branch]);

            if ($row) {
                return (int) $row['id'];
            }
        }

        $row = $this->queryOne($conn, "
            SELECT id
            FROM acc_head
            WHERE isdeleted = 0
              AND tenant = ?
              AND branch = ?
              AND {$whereSql}
            ORDER BY id ASC
            LIMIT 1
        ", [$tenant, $branch]);

        return $row ? (int) $row['id'] : 0;
    }

    private function resolveTable(mysqli $conn, $tenant, $branch, $moovaBranchId, $tableToken)
    {
        $mapped = $this->queryOne($conn, "
            SELECT t.*
            FROM moova_pos_table_links m
            INNER JOIN tables t ON t.id = m.pos_table_id
            WHERE m.moova_branch_id = ?
              AND m.moova_table_id = ?
              AND m.pos_tenant = ?
              AND m.pos_branch = ?
              AND m.status = 'active'
              AND t.isdeleted = 0
            LIMIT 1
        ", [$moovaBranchId, $tableToken, $tenant, $branch]);

        if ($mapped) {
            return $mapped;
        }

        $exactTable = $this->queryOne($conn, "
            SELECT *
            FROM tables
            WHERE isdeleted = 0
              AND (
                  CAST(branch AS CHAR) = CAST(? AS CHAR)
                  OR (? = 0 AND (branch IS NULL OR branch = '' OR branch = '0'))
              )
              AND (CAST(id AS CHAR) = ? OR tname = ?)
            ORDER BY id ASC
            LIMIT 1
        ", [$branch, $branch, $tableToken, $tableToken]);

        if ($exactTable) {
            return $exactTable;
        }

        $namedTable = $this->queryOne($conn, "
            SELECT *
            FROM tables
            WHERE isdeleted = 0
              AND (
                  CAST(branch AS CHAR) = CAST(? AS CHAR)
                  OR (? = 0 AND (branch IS NULL OR branch = '' OR branch = '0'))
              )
              AND tname = ?
            ORDER BY id ASC
            LIMIT 1
        ", [$branch, $branch, 'طاولة ' . $tableToken]);

        if ($namedTable) {
            return $namedTable;
        }

        $tableRows = $this->queryAll($conn, "
            SELECT *
            FROM tables
            WHERE isdeleted = 0
              AND (
                  CAST(branch AS CHAR) = CAST(? AS CHAR)
                  OR (? = 0 AND (branch IS NULL OR branch = '' OR branch = '0'))
              )
              AND tname LIKE ?
            ORDER BY id ASC
            LIMIT 2
        ", [$branch, $branch, '%' . $tableToken . '%']);

        if (count($tableRows) === 1) {
            return $tableRows[0];
        }

        if (count($tableRows) > 1) {
            throw new Exception('TABLE_MAPPING_AMBIGUOUS');
        }

        throw new Exception('TABLE_NOT_FOUND');
    }

    private function resolveIncomingItems(mysqli $conn, $tenant, $branch, array $items)
    {
        $resolved = [];

        foreach ($items as $item) {
            $providerItemId = trim((string) ($item['itemId'] ?? ''));
            $qty = (float) ($item['qty'] ?? 0);
            if ($providerItemId === '' || $qty <= 0) {
                continue;
            }

            $itemIdInt = ctype_digit($providerItemId) ? (int) $providerItemId : 0;
            $row = $this->queryOne($conn, "
                SELECT id, iname, barcode, price1, cost_price, itmqty
                FROM myitems
                WHERE isdeleted = 0
                  AND tenant = ?
                  AND branch = ?
                  AND (id = ? OR barcode = ?)
                LIMIT 1
            ", [$tenant, $branch, $itemIdInt, $providerItemId]);

            if (!$row) {
                throw new Exception('ITEM_NOT_FOUND:' . $providerItemId);
            }

            $resolved[] = [
                'id' => (int) $row['id'],
                'name' => $row['iname'],
                'barcode' => $row['barcode'],
                'qty' => $qty,
                'price' => (float) $row['price1'],
                'cost_price' => (float) $row['cost_price'],
                'itmqty' => (float) $row['itmqty'],
            ];
        }

        return $resolved;
    }

    private function findActiveTableOrderForUpdate(mysqli $conn, $tenant, $branch, $tableId)
    {
        return $this->queryOne($conn, "
            SELECT *
            FROM ot_head
            WHERE tenant = ?
              AND branch = ?
              AND table_id = ?
              AND pro_tybe = ?
              AND isdeleted = 0
              AND fat_net > 0
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ", [$tenant, $branch, $tableId, self::TYPE_POS]);
    }

    private function loadExistingOrderLines(mysqli $conn, $tenant, $branch, $orderId)
    {
        $rows = $this->queryAll($conn, "
            SELECT
                fd.item_id,
                fd.qty_in,
                fd.qty_out,
                fd.price,
                fd.discount,
                fd.u_val,
                m.iname,
                m.barcode,
                m.cost_price,
                m.itmqty
            FROM fat_details fd
            INNER JOIN myitems m ON m.id = fd.item_id
            WHERE fd.fatid = ?
              AND fd.isdeleted = 0
              AND fd.tenant = ?
              AND fd.branch = ?
            ORDER BY fd.id ASC
        ", [$orderId, $tenant, $branch]);

        $lines = [];
        foreach ($rows as $row) {
            $itemId = (int) $row['item_id'];
            $qty = abs((float) $row['qty_out'] - (float) $row['qty_in']);
            if ($qty <= 0) {
                continue;
            }

            if (!isset($lines[$itemId])) {
                $lines[$itemId] = [
                    'item_id' => $itemId,
                    'name' => $row['iname'],
                    'barcode' => $row['barcode'],
                    'qty' => 0.0,
                    'price' => (float) $row['price'],
                    'discount' => (float) ($row['discount'] ?? 0),
                    'u_val' => (float) ($row['u_val'] ?: 1),
                    'cost_price' => (float) $row['cost_price'],
                    'itmqty' => (float) $row['itmqty'],
                ];
            }

            $lines[$itemId]['qty'] += $qty;
        }

        return $lines;
    }

    private function calculateTotals(array $lines)
    {
        $total = 0.0;
        $net = 0.0;

        foreach ($lines as $line) {
            $qty = (float) $line['qty'];
            $price = (float) $line['price'];
            $discount = (float) ($line['discount'] ?? 0);
            $total += $qty * $price;
            $net += $qty * ($price - $discount);
        }

        $discountTotal = max(0, $total - $net);
        $discPercent = $total > 0 && $discountTotal > 0 ? round(($discountTotal / $total) * 100, 2) : 0;

        return [
            'total' => $total,
            'discount' => $discountTotal,
            'disc_percent' => $discPercent,
            'net' => $net,
        ];
    }

    private function buildOrderInfo(array $payload, array $table)
    {
        $pieces = [];
        $moovaOrderId = trim((string) ($payload['cofeOrderId'] ?? ''));
        if ($moovaOrderId !== '') {
            $pieces[] = 'Moova Order: ' . $moovaOrderId;
        }
        $pieces[] = 'طاولة: ' . $table['tname'];
        $pieces[] = 'نوع الطلب: طاولة';

        return implode(' - ', $pieces);
    }

    private function insertOrderHeader(mysqli $conn, $tenant, $branch, $proId, array $defaults, array $totals, $info, $proDate, $userId, $tableId)
    {
        $this->execute($conn, "
            INSERT INTO ot_head (
                pro_id, branch_id, table_id, order_type, pro_tybe, is_stock, is_journal, journal_tybe,
                info, pro_date, accural_date, pro_pattren, pro_serial, price_list, store_id, emp_id,
                emp2_id, acc1, acc2, pro_value, fat_cost, cost_center, profit, fat_total, fat_disc,
                fat_disc_per, fat_plus, fat_plus_per, fat_tax, fat_tax_per, fat_net, paid_amount,
                remaining_amount, payment_status, invoice_status, user, tenant, branch, order_status
            ) VALUES (
                ?, ?, ?, 'table', ?, 1, 1, ?,
                ?, ?, ?, 1, '', 1, ?, ?,
                ?, ?, ?, ?, 0, 1, 0, ?, ?,
                ?, 0, 0, 0, 0, ?, 0,
                ?, 'unpaid', 'draft', ?, ?, ?, 'active'
            )
        ", [
            $proId,
            $branch,
            $tableId,
            self::TYPE_POS,
            self::TYPE_POS,
            $info,
            $proDate,
            $proDate,
            $defaults['store_id'],
            $defaults['emp_id'],
            $defaults['emp_id'],
            $defaults['fund_id'],
            $defaults['client_id'],
            $totals['total'],
            $totals['total'],
            $totals['discount'],
            $totals['disc_percent'],
            $totals['net'],
            $totals['net'],
            $userId,
            $tenant,
            $branch,
        ]);

        return (int) $conn->insert_id;
    }

    private function updateOrderHeader(mysqli $conn, $tenant, $branch, $orderId, array $defaults, array $totals, $info, $proDate, $userId, $tableId)
    {
        $this->execute($conn, "
            UPDATE ot_head
            SET pro_tybe = ?,
                branch_id = ?,
                table_id = ?,
                order_type = 'table',
                info = ?,
                accural_date = ?,
                pro_serial = '',
                store_id = ?,
                emp_id = ?,
                emp2_id = ?,
                acc1 = ?,
                acc2 = ?,
                pro_value = ?,
                fat_total = ?,
                fat_disc = ?,
                fat_disc_per = ?,
                fat_plus = 0,
                fat_plus_per = 0,
                fat_tax = 0,
                fat_tax_per = 0,
                fat_net = ?,
                paid_amount = 0,
                remaining_amount = ?,
                payment_status = 'unpaid',
                invoice_status = 'draft',
                order_status = 'active',
                user = ?,
                tenant = ?,
                branch = ?
            WHERE id = ?
              AND tenant = ?
              AND branch = ?
        ", [
            self::TYPE_POS,
            $branch,
            $tableId,
            $info,
            $proDate,
            $defaults['store_id'],
            $defaults['emp_id'],
            $defaults['emp_id'],
            $defaults['fund_id'],
            $defaults['client_id'],
            $totals['total'],
            $totals['total'],
            $totals['discount'],
            $totals['disc_percent'],
            $totals['net'],
            $totals['net'],
            $userId,
            $tenant,
            $branch,
            $orderId,
            $tenant,
            $branch,
        ]);
    }

    private function clearOrderAccountingAndDetails(mysqli $conn, $tenant, $branch, $orderId)
    {
        $journalRows = $this->queryAll($conn, "
            SELECT id
            FROM journal_heads
            WHERE op_id = ?
              AND tenant = ?
              AND branch = ?
            FOR UPDATE
        ", [$orderId, $tenant, $branch]);

        foreach ($journalRows as $journalRow) {
            $this->execute($conn, "
                DELETE FROM journal_entries
                WHERE journal_id = ?
                  AND tenant = ?
                  AND branch = ?
            ", [(int) $journalRow['id'], $tenant, $branch]);
        }

        $this->execute($conn, "
            DELETE FROM journal_heads
            WHERE op_id = ?
              AND tenant = ?
              AND branch = ?
        ", [$orderId, $tenant, $branch]);

        $this->execute($conn, "
            DELETE FROM ot_head
            WHERE op2 = ?
              AND tenant = ?
              AND branch = ?
        ", [$orderId, $tenant, $branch]);

        $this->execute($conn, "
            DELETE FROM fat_details
            WHERE fatid = ?
              AND tenant = ?
              AND branch = ?
        ", [$orderId, $tenant, $branch]);
    }

    private function insertMainJournal(mysqli $conn, $tenant, $branch, $orderId, $proId, array $defaults, array $totals, $proDate, $userId)
    {
        $journalId = $this->getNextJournalId($conn, $tenant, $branch);
        $details = 'فاتورة ريسيت _ ' . $orderId;

        $this->execute($conn, "
            INSERT INTO journal_heads (journal_id, total, jdate, details, user, op_id, pro_tybe, tenant, branch)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [$journalId, $totals['net'], $proDate, $details, $userId, $orderId, self::TYPE_POS, $tenant, $branch]);

        $journalHeadId = (int) $conn->insert_id;

        $this->execute($conn, "
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id, tenant, branch)
            VALUES (?, ?, ?, 0, 0, ?, ?, ?)
        ", [$journalHeadId, $defaults['client_id'], $totals['net'], $orderId, $tenant, $branch]);

        $this->execute($conn, "
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id, tenant, branch)
            VALUES (?, ?, 0, ?, 1, ?, ?, ?)
        ", [$journalHeadId, self::SALES_ACCOUNT, $totals['net'], $orderId, $tenant, $branch]);

        return $journalHeadId;
    }

    private function insertOrderDetails(mysqli $conn, $tenant, $branch, $orderId, array $lines, $storeId)
    {
        foreach ($lines as $line) {
            $qty = (float) $line['qty'];
            if ($qty <= 0) {
                continue;
            }

            $uVal = (float) ($line['u_val'] ?: 1);
            if ($uVal <= 0) {
                $uVal = 1;
            }
            $price = (float) $line['price'];
            $discount = (float) ($line['discount'] ?? 0);
            $qtyOut = $qty * $uVal;
            $detValue = $qty * ($price - $discount);
            $unitPrice = $price / $uVal;
            $costPrice = (float) $line['cost_price'];
            $profit = $qty * $uVal * ($unitPrice - $costPrice);

            $this->execute($conn, "
                INSERT INTO fat_details (
                    pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price,
                    discount, det_value, fatid, fat_tybe, det_store, cost_price, profit,
                    tenant, branch
                ) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                self::TYPE_POS,
                $orderId,
                (int) $line['item_id'],
                $uVal,
                $qtyOut,
                $unitPrice,
                $discount,
                $detValue,
                $orderId,
                self::TYPE_POS,
                $storeId,
                $costPrice,
                $profit,
                $tenant,
                $branch,
            ]);
        }
    }

    private function refreshOrderProfit(mysqli $conn, $tenant, $branch, $orderId)
    {
        $row = $this->queryOne($conn, "
            SELECT SUM(profit) AS tprofit
            FROM fat_details
            WHERE fatid = ?
              AND tenant = ?
              AND branch = ?
        ", [$orderId, $tenant, $branch]);

        $profit = (float) ($row['tprofit'] ?? 0);
        $this->execute($conn, "
            UPDATE ot_head
            SET profit = ?
            WHERE id = ?
              AND tenant = ?
              AND branch = ?
        ", [$profit, $orderId, $tenant, $branch]);

        return $profit;
    }

    private function markTableBusy(mysqli $conn, $tableId)
    {
        $this->execute($conn, "UPDATE tables SET table_case = 1 WHERE id = ?", [$tableId]);
    }

    private function logProcess(mysqli $conn, $type)
    {
        $this->execute($conn, "INSERT INTO process (type) VALUES (?)", [$type]);
    }

    private function getNextInvoiceNumber(mysqli $conn, $invoiceType, $tenant, $branch)
    {
        $row = $this->queryOne($conn, "
            SELECT MAX(CAST(pro_id AS UNSIGNED)) AS max_id
            FROM ot_head
            WHERE pro_tybe = ?
              AND tenant = ?
              AND branch = ?
        ", [$invoiceType, $tenant, $branch]);

        return $row && $row['max_id'] ? ((int) $row['max_id'] + 1) : 1;
    }

    private function getNextJournalId(mysqli $conn, $tenant, $branch)
    {
        $row = $this->queryOne($conn, "
            SELECT MAX(journal_id) AS max_id
            FROM journal_heads
            WHERE tenant = ?
              AND branch = ?
        ", [$tenant, $branch]);

        return $row && $row['max_id'] ? ((int) $row['max_id'] + 1) : 1;
    }

    private function queryOne(mysqli $conn, $sql, array $params = [])
    {
        $rows = $this->queryAll($conn, $sql, $params);
        return $rows ? $rows[0] : null;
    }

    private function queryAll(mysqli $conn, $sql, array $params = [])
    {
        $stmt = $this->prepareStatement($conn, $sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function execute(mysqli $conn, $sql, array $params = [])
    {
        $stmt = $this->prepareStatement($conn, $sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $stmt->close();
    }

    private function prepareStatement(mysqli $conn, $sql)
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('SQL_PREPARE_FAILED: ' . $conn->error);
        }

        return $stmt;
    }

    private function bindParams(mysqli_stmt $stmt, array &$params)
    {
        if ($params) {
            $types = '';
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
            }

            $refs = [];
            $refs[] = $types;
            foreach ($params as $key => $value) {
                $params[$key] = $value;
                $refs[] = &$params[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }
    }
}
