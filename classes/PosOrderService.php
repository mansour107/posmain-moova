<?php

require_once __DIR__ . '/Sync/DocumentCounterService.php';
require_once __DIR__ . '/Recipe/ExternalOrderLineIdentityService.php';
require_once __DIR__ . '/Recipe/RecipeDecimal.php';
require_once __DIR__ . '/Recipe/RecipeOrderLifecycleService.php';

class PosOrderService
{
    const TYPE_POS = 9;
    const TYPE_RECEIPT = 1;
    const SALES_ACCOUNT = 91;

    private RecipeOrderLifecycleService $recipeLifecycleService;

    public function __construct(?RecipeOrderLifecycleService $recipeLifecycleService = null)
    {
        $this->recipeLifecycleService = $recipeLifecycleService ?: new RecipeOrderLifecycleService();
    }

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
        $moovaOrderId = trim((string) ($payload['cofeOrderId'] ?? $payload['moovaOrderId'] ?? ''));

        if (!$incomingItems) {
            throw new Exception('NO_VALID_ITEMS');
        }

        $lines = [];

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
        $existingOrder = $this->findActiveTableOrderForUpdate($conn, $tenant, $branch, (int) $table['id']);
        $isMerge = (bool) $existingOrder;

        if ($existingOrder) {
            $orderId = (int) $existingOrder['id'];
            $proId = (int) ($existingOrder['pro_id'] ?: $orderId);
        } else {
            $proId = $this->getNextInvoiceNumber($conn, self::TYPE_POS, $tenant, $branch);
            $orderId = $this->insertOrderHeader($conn, $tenant, $branch, $proId, $defaults, $totals, $info, $proDate, $userId, (int) $table['id']);
        }

        $insertedLines = $this->upsertOrderDetails($conn, $tenant, $branch, $orderId, $lines, $defaults['store_id']);
        $moovaMappedLines = [];
        if ($moovaOrderId !== '') {
            $moovaMappedLines = $this->insertMoovaLineMappings($conn, $tenant, $branch, $moovaOrderId, $orderId, $insertedLines);
            $this->registerExternalLineIdentities(
                $conn,
                $tenant,
                $branch,
                $scope,
                $payload,
                $moovaOrderId,
                $orderId,
                $incomingItems,
                $insertedLines,
                (int) $defaults['store_id']
            );
            $this->recordRecipeOrderLinesAdded(
                $conn,
                $this->recipeContextsFromMoovaIncomingItems(
                    $conn,
                    $tenant,
                    $branch,
                    $scope,
                    $payload,
                    $moovaOrderId,
                    $orderId,
                    $incomingItems,
                    $insertedLines
                )
            );
        }
        $receiptState = $this->refreshReceiptTotalsAndAccounting($conn, $tenant, $branch, $orderId, $defaults, $proDate, $userId);
        $this->markTableBusy($conn, (int) $table['id']);
        $this->logProcess($conn, 'add cash');
        $lineSnapshot = $moovaOrderId === ''
            ? null
            : $this->getMoovaOrderLineStateSnapshot($conn, $tenant, $branch, $orderId, $moovaOrderId);

        return [
            'order_id' => $orderId,
            'pro_id' => $proId,
            'table_id' => (int) $table['id'],
            'table_name' => $table['tname'],
            'total' => $receiptState['total'],
            'discount' => $receiptState['discount'],
            'net' => $receiptState['net'],
            'profit' => $receiptState['profit'],
            'journal_head_id' => $receiptState['journal_head_id'],
            'merged' => $isMerge,
            'state_hash' => $lineSnapshot['hash'] ?? null,
            'state_payload' => $lineSnapshot['payload'] ?? null,
        ];
    }

    public function getMoovaOrderStateSnapshot(mysqli $conn, $tenant, $branch, $orderId)
    {
        $tenant = (int) $tenant;
        $branch = (int) $branch;
        $orderId = (int) $orderId;
        if ($orderId < 1) {
            return null;
        }

        $head = $this->queryOne($conn, "
            SELECT id, pro_id, table_id, order_type, pro_tybe, isdeleted, payment_status,
                   invoice_status, order_status, fat_total, fat_disc, fat_plus, fat_tax,
                   fat_net, paid_amount, remaining_amount
            FROM ot_head
            WHERE id = ?
              AND tenant = ?
              AND branch = ?
            LIMIT 1
        ", [$orderId, $tenant, $branch]);

        if (!$head) {
            return null;
        }

        $rows = $this->queryAll($conn, "
            SELECT item_id, u_val, qty_in, qty_out, price, discount, det_value,
                   det_store, cost_price, profit, isdeleted
            FROM fat_details
            WHERE fatid = ?
              AND tenant = ?
              AND branch = ?
            ORDER BY item_id ASC, id ASC
        ", [$orderId, $tenant, $branch]);

        $snapshot = [
            'head' => [
                'id' => (int) $head['id'],
                'pro_id' => (int) ($head['pro_id'] ?? 0),
                'table_id' => (int) ($head['table_id'] ?? 0),
                'order_type' => (string) ($head['order_type'] ?? ''),
                'pro_tybe' => (int) ($head['pro_tybe'] ?? 0),
                'isdeleted' => (int) ($head['isdeleted'] ?? 0),
                'payment_status' => (string) ($head['payment_status'] ?? ''),
                'invoice_status' => (string) ($head['invoice_status'] ?? ''),
                'order_status' => (string) ($head['order_status'] ?? ''),
                'fat_total' => $this->normalizeStateNumber($head['fat_total'] ?? 0),
                'fat_disc' => $this->normalizeStateNumber($head['fat_disc'] ?? 0),
                'fat_plus' => $this->normalizeStateNumber($head['fat_plus'] ?? 0),
                'fat_tax' => $this->normalizeStateNumber($head['fat_tax'] ?? 0),
                'fat_net' => $this->normalizeStateNumber($head['fat_net'] ?? 0),
                'paid_amount' => $this->normalizeStateNumber($head['paid_amount'] ?? 0),
                'remaining_amount' => $this->normalizeStateNumber($head['remaining_amount'] ?? 0),
            ],
            'lines' => [],
        ];

        foreach ($rows as $row) {
            $snapshot['lines'][] = [
                'item_id' => (int) ($row['item_id'] ?? 0),
                'u_val' => $this->normalizeStateNumber($row['u_val'] ?? 1),
                'qty_in' => $this->normalizeStateNumber($row['qty_in'] ?? 0),
                'qty_out' => $this->normalizeStateNumber($row['qty_out'] ?? 0),
                'price' => $this->normalizeStateNumber($row['price'] ?? 0),
                'discount' => $this->normalizeStateNumber($row['discount'] ?? 0),
                'det_value' => $this->normalizeStateNumber($row['det_value'] ?? 0),
                'det_store' => (int) ($row['det_store'] ?? 0),
                'cost_price' => $this->normalizeStateNumber($row['cost_price'] ?? 0),
                'profit' => $this->normalizeStateNumber($row['profit'] ?? 0),
                'isdeleted' => (int) ($row['isdeleted'] ?? 0),
            ];
        }

        return [
            'hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'payload' => $snapshot,
        ];
    }

    public function getMoovaOrderLineStateSnapshot(mysqli $conn, $tenant, $branch, $orderId, $moovaOrderId, $forUpdate = false)
    {
        $tenant = (int) $tenant;
        $branch = (int) $branch;
        $orderId = (int) $orderId;
        $moovaOrderId = trim((string) $moovaOrderId);
        if ($orderId < 1 || $moovaOrderId === '') {
            return null;
        }

        $lockSql = $forUpdate ? ' FOR UPDATE' : '';
        $rows = $this->queryAll($conn, "
            SELECT
                l.id AS mapping_id,
                l.moova_order_id,
                l.pos_order_id,
                l.fat_detail_id,
                l.item_id AS mapped_item_id,
                l.qty_out AS mapped_qty_out,
                l.price AS mapped_price,
                l.discount AS mapped_discount,
                l.det_value AS mapped_det_value,
                l.line_hash AS mapped_line_hash,
                l.status AS mapping_status,
                fd.item_id,
                fd.u_val,
                fd.qty_in,
                fd.qty_out,
                fd.price,
                fd.discount,
                fd.det_value,
                fd.det_store,
                fd.cost_price,
                fd.profit,
                fd.isdeleted
            FROM moova_pos_order_lines l
            INNER JOIN fat_details fd
                    ON fd.id = l.fat_detail_id
                   AND fd.tenant = l.pos_tenant
                   AND fd.branch = l.pos_branch
            WHERE l.pos_tenant = ?
              AND l.pos_branch = ?
              AND l.pos_order_id = ?
              AND l.moova_order_id = ?
              AND l.status = 'active'
            ORDER BY l.id ASC" . $lockSql, [$tenant, $branch, $orderId, $moovaOrderId]);

        $payload = [
            'moova_order_id' => $moovaOrderId,
            'pos_order_id' => $orderId,
            'lines' => [],
        ];

        foreach ($rows as $row) {
            $row['detail_consistent'] = $this->isMappedDetailConsistent($conn, $tenant, $branch, (int) $row['fat_detail_id'], $row);
            $payload['lines'][] = $this->normalizeMappedLineState($row);
        }

        return [
            'hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'payload' => $payload,
            'lines' => $payload['lines'],
        ];
    }

    public function replaceMoovaTableOrder(mysqli $conn, array $scope, $orderId, array $payload)
    {
        $tenant = (int) ($scope['tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? 0);
        $userId = (int) ($scope['user_id'] ?? 1);
        if ($userId < 1) {
            $userId = 1;
        }

        $moovaOrderId = trim((string) ($payload['moovaOrderId'] ?? $payload['orderId'] ?? $payload['cofeOrderId'] ?? ''));
        if ($moovaOrderId === '') {
            throw new Exception('MOOVA_ORDER_REQUIRED');
        }

        $order = $this->findMoovaOrderForUpdate($conn, $tenant, $branch, (int) $orderId);
        $this->assertEditableMoovaTableOrder($order);
        $existingLines = $this->getMoovaOrderLineStateSnapshot($conn, $tenant, $branch, (int) $order['id'], $moovaOrderId, true);
        if (!$existingLines || empty($existingLines['lines'])) {
            throw new Exception('POS_ORDER_LINES_UNMAPPED');
        }
        $expectedStateHash = trim((string) ($payload['expectedStateHash'] ?? ''));
        if ($expectedStateHash !== '' && !hash_equals($expectedStateHash, (string) $existingLines['hash'])) {
            throw new Exception('POS_ORDER_LINES_CHANGED');
        }

        $table = $this->findTableById($conn, (int) $order['table_id']);
        if (!$table) {
            throw new Exception('TABLE_NOT_FOUND');
        }

        $defaults = $this->loadTenantDefaults($conn, $tenant, $branch);
        $incomingItems = $this->resolveIncomingItems($conn, $tenant, $branch, $payload['items'] ?? []);
        $lines = $this->buildReplacementLines($incomingItems);
        if (!$lines) {
            throw new Exception('NO_VALID_ITEMS');
        }

        $proDate = date('Y-m-d');
        $proId = (int) ($order['pro_id'] ?: $order['id']);

        $this->recordRecipeOrderLinesCancelled(
            $conn,
            $this->recipeContextsFromMoovaMappedLines(
                $conn,
                $tenant,
                $branch,
                $scope,
                $payload,
                $moovaOrderId,
                (int) $order['id'],
                $existingLines['lines'] ?? []
            ),
            'moova_order_replaced'
        );
        $this->deactivateMoovaMappedLines($conn, $tenant, $branch, (int) $order['id'], $moovaOrderId, 'replaced');
        $insertedLines = $this->upsertOrderDetails($conn, $tenant, $branch, (int) $order['id'], $lines, $defaults['store_id']);
        $moovaMappedLines = $this->insertMoovaLineMappings($conn, $tenant, $branch, $moovaOrderId, (int) $order['id'], $insertedLines);
        $this->registerExternalLineIdentities(
            $conn,
            $tenant,
            $branch,
            $scope,
            $payload,
            $moovaOrderId,
            (int) $order['id'],
            $incomingItems,
            $insertedLines,
            (int) $defaults['store_id']
        );
        $this->recordRecipeOrderLinesAdded(
            $conn,
            $this->recipeContextsFromMoovaIncomingItems(
                $conn,
                $tenant,
                $branch,
                $scope,
                $payload,
                $moovaOrderId,
                (int) $order['id'],
                $incomingItems,
                $insertedLines
            )
        );
        $receiptState = $this->refreshReceiptTotalsAndAccounting($conn, $tenant, $branch, (int) $order['id'], $defaults, $proDate, $userId);
        $this->logProcess($conn, 'edit moova pos order');
        $snapshot = $this->getMoovaOrderLineStateSnapshot($conn, $tenant, $branch, (int) $order['id'], $moovaOrderId);

        return [
            'order_id' => (int) $order['id'],
            'pro_id' => $proId,
            'table_id' => (int) $table['id'],
            'table_name' => $table['tname'],
            'total' => $receiptState['total'],
            'discount' => $receiptState['discount'],
            'net' => $receiptState['net'],
            'profit' => $receiptState['profit'],
            'journal_head_id' => $receiptState['journal_head_id'],
            'state_hash' => $snapshot['hash'] ?? null,
            'state_payload' => $snapshot['payload'] ?? null,
        ];
    }

    public function cancelMoovaTableOrder(mysqli $conn, array $scope, $orderId, $moovaOrderId = null, $expectedStateHash = null)
    {
        $tenant = (int) ($scope['tenant'] ?? 0);
        $branch = (int) ($scope['branch'] ?? 0);
        $userId = (int) ($scope['user_id'] ?? 1);
        if ($userId < 1) {
            $userId = 1;
        }
        $moovaOrderId = trim((string) $moovaOrderId);
        if ($moovaOrderId === '') {
            throw new Exception('MOOVA_ORDER_REQUIRED');
        }

        $order = $this->findMoovaOrderForUpdate($conn, $tenant, $branch, (int) $orderId);
        $this->assertEditableMoovaTableOrder($order);
        $existingLines = $this->getMoovaOrderLineStateSnapshot($conn, $tenant, $branch, (int) $order['id'], $moovaOrderId, true);
        if (!$existingLines || empty($existingLines['lines'])) {
            throw new Exception('POS_ORDER_LINES_UNMAPPED');
        }
        $expectedStateHash = trim((string) $expectedStateHash);
        if ($expectedStateHash !== '' && !hash_equals($expectedStateHash, (string) $existingLines['hash'])) {
            throw new Exception('POS_ORDER_LINES_CHANGED');
        }

        $tableId = (int) ($order['table_id'] ?? 0);
        $defaults = $this->loadTenantDefaults($conn, $tenant, $branch);
        $this->recordRecipeOrderLinesCancelled(
            $conn,
            $this->recipeContextsFromMoovaMappedLines(
                $conn,
                $tenant,
                $branch,
                $scope,
                ['moovaOrderId' => $moovaOrderId],
                $moovaOrderId,
                (int) $order['id'],
                $existingLines['lines'] ?? []
            ),
            'moova_order_cancelled'
        );
        $this->deactivateMoovaMappedLines($conn, $tenant, $branch, (int) $order['id'], $moovaOrderId, 'cancelled');
        $receiptState = $this->refreshReceiptTotalsAndAccounting($conn, $tenant, $branch, (int) $order['id'], $defaults, date('Y-m-d'), $userId);
        if ($tableId > 0) {
            $this->markTableBusy($conn, $tableId);
        }
        $this->logProcess($conn, 'cancel moova pos order');

        return [
            'order_id' => (int) $order['id'],
            'table_id' => $tableId,
            'table_name' => '',
            'total' => $receiptState['total'],
            'discount' => $receiptState['discount'],
            'net' => $receiptState['net'],
            'profit' => $receiptState['profit'],
            'state_hash' => null,
            'state_payload' => null,
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

        foreach ($items as $itemIndex => $item) {
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
                'source_line' => $item,
                'source_line_index' => (int) $itemIndex,
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
	              AND COALESCE(order_status, 'active') = 'active'
	              AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
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

    private function upsertOrderDetails(mysqli $conn, $tenant, $branch, $orderId, array $lines, $storeId)
    {
        $inserted = [];

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
            $existingDetail = $this->findMergeableMoovaDetailForUpdate(
                $conn,
                $tenant,
                $branch,
                $orderId,
                (int) $line['item_id'],
                $uVal,
                $unitPrice,
                $discount,
                (int) $storeId,
                $costPrice
            );

            if ($existingDetail) {
                $detailId = (int) $existingDetail['id'];
                $this->execute($conn, "
                    UPDATE fat_details
                    SET qty_out = qty_out + ?,
                        det_value = det_value + ?,
                        profit = profit + ?,
                        isdeleted = 0
                    WHERE id = ?
                      AND tenant = ?
                      AND branch = ?
                ", [$qtyOut, $detValue, $profit, $detailId, $tenant, $branch]);
            } else {
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
                $detailId = (int) $conn->insert_id;
            }

            $inserted[] = [
                'fat_detail_id' => $detailId,
                'item_id' => (int) $line['item_id'],
                'u_val' => $uVal,
                'qty_in' => 0.0,
                'qty_out' => $qtyOut,
                'price' => $unitPrice,
                'discount' => $discount,
                'det_value' => $detValue,
                'det_store' => (int) $storeId,
                'cost_price' => $costPrice,
                'profit' => $profit,
                'isdeleted' => 0,
            ];
        }

        return $inserted;
    }

    private function findMergeableMoovaDetailForUpdate(mysqli $conn, $tenant, $branch, $orderId, $itemId, $uVal, $unitPrice, $discount, $storeId, $costPrice)
    {
        return $this->queryOne($conn, "
            SELECT fd.*
            FROM fat_details fd
            WHERE fd.fatid = ?
              AND fd.tenant = ?
              AND fd.branch = ?
              AND fd.pro_tybe = ?
              AND fd.fat_tybe = ?
              AND fd.item_id = ?
              AND fd.isdeleted = 0
              AND ABS(COALESCE(fd.u_val, 1) - ?) < 0.0001
              AND ABS(COALESCE(fd.price, 0) - ?) < 0.0001
              AND ABS(COALESCE(fd.discount, 0) - ?) < 0.0001
              AND COALESCE(fd.det_store, 0) = ?
              AND ABS(COALESCE(fd.cost_price, 0) - ?) < 0.0001
              AND EXISTS (
                  SELECT 1
                  FROM moova_pos_order_lines l
                  WHERE l.fat_detail_id = fd.id
                    AND l.pos_order_id = fd.fatid
                    AND l.pos_tenant = fd.tenant
                    AND l.pos_branch = fd.branch
                    AND l.status = 'active'
              )
            ORDER BY fd.id ASC
            LIMIT 1
            FOR UPDATE
        ", [
            (int) $orderId,
            (int) $tenant,
            (int) $branch,
            self::TYPE_POS,
            self::TYPE_POS,
            (int) $itemId,
            (float) $uVal,
            (float) $unitPrice,
            (float) $discount,
            (int) $storeId,
            (float) $costPrice,
        ]);
    }

    private function refreshOrderProfit(mysqli $conn, $tenant, $branch, $orderId)
    {
        $row = $this->queryOne($conn, "
            SELECT SUM(profit) AS tprofit
            FROM fat_details
            WHERE fatid = ?
              AND tenant = ?
              AND branch = ?
              AND isdeleted = 0
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

    private function findMoovaOrderForUpdate(mysqli $conn, $tenant, $branch, $orderId)
    {
        return $this->queryOne($conn, "
            SELECT *
            FROM ot_head
            WHERE id = ?
              AND tenant = ?
              AND branch = ?
            LIMIT 1
            FOR UPDATE
        ", [(int) $orderId, (int) $tenant, (int) $branch]);
    }

    private function assertEditableMoovaTableOrder($order)
    {
        if (!$order) {
            throw new Exception('POS_ORDER_NOT_FOUND');
        }
        if ((int) ($order['isdeleted'] ?? 0) === 1) {
            throw new Exception('POS_ORDER_DELETED');
        }
        if ((int) ($order['pro_tybe'] ?? 0) !== self::TYPE_POS || (int) ($order['table_id'] ?? 0) < 1) {
            throw new Exception('POS_ORDER_NOT_TABLE');
        }
        $paymentStatus = strtolower(trim((string) ($order['payment_status'] ?? '')));
        $paidAmount = (float) ($order['paid_amount'] ?? 0);
        if (($paymentStatus !== '' && $paymentStatus !== 'unpaid') || $paidAmount > 0) {
            throw new Exception('POS_ORDER_PAID');
        }
        if ((float) ($order['fat_net'] ?? 0) <= 0) {
            throw new Exception('POS_ORDER_NOT_ACTIVE');
        }
    }

    private function findTableById(mysqli $conn, $tableId)
    {
        return $this->queryOne($conn, "
            SELECT *
            FROM tables
            WHERE id = ?
              AND isdeleted = 0
            LIMIT 1
        ", [(int) $tableId]);
    }

    private function buildReplacementLines(array $incomingItems)
    {
        $lines = [];
        foreach ($incomingItems as $item) {
            $id = (int) $item['id'];
            if ($id < 1) {
                continue;
            }
            if (!isset($lines[$id])) {
                $lines[$id] = [
                    'item_id' => $id,
                    'name' => $item['name'],
                    'barcode' => $item['barcode'],
                    'qty' => 0.0,
                    'price' => (float) $item['price'],
                    'discount' => 0.0,
                    'u_val' => 1.0,
                    'cost_price' => (float) $item['cost_price'],
                    'itmqty' => (float) $item['itmqty'],
                ];
            }
            $lines[$id]['qty'] += (float) $item['qty'];
        }

        return array_filter($lines, function ($line) {
            return (float) ($line['qty'] ?? 0) > 0;
        });
    }

    private function clearOrderAccounting(mysqli $conn, $tenant, $branch, $orderId)
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
    }

    private function insertMoovaLineMappings(mysqli $conn, $tenant, $branch, $moovaOrderId, $orderId, array $insertedLines)
    {
        $moovaOrderId = trim((string) $moovaOrderId);
        if ($moovaOrderId === '' || !$insertedLines) {
            return [];
        }

        $mappedLines = [];
        foreach ($insertedLines as $line) {
            $lineHash = $this->hashMappedLineState($line);
            $status = 'active';
            $this->execute($conn, "
                INSERT INTO moova_pos_order_lines (
                    moova_order_id, pos_order_id, fat_detail_id, item_id,
                    qty_out, price, discount, det_value, line_hash,
                    status, pos_tenant, pos_branch
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $moovaOrderId,
                (int) $orderId,
                (int) $line['fat_detail_id'],
                (int) $line['item_id'],
                (float) $line['qty_out'],
                (float) $line['price'],
                (float) ($line['discount'] ?? 0),
                (float) $line['det_value'],
                $lineHash,
                $status,
                (int) $tenant,
                (int) $branch,
            ]);
            $mappedLines[] = array_merge($line, [
                'mapping_id' => (int) $conn->insert_id,
                'moova_order_id' => $moovaOrderId,
                'pos_order_id' => (int) $orderId,
                'mapping_status' => $status,
            ]);
        }

        return $mappedLines;
    }

    private function recipeContextsFromMoovaMappedLines(
        mysqli $conn,
        $tenant,
        $branch,
        array $scope,
        array $payload,
        string $moovaOrderId,
        int $orderId,
        array $mappedLines
    ): array {
        $contexts = [];
        foreach ($mappedLines as $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $fatDetailId = (int) ($line['fat_detail_id'] ?? 0);
            $quantity = $this->recipeQuantityFromLegacyStockValues(
                $line['qty_in'] ?? '0',
                $line['qty_out'] ?? '0',
                $line['u_val'] ?? '1'
            );
            if ($itemId < 1 || RecipeDecimal::compare($quantity, '0') <= 0) {
                continue;
            }

            $externalContexts = $this->recipeContextsFromExternalLineMap(
                $conn,
                $tenant,
                $branch,
                $scope,
                $payload,
                $moovaOrderId,
                $orderId,
                $line,
                $quantity
            );
            if ($externalContexts) {
                foreach ($externalContexts as $externalContext) {
                    $contexts[] = $externalContext;
                }
                continue;
            }

            $contexts[] = [
                'conn' => $conn,
                'tenant' => (int) $tenant,
                'branch' => (int) $branch,
                'branch_uuid' => $this->nullableString($scope['branch_uuid'] ?? $payload['branchUuid'] ?? $payload['branch_uuid'] ?? null),
                'store_id' => (int) ($line['det_store'] ?? 0),
                'order_id' => $orderId,
                'fat_detail_id' => $fatDetailId > 0 ? $fatDetailId : null,
                'sellable_item_id' => $itemId,
                'item_id' => $itemId,
                'quantity' => $quantity,
                'qty' => $quantity,
                'channel' => $this->externalLineSourceChannel($payload),
                'order_type' => $this->externalLineOrderType($payload),
                'source_order_uuid' => $moovaOrderId,
                'source_line_uuid' => isset($line['mapping_id']) ? 'moova_pos_order_lines:' . (int) $line['mapping_id'] : null,
                'requested_at' => date('Y-m-d H:i:s'),
            ];
        }

        return $contexts;
    }

    private function recipeContextsFromExternalLineMap(
        mysqli $conn,
        $tenant,
        $branch,
        array $scope,
        array $payload,
        string $moovaOrderId,
        int $orderId,
        array $mappedLine,
        string $quantity
    ): array {
        if (!$this->tableExists($conn, 'external_order_line_map')) {
            return [];
        }

        $fatDetailId = (int) ($mappedLine['fat_detail_id'] ?? 0);
        $itemId = (int) ($mappedLine['item_id'] ?? 0);
        if ($fatDetailId < 1 || $itemId < 1) {
            return [];
        }

        $sourceChannel = $this->externalLineSourceChannel($payload);
        $rows = $this->queryAll($conn, "
            SELECT external_line_id, variant_id, modifiers_json
            FROM external_order_line_map
            WHERE pos_tenant = ?
              AND pos_branch = ?
              AND source_channel = ?
              AND external_order_id = ?
              AND fat_detail_id = ?
              AND item_id = ?
              AND line_status IN ('active', 'merged')
            ORDER BY id ASC
        ", [(int) $tenant, (int) $branch, $sourceChannel, $moovaOrderId, $fatDetailId, $itemId]);
        if (!$rows) {
            return [];
        }

        $contexts = [];
        foreach ($rows as $row) {
            $modifiers = json_decode((string) ($row['modifiers_json'] ?? '[]'), true);
            if (!is_array($modifiers)) {
                $modifiers = [];
            }

            $context = [
                'conn' => $conn,
                'tenant' => (int) $tenant,
                'branch' => (int) $branch,
                'branch_uuid' => $this->nullableString($scope['branch_uuid'] ?? $payload['branchUuid'] ?? $payload['branch_uuid'] ?? null),
                'store_id' => (int) ($mappedLine['det_store'] ?? 0),
                'order_id' => $orderId,
                'fat_detail_id' => $fatDetailId,
                'sellable_item_id' => $itemId,
                'item_id' => $itemId,
                'quantity' => $quantity,
                'qty' => $quantity,
                'channel' => $sourceChannel,
                'order_type' => $this->externalLineOrderType($payload),
                'source_order_uuid' => $moovaOrderId,
                'source_line_uuid' => substr($sourceChannel . ':' . (string) $row['external_line_id'], 0, 128),
                'requested_at' => date('Y-m-d H:i:s'),
            ];

            $variantId = (int) ($row['variant_id'] ?? 0);
            if ($variantId > 0) {
                $context['variant_id'] = $variantId;
            }
            if ($modifiers) {
                $context['modifiers'] = $modifiers;
            }
            $contexts[] = $context;
        }

        return $contexts;
    }

    private function recipeContextsFromMoovaIncomingItems(
        mysqli $conn,
        $tenant,
        $branch,
        array $scope,
        array $payload,
        string $moovaOrderId,
        int $orderId,
        array $incomingItems,
        array $insertedLines
    ): array {
        $contexts = [];
        $sourceChannel = $this->externalLineSourceChannel($payload);
        $identity = new ExternalOrderLineIdentityService();
        $insertedByItem = [];
        foreach ($insertedLines as $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            if ($itemId > 0) {
                $insertedByItem[$itemId][] = $line;
            }
        }

        foreach ($incomingItems as $fallbackIndex => $item) {
            $itemId = (int) ($item['id'] ?? 0);
            $qty = RecipeDecimal::normalize($item['qty'] ?? '0');
            if ($itemId < 1 || RecipeDecimal::compare($qty, '0') <= 0) {
                continue;
            }

            $sourceLine = $this->externalIdentityLineFromIncomingItem($item);
            $variantId = (int) ($sourceLine['variant_id'] ?? $sourceLine['variantId'] ?? $sourceLine['variant_item_id'] ?? $sourceLine['variantItemId'] ?? 0);
            $modifiers = $this->externalLineModifiers($sourceLine);
            $candidateLines = $insertedByItem[$itemId] ?? [];
            $localLine = count($candidateLines) === 1 ? $candidateLines[0] : [];
            $fatDetailId = (int) ($localLine['fat_detail_id'] ?? 0);
            $externalLineId = $identity->externalLineId(
                $sourceLine,
                (int) ($item['source_line_index'] ?? $fallbackIndex),
                $itemId,
                $variantId > 0 ? $variantId : null,
                $identity->modifiersHash($modifiers)
            );

            $context = [
                'conn' => $conn,
                'tenant' => (int) $tenant,
                'branch' => (int) $branch,
                'branch_uuid' => $this->nullableString($scope['branch_uuid'] ?? $payload['branchUuid'] ?? $payload['branch_uuid'] ?? null),
                'store_id' => (int) ($localLine['det_store'] ?? 0),
                'order_id' => $orderId,
                'fat_detail_id' => $fatDetailId > 0 ? $fatDetailId : null,
                'sellable_item_id' => $itemId,
                'item_id' => $itemId,
                'quantity' => $qty,
                'qty' => $qty,
                'channel' => $sourceChannel,
                'order_type' => $this->externalLineOrderType($payload),
                'source_order_uuid' => $moovaOrderId,
                'source_line_uuid' => substr($sourceChannel . ':' . $externalLineId, 0, 128),
                'requested_at' => date('Y-m-d H:i:s'),
            ];
            if ($variantId > 0) {
                $context['variant_id'] = $variantId;
            }
            if ($modifiers) {
                $context['modifiers'] = $modifiers;
            }
            $contexts[] = $context;
        }

        return $contexts;
    }

    private function externalLineModifiers(array $line): array
    {
        foreach (['modifiers', 'modifier_options', 'selected_modifiers', 'options'] as $modifierKey) {
            if (isset($line[$modifierKey]) && is_array($line[$modifierKey])) {
                return $line[$modifierKey];
            }
        }

        return [];
    }

    private function recordRecipeOrderLinesAdded(mysqli $conn, array $lines): void
    {
        foreach ($lines as $line) {
            if (is_array($line)) {
                $line['conn'] = $conn;
                $this->recipeLifecycleService->onOrderLineAdded($line);
            }
        }
    }

    private function recordRecipeOrderLinesCancelled(mysqli $conn, array $lines, string $reason): void
    {
        foreach ($lines as $line) {
            if (is_array($line)) {
                $line['conn'] = $conn;
                $this->recipeLifecycleService->onOrderLineCancelled($line, $reason);
            }
        }
    }

    private function registerExternalLineIdentities(
        mysqli $conn,
        $tenant,
        $branch,
        array $scope,
        array $payload,
        $moovaOrderId,
        $orderId,
        array $incomingItems,
        array $insertedLines,
        $storeId
    ) {
        $moovaOrderId = trim((string) $moovaOrderId);
        if ($moovaOrderId === '' || !$incomingItems || !$this->tableExists($conn, 'external_order_line_map')) {
            return;
        }

        $sourceChannel = $this->externalLineSourceChannel($payload);
        $recipeScope = new RecipeScope(
            (int) $tenant,
            (int) $branch,
            $this->nullableString($scope['branch_uuid'] ?? $payload['branchUuid'] ?? $payload['branch_uuid'] ?? null),
            (int) $storeId,
            $sourceChannel,
            $this->externalLineOrderType($payload),
            $sourceChannel
        );
        $identity = new ExternalOrderLineIdentityService();
        $incomingByItem = [];
        foreach ($incomingItems as $item) {
            $itemId = (int) ($item['id'] ?? 0);
            if ($itemId > 0) {
                $incomingByItem[$itemId][] = $item;
            }
        }

        $insertedByItem = [];
        foreach ($insertedLines as $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            if ($itemId > 0) {
                $insertedByItem[$itemId][] = $line;
            }
        }

        foreach ($incomingItems as $fallbackIndex => $item) {
            $itemId = (int) ($item['id'] ?? 0);
            if ($itemId < 1) {
                continue;
            }

            $candidateLines = $insertedByItem[$itemId] ?? [];
            $isOneToOne = count($incomingByItem[$itemId] ?? []) === 1 && count($candidateLines) === 1;
            $localLine = [
                'order_id' => (int) $orderId,
                'line_status' => $isOneToOne ? 'active' : 'merged',
            ];
            if (count($candidateLines) === 1) {
                $localLine['fat_detail_id'] = (int) ($candidateLines[0]['fat_detail_id'] ?? 0);
            }

            $identity->registerLine(
                $conn,
                $recipeScope,
                $sourceChannel,
                $moovaOrderId,
                $this->externalIdentityLineFromIncomingItem($item),
                (int) ($item['source_line_index'] ?? $fallbackIndex),
                $localLine
            );
        }
    }

    private function externalIdentityLineFromIncomingItem(array $item): array
    {
        $sourceLine = is_array($item['source_line'] ?? null) ? $item['source_line'] : [];
        $line = $sourceLine;
        $line['item_id'] = (int) ($item['id'] ?? 0);

        if (!isset($line['itemId']) && isset($sourceLine['item_id'])) {
            $line['itemId'] = $sourceLine['item_id'];
        }

        return $line;
    }

    private function externalLineSourceChannel(array $payload): string
    {
        foreach (['sourceChannel', 'source_channel', 'externalProvider', 'external_provider', 'provider', 'sourceSystem', 'source_system'] as $key) {
            if (!isset($payload[$key])) {
                continue;
            }

            $value = strtolower(trim((string) $payload[$key]));
            $value = str_replace(['-', ' '], '_', $value);
            if (in_array($value, ['moova', 'cofe', 'api', 'sync'], true)) {
                return $value;
            }
        }

        return 'moova';
    }

    private function externalLineOrderType(array $payload): string
    {
        foreach (['fulfillmentType', 'fulfillment_type', 'orderType', 'order_type', 'type', 'orderChannel', 'order_channel'] as $key) {
            if (!isset($payload[$key])) {
                continue;
            }

            $value = strtolower(trim((string) $payload[$key]));
            $value = str_replace(['-', ' '], '_', $value);
            if (in_array($value, ['dine_in', 'takeaway', 'delivery'], true)) {
                return $value;
            }
            if (strpos($value, 'delivery') !== false) {
                return 'delivery';
            }
            if (strpos($value, 'take') !== false || strpos($value, 'pickup') !== false) {
                return 'takeaway';
            }
            if (strpos($value, 'table') !== false || strpos($value, 'dine') !== false) {
                return 'dine_in';
            }
        }

        return 'delivery';
    }

    private function deactivateMoovaMappedLines(mysqli $conn, $tenant, $branch, $orderId, $moovaOrderId, $status)
    {
        $moovaOrderId = trim((string) $moovaOrderId);
        $status = trim((string) $status);
        if ($moovaOrderId === '' || $status === '') {
            return;
        }

        $ownedRows = $this->queryAll($conn, "
            SELECT
                l.id AS mapping_id,
                l.fat_detail_id,
                l.qty_out AS mapped_qty_out,
                l.det_value AS mapped_det_value,
                fd.qty_out AS detail_qty_out,
                fd.det_value AS detail_det_value,
                fd.price,
                fd.cost_price,
                fd.profit
            FROM moova_pos_order_lines l
            INNER JOIN fat_details fd
                    ON fd.id = l.fat_detail_id
                   AND fd.tenant = l.pos_tenant
                   AND fd.branch = l.pos_branch
            WHERE l.pos_tenant = ?
              AND l.pos_branch = ?
              AND l.pos_order_id = ?
              AND l.moova_order_id = ?
              AND l.status = 'active'
            ORDER BY l.id ASC
            FOR UPDATE
        ", [(int) $tenant, (int) $branch, (int) $orderId, $moovaOrderId]);

        foreach ($ownedRows as $row) {
            $detailId = (int) $row['fat_detail_id'];
            $ownedQtyOut = (float) $row['mapped_qty_out'];
            $ownedDetValue = (float) $row['mapped_det_value'];
            $ownedProfit = $ownedQtyOut * ((float) $row['price'] - (float) $row['cost_price']);
            $remainingQtyOut = max(0, (float) $row['detail_qty_out'] - $ownedQtyOut);
            $remainingDetValue = max(0, (float) $row['detail_det_value'] - $ownedDetValue);
            $remainingProfit = max(0, (float) $row['profit'] - $ownedProfit);

            if ($remainingQtyOut <= 0.0001 || $remainingDetValue <= 0.0001) {
                $this->execute($conn, "
                    UPDATE fat_details
                    SET qty_out = 0,
                        det_value = 0,
                        profit = 0,
                        isdeleted = 1
                    WHERE id = ?
                      AND tenant = ?
                      AND branch = ?
                ", [$detailId, (int) $tenant, (int) $branch]);
            } else {
                $this->execute($conn, "
                    UPDATE fat_details
                    SET qty_out = ?,
                        det_value = ?,
                        profit = ?,
                        isdeleted = 0
                    WHERE id = ?
                      AND tenant = ?
                      AND branch = ?
                ", [$remainingQtyOut, $remainingDetValue, $remainingProfit, $detailId, (int) $tenant, (int) $branch]);
            }
        }

        $this->execute($conn, "
            UPDATE moova_pos_order_lines
            SET status = ?
            WHERE pos_tenant = ?
              AND pos_branch = ?
              AND pos_order_id = ?
              AND moova_order_id = ?
              AND status = 'active'
        ", [$status, (int) $tenant, (int) $branch, (int) $orderId, $moovaOrderId]);
    }

    private function refreshReceiptTotalsAndAccounting(mysqli $conn, $tenant, $branch, $orderId, array $defaults, $proDate, $userId)
    {
        $order = $this->queryOne($conn, "
            SELECT id, pro_id
            FROM ot_head
            WHERE id = ?
              AND tenant = ?
              AND branch = ?
            LIMIT 1
        ", [(int) $orderId, (int) $tenant, (int) $branch]);

        if (!$order) {
            throw new Exception('POS_ORDER_NOT_FOUND');
        }

        $activeLines = $this->queryAll($conn, "
            SELECT id, u_val, qty_in, qty_out, price, discount, det_value, profit
            FROM fat_details
            WHERE fatid = ?
              AND tenant = ?
              AND branch = ?
              AND isdeleted = 0
            ORDER BY id ASC
        ", [(int) $orderId, (int) $tenant, (int) $branch]);

        $this->clearOrderAccounting($conn, $tenant, $branch, (int) $orderId);

        if (!$activeLines) {
            $this->execute($conn, "
                UPDATE ot_head
                SET pro_value = 0,
                    fat_cost = 0,
                    profit = 0,
                    fat_total = 0,
                    fat_disc = 0,
                    fat_disc_per = 0,
                    fat_plus = 0,
                    fat_plus_per = 0,
                    fat_tax = 0,
                    fat_tax_per = 0,
                    fat_net = 0,
                    paid_amount = 0,
                    remaining_amount = 0,
                    payment_status = 'unpaid',
                    invoice_status = 'cancelled',
                    order_status = 'cancelled',
                    isdeleted = 1,
                    user = ?
                WHERE id = ?
                  AND tenant = ?
                  AND branch = ?
            ", [(int) $userId, (int) $orderId, (int) $tenant, (int) $branch]);

            return [
                'total' => 0.0,
                'discount' => 0.0,
                'disc_percent' => 0.0,
                'net' => 0.0,
                'profit' => 0.0,
                'journal_head_id' => null,
                'active_line_count' => 0,
            ];
        }

        $totals = $this->calculateTotalsFromDetailRows($activeLines);
        $this->execute($conn, "
            UPDATE ot_head
            SET pro_value = ?,
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
                isdeleted = 0,
                user = ?
            WHERE id = ?
              AND tenant = ?
              AND branch = ?
        ", [
            $totals['total'],
            $totals['total'],
            $totals['discount'],
            $totals['disc_percent'],
            $totals['net'],
            $totals['net'],
            (int) $userId,
            (int) $orderId,
            (int) $tenant,
            (int) $branch,
        ]);

        $journalHeadId = $this->insertMainJournal(
            $conn,
            $tenant,
            $branch,
            (int) $orderId,
            (int) ($order['pro_id'] ?: $orderId),
            $defaults,
            $totals,
            $proDate,
            $userId
        );
        $profit = $this->refreshOrderProfit($conn, $tenant, $branch, (int) $orderId);

        return [
            'total' => $totals['total'],
            'discount' => $totals['discount'],
            'disc_percent' => $totals['disc_percent'],
            'net' => $totals['net'],
            'profit' => $profit,
            'journal_head_id' => $journalHeadId,
            'active_line_count' => count($activeLines),
        ];
    }

    private function calculateTotalsFromDetailRows(array $rows)
    {
        $total = 0.0;
        $net = 0.0;

        foreach ($rows as $row) {
            $uVal = (float) ($row['u_val'] ?? 1);
            if ($uVal <= 0) {
                $uVal = 1;
            }
            $qty = abs((float) ($row['qty_out'] ?? 0) - (float) ($row['qty_in'] ?? 0)) / $uVal;
            $lineGross = $qty * ((float) ($row['price'] ?? 0) * $uVal);
            $lineNet = (float) ($row['det_value'] ?? 0);
            if ($lineGross <= 0 && $lineNet > 0) {
                $lineGross = $lineNet;
            }
            $total += $lineGross;
            $net += $lineNet;
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

    private function normalizeMappedLineState(array $row)
    {
        $ownedQtyOut = array_key_exists('mapped_qty_out', $row) ? $row['mapped_qty_out'] : ($row['qty_out'] ?? 0);
        $ownedPrice = array_key_exists('mapped_price', $row) ? $row['mapped_price'] : ($row['price'] ?? 0);
        $ownedDiscount = array_key_exists('mapped_discount', $row) ? $row['mapped_discount'] : ($row['discount'] ?? 0);
        $ownedDetValue = array_key_exists('mapped_det_value', $row) ? $row['mapped_det_value'] : ($row['det_value'] ?? 0);

        return [
            'mapping_id' => (int) ($row['mapping_id'] ?? 0),
            'fat_detail_id' => (int) ($row['fat_detail_id'] ?? 0),
            'item_id' => (int) ($row['mapped_item_id'] ?? $row['item_id'] ?? 0),
            'u_val' => $this->normalizeStateNumber($row['u_val'] ?? 1),
            'qty_in' => $this->normalizeStateNumber($row['qty_in'] ?? 0),
            'qty_out' => $this->normalizeStateNumber($ownedQtyOut),
            'price' => $this->normalizeStateNumber($ownedPrice),
            'discount' => $this->normalizeStateNumber($ownedDiscount),
            'det_value' => $this->normalizeStateNumber($ownedDetValue),
            'det_store' => (int) ($row['det_store'] ?? 0),
            'cost_price' => $this->normalizeStateNumber($row['cost_price'] ?? 0),
            'profit' => $this->normalizeStateNumber(((float) $ownedQtyOut) * ((float) $ownedPrice - (float) ($row['cost_price'] ?? 0))),
            'isdeleted' => (int) ($row['isdeleted'] ?? 0),
            'detail_consistent' => !empty($row['detail_consistent']) ? 1 : 0,
        ];
    }

    private function recipeQuantityFromLegacyStockValues($qtyIn, $qtyOut, $uVal): string
    {
        $qtyIn = RecipeDecimal::normalize($qtyIn);
        $qtyOut = RecipeDecimal::normalize($qtyOut);
        $unitValue = RecipeDecimal::normalize($uVal);
        if (RecipeDecimal::compare($unitValue, '0') <= 0) {
            $unitValue = '1.000000';
        }

        $difference = RecipeDecimal::compare($qtyOut, $qtyIn) >= 0
            ? RecipeDecimal::subtract($qtyOut, $qtyIn)
            : RecipeDecimal::subtract($qtyIn, $qtyOut);
        if (RecipeDecimal::compare($difference, '0') <= 0) {
            return '0.000000';
        }

        return RecipeDecimal::divide($difference, $unitValue);
    }

    private function isMappedDetailConsistent(mysqli $conn, $tenant, $branch, $detailId, array $detailRow)
    {
        $sum = $this->queryOne($conn, "
            SELECT
                COALESCE(SUM(qty_out), 0) AS mapped_qty_out,
                COALESCE(SUM(det_value), 0) AS mapped_det_value
            FROM moova_pos_order_lines
            WHERE fat_detail_id = ?
              AND pos_tenant = ?
              AND pos_branch = ?
              AND status = 'active'
        ", [(int) $detailId, (int) $tenant, (int) $branch]);

        $mappedQtyOut = (float) ($sum['mapped_qty_out'] ?? 0);
        $mappedDetValue = (float) ($sum['mapped_det_value'] ?? 0);
        $detailQtyOut = (float) ($detailRow['qty_out'] ?? 0);
        $detailDetValue = (float) ($detailRow['det_value'] ?? 0);
        $itemMatches = (int) ($detailRow['item_id'] ?? 0) === (int) ($detailRow['mapped_item_id'] ?? 0);
        $priceMatches = abs((float) ($detailRow['price'] ?? 0) - (float) ($detailRow['mapped_price'] ?? 0)) < 0.0001;
        $discountMatches = abs((float) ($detailRow['discount'] ?? 0) - (float) ($detailRow['mapped_discount'] ?? 0)) < 0.0001;

        return (int) ($detailRow['isdeleted'] ?? 0) === 0
            && $itemMatches
            && $priceMatches
            && $discountMatches
            && abs($detailQtyOut - $mappedQtyOut) < 0.0001
            && abs($detailDetValue - $mappedDetValue) < 0.0001;
    }

    private function hashMappedLineState(array $line)
    {
        return hash(
            'sha256',
            json_encode($this->normalizeMappedLineState($line), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function normalizeStateNumber($value)
    {
        return number_format((float) $value, 4, '.', '');
    }

    private function markTableBusy(mysqli $conn, $tableId)
    {
        $this->execute($conn, "UPDATE tables SET table_case = 1 WHERE id = ?", [$tableId]);
    }

    private function markTableAvailable(mysqli $conn, $tableId)
    {
        $this->execute($conn, "UPDATE tables SET table_case = 0 WHERE id = ?", [(int) $tableId]);
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

        $counter = new DocumentCounterService();
        $counter->ensureCounterRow(
            $conn,
            (int) $tenant,
            (int) $branch,
            'pro_id',
            'pro_tybe:' . (int) $invoiceType,
            $row && $row['max_id'] ? (int) $row['max_id'] : 0
        );

        return $counter->nextProId($conn, (int) $invoiceType, (int) $tenant, (int) $branch);
    }

    private function getNextJournalId(mysqli $conn, $tenant, $branch)
    {
        $row = $this->queryOne($conn, "
            SELECT MAX(journal_id) AS max_id
            FROM journal_heads
            WHERE tenant = ?
              AND branch = ?
        ", [$tenant, $branch]);

        $counter = new DocumentCounterService();
        $counter->ensureCounterRow(
            $conn,
            (int) $tenant,
            (int) $branch,
            'journal_id',
            'journal:default',
            $row && $row['max_id'] ? (int) $row['max_id'] : 0
        );

        return $counter->nextJournalId($conn, (int) $tenant, (int) $branch);
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");

        return $result && $result->num_rows > 0;
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
