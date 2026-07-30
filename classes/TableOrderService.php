<?php

require_once __DIR__ . '/Sync/DocumentCounterService.php';
require_once __DIR__ . '/Financial/Money.php';

class TableOrderService
{
    const POS_TYPE = 9;

    public function getScopeForUser(mysqli $conn, $userId)
    {
        $scope = ['tenant' => 0, 'branch' => 0];
        $userId = (int) $userId;
        if ($userId < 1 || !$this->columnExists($conn, 'users', 'tenant') || !$this->columnExists($conn, 'users', 'branch')) {
            return $scope;
        }

        $row = $this->queryOne($conn, "SELECT tenant, branch FROM users WHERE id = ? LIMIT 1", [$userId]);
        if ($row) {
            $scope['tenant'] = (int) ($row['tenant'] ?? 0);
            $scope['branch'] = (int) ($row['branch'] ?? 0);
        }

        return $scope;
    }

    public function nextPosProId(mysqli $conn, $proTybe = self::POS_TYPE, $tenant = 0, $branch = 0)
    {
        $proTybe = (int) $proTybe;
        $tenant = (int) $tenant;
        $branch = (int) $branch;
        $row = $this->queryOne($conn, "
            SELECT COALESCE(MAX(CAST(pro_id AS UNSIGNED)), 0) AS max_id
            FROM ot_head
            WHERE pro_tybe = ?
              AND COALESCE(tenant, 0) = ?
              AND COALESCE(branch, 0) = ?
        ", [$proTybe, $tenant, $branch]);

        $counter = new DocumentCounterService();
        $counter->ensureCounterRow(
            $conn,
            $tenant,
            $branch,
            'pro_id',
            'pro_tybe:' . $proTybe,
            (int) ($row['max_id'] ?? 0)
        );

        return $counter->nextProId($conn, $proTybe, $tenant, $branch);
    }

    public function nextJournalId(mysqli $conn, $tenant = 0, $branch = 0)
    {
        $tenant = (int) $tenant;
        $branch = (int) $branch;
        $row = $this->queryOne($conn, "
            SELECT COALESCE(MAX(journal_id), 0) AS max_id
            FROM journal_heads
            WHERE COALESCE(tenant, 0) = ?
              AND COALESCE(branch, 0) = ?
        ", [$tenant, $branch]);

        $counter = new DocumentCounterService();
        $counter->ensureCounterRow(
            $conn,
            $tenant,
            $branch,
            'journal_id',
            'journal:default',
            (int) ($row['max_id'] ?? 0)
        );

        return $counter->nextJournalId($conn, $tenant, $branch);
    }

    public function findTableById(mysqli $conn, $tableId)
    {
        return $this->queryOne($conn, "
            SELECT id, tname, table_case
            FROM tables
            WHERE id = ?
              AND isdeleted = 0
            LIMIT 1
        ", [(int) $tableId]);
    }

    public function requireTable(mysqli $conn, $tableId)
    {
        $table = $this->findTableById($conn, $tableId);
        if (!$table) {
            throw new Exception('الطاولة غير موجودة');
        }

        return $table;
    }

    public function resolveDefaultCustomerId(mysqli $conn, $preferredId = 0)
    {
        $preferredId = (int) $preferredId;
        if ($preferredId > 0) {
            $preferred = $this->queryOne($conn, "
                SELECT id
                FROM acc_head
                WHERE id = ?
                  AND isdeleted = 0
                LIMIT 1
            ", [$preferredId]);
            if ($preferred) {
                return $preferredId;
            }
        }

        $fallback = $this->queryOne($conn, "
            SELECT id
            FROM acc_head
            WHERE code LIKE '122%'
              AND code <> '122'
              AND isdeleted = 0
            ORDER BY id ASC
            LIMIT 1
        ");
        if (!$fallback) {
            $fallback = $this->queryOne($conn, "
                SELECT id
                FROM acc_head
                WHERE code LIKE '122%'
                  AND isdeleted = 0
                ORDER BY id ASC
                LIMIT 1
            ");
        }
        if (!$fallback) {
            throw new Exception('لا يوجد حساب عميل صالح لحفظ طلب الطاولة');
        }

        return (int) $fallback['id'];
    }

    public function findActiveOrderByTableId(mysqli $conn, $tableId, $lock = false)
    {
        $lockSql = $lock ? ' FOR UPDATE' : '';

        return $this->queryOne($conn, "
            SELECT *
            FROM ot_head
            WHERE table_id = ?
              AND pro_tybe = ?
              AND isdeleted = 0
              AND COALESCE(order_status, 'active') = 'active'
              AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
            ORDER BY id DESC
            LIMIT 1" . $lockSql, [(int) $tableId, self::POS_TYPE]);
    }

    public function findActiveOrderByTableAndOrderId(mysqli $conn, $tableId, $orderId, $lock = false)
    {
        $lockSql = $lock ? ' FOR UPDATE' : '';

        return $this->queryOne($conn, "
            SELECT *
            FROM ot_head
            WHERE id = ?
              AND table_id = ?
              AND pro_tybe = ?
              AND isdeleted = 0
              AND COALESCE(order_status, 'active') = 'active'
              AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
            LIMIT 1" . $lockSql, [(int) $orderId, (int) $tableId, self::POS_TYPE]);
    }

    public function loadOrderWithItems(mysqli $conn, $orderId, $postedTableId = null)
    {
        $order = $this->queryOne($conn, "
            SELECT oh.*, t.tname AS table_name
            FROM ot_head oh
            LEFT JOIN tables t ON t.id = oh.table_id
            WHERE oh.id = ?
              AND oh.isdeleted = 0
            LIMIT 1
        ", [(int) $orderId]);

        if (!$order) {
            return null;
        }

        if ($postedTableId !== null && (int) $postedTableId > 0 && (int) ($order['table_id'] ?? 0) > 0 && (int) $postedTableId !== (int) $order['table_id']) {
            throw new Exception('الطلب لا يخص الطاولة المحددة');
        }

        $lineNoteSelect = "NULL AS kitchen_note";
        if ($this->tableExists($conn, 'order_line_notes')) {
            $lineNoteSelect = "
                (
                    SELECT GROUP_CONCAT(oln.note_text ORDER BY oln.id SEPARATOR '\n')
                    FROM order_line_notes oln
                    WHERE oln.order_id = fd.fatid
                      AND oln.detail_id = fd.id
                      AND oln.note_type = 'kitchen'
                ) AS kitchen_note
            ";
        }

        $items = $this->queryAll($conn, "
            SELECT
                fd.*,
                m.iname AS item_name,
                m.barcode,
                m.info AS item_desc,
                {$lineNoteSelect}
            FROM fat_details fd
            LEFT JOIN myitems m ON m.id = fd.item_id
            WHERE fd.fatid = ?
              AND fd.isdeleted = 0
            ORDER BY fd.id ASC
        ", [(int) $orderId]);

        return [
            'order' => $order,
            'items' => $items,
        ];
    }

    public function buildInfo($orderType, $tableName = '', $existingInfo = '')
    {
        $parts = [];
        $existingInfo = trim((string) $existingInfo);

        if ($existingInfo !== '') {
            $parts[] = $existingInfo;
        }

        if ($orderType === 'table') {
            $parts[] = 'نوع الطلب: طاولة';
            if (trim((string) $tableName) !== '') {
                $parts[] = 'طاولة: ' . trim((string) $tableName);
            }
        } elseif ($orderType === 'delivery') {
            $parts[] = 'نوع الطلب: دليفري';
        } else {
            $parts[] = 'نوع الطلب: تيك أواي';
        }

        $deduped = [];
        foreach ($parts as $part) {
            if ($part !== '' && !in_array($part, $deduped, true)) {
                $deduped[] = $part;
            }
        }

        return posmain_truncate_invoice_info(implode(' - ', $deduped));
    }

    public function recalculateOrderTotals(mysqli $conn, $orderId)
    {
        $row = $this->queryOne($conn, "
            SELECT
                COALESCE(SUM(det_value), 0) AS total,
                COALESCE(SUM(profit), 0) AS profit
            FROM fat_details
            WHERE fatid = ?
              AND isdeleted = 0
        ", [(int) $orderId]);

        $total = Money::from((string) ($row['total'] ?? '0'))->toString();
        $profit = Money::from((string) ($row['profit'] ?? '0'), true)->toString();
        $head = $this->queryOne($conn, "SELECT fat_disc FROM ot_head WHERE id = ? LIMIT 1", [(int) $orderId]);
        $discount = Money::from((string) ($head['fat_disc'] ?? '0'))->toString();
        $net = Money::from($total)->subtract(Money::from($discount));
        if ($net->isNegative()) {
            $net = Money::zero();
        }

        $this->execute($conn, "
            UPDATE ot_head
            SET pro_value = ?,
                fat_total = ?,
                fat_net = ?,
                profit = ?
            WHERE id = ?
        ", [$total, $total, $net->toString(), $profit, (int) $orderId]);

        return [
            'total' => $total,
            'net' => $net->toString(),
            'profit' => $profit,
        ];
    }

    public function markTableOccupied(mysqli $conn, $tableId)
    {
        $this->execute($conn, "UPDATE tables SET table_case = 1 WHERE id = ?", [(int) $tableId]);
    }

    public function setTableFreeIfNoActiveOrder(mysqli $conn, $tableId)
    {
        $row = $this->queryOne($conn, "
            SELECT COUNT(*) AS active_count
            FROM ot_head
            WHERE table_id = ?
              AND pro_tybe = ?
              AND isdeleted = 0
              AND COALESCE(order_status, 'active') = 'active'
              AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
        ", [(int) $tableId, self::POS_TYPE]);

        if ((int) ($row['active_count'] ?? 0) === 0) {
            $this->execute($conn, "UPDATE tables SET table_case = 0 WHERE id = ?", [(int) $tableId]);
            return true;
        }

        return false;
    }

    public function cancelTableOrder(mysqli $conn, $tableId, $orderId, $reason, $userId)
    {
        $order = $this->findActiveOrderByTableAndOrderId($conn, $tableId, $orderId, true);
        if (!$order) {
            throw new Exception('لا يوجد طلب نشط لهذه الطاولة');
        }
        if (
            strtolower(trim((string) ($order['payment_status'] ?? 'unpaid'))) !== 'unpaid'
            || Money::from((string) ($order['paid_amount'] ?? '0'))->compare(Money::zero()) > 0
        ) {
            throw new Exception('ORDER_HAS_PAYMENT_USE_REFUND');
        }

        $this->execute($conn, "
            UPDATE ot_head
            SET order_status = 'cancelled',
                invoice_status = 'cancelled',
                payment_status = 'voided',
                isdeleted = 1,
                cancelled_at = NOW(),
                cancelled_by = ?,
                cancellation_reason = ?
            WHERE id = ?
              AND table_id = ?
        ", [(int) $userId, trim((string) $reason), (int) $orderId, (int) $tableId]);

        $this->execute($conn, "
            UPDATE fat_details
            SET isdeleted = 1
            WHERE fatid = ?
        ", [(int) $orderId]);

        $this->setTableFreeIfNoActiveOrder($conn, $tableId);

        return $order;
    }

    public function payTableOrder(
        mysqli $conn,
        $tableId,
        $orderId,
        $amountPaid,
        $paymentMethod,
        $notes = '',
        $userId = 1,
        $discount = null,
        $netOverride = null,
        array $tenderFacts = []
    )
    {
        $order = $this->findActiveOrderByTableAndOrderId($conn, $tableId, $orderId, true);
        if (!$order) {
            throw new Exception('لا يوجد طلب نشط لهذه الطاولة');
        }

        $amountPaid = Money::from($amountPaid);
        if (!$amountPaid->isPositive()) {
            throw new Exception('يجب إدخال مبلغ الدفع');
        }

        if ($discount !== null) {
            $discount = Money::from($discount);
            $net = $netOverride !== null
                ? Money::from($netOverride)
                : Money::from((string) ($order['fat_total'] ?? '0'))->subtract($discount);
            if ($net->isNegative()) {
                $net = Money::zero();
            }
            $this->execute($conn, "
                UPDATE ot_head
                SET fat_disc = ?,
                    fat_net = ?,
                    remaining_amount = GREATEST(0, ? - COALESCE(paid_amount, 0))
                WHERE id = ?
                  AND table_id = ?
            ", [$discount->toString(), $net->toString(), $net->toString(), (int) $orderId, (int) $tableId]);
            $order['fat_disc'] = $discount->toString();
            $order['fat_net'] = $net->toString();
        }

        $netAmount = $netOverride !== null ? Money::from($netOverride) : Money::from((string) ($order['fat_net'] ?? '0'));
        if (!$netAmount->isPositive()) {
            $totals = $this->recalculateOrderTotals($conn, $orderId);
            $netAmount = Money::from($totals['net']);
        }

        $existingPaid = Money::from((string) ($order['paid_amount'] ?? '0'));
        $outstanding = $netAmount->subtract($existingPaid);
        if (!$outstanding->isPositive()) {
            throw new RuntimeException('ORDER_ALREADY_PAID');
        }
        $tenderType = strtolower(trim((string) ($tenderFacts['type'] ?? '')));
        $isCashTender = $tenderType === 'cash';
        if (!$isCashTender && $amountPaid->compare($outstanding) > 0) {
            throw new InvalidArgumentException('NON_CASH_TENDER_EXCEEDS_REMAINING');
        }

        $candidatePaid = $existingPaid->add($amountPaid);
        $newPaid = $candidatePaid->compare($netAmount) > 0 ? $netAmount : $candidatePaid;
        $appliedAmount = $newPaid->subtract($existingPaid);
        $changeDue = $isCashTender && $amountPaid->compare($appliedAmount) > 0
            ? $amountPaid->subtract($appliedAmount)
            : Money::zero();
        $remaining = $netAmount->subtract($newPaid);
        $isPaid = !$remaining->isPositive();
        $paymentStatus = $isPaid ? 'paid' : 'partial';
        $orderStatus = $isPaid ? 'completed' : 'active';
        $invoiceStatus = $isPaid ? 'completed' : 'draft';

        $this->execute($conn, "
            UPDATE ot_head
            SET paid_amount = ?,
                remaining_amount = ?,
                payment_status = ?,
                order_status = ?,
                invoice_status = ?,
                payment_method = ?,
                payment_notes = ?,
                payment_date = CASE WHEN ? = 'paid' THEN NOW() ELSE payment_date END,
                completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE completed_at END
            WHERE id = ?
              AND table_id = ?
              AND pro_tybe = ?
        ", [
            $newPaid->toString(),
            $remaining->toString(),
            $paymentStatus,
            $orderStatus,
            $invoiceStatus,
            trim((string) $paymentMethod),
            trim((string) $notes),
            $paymentStatus,
            $orderStatus,
            (int) $orderId,
            (int) $tableId,
            self::POS_TYPE,
        ]);

        $paymentId = $this->insertPaymentRecord(
            $conn,
            $orderId,
            $appliedAmount->toString(),
            $paymentMethod,
            $userId,
            $notes,
            [
                'tendered_amount' => $amountPaid->toString(),
                'change_due' => $changeDue->toString(),
                'require_cash_fact_columns' => !empty($tenderFacts['require_cash_fact_columns']),
            ]
        );

        if ($isPaid) {
            $this->setTableFreeIfNoActiveOrder($conn, $tableId);
        } else {
            $this->markTableOccupied($conn, $tableId);
        }

        return [
            'order_id' => (int) $orderId,
            'table_id' => (int) $tableId,
            'paid_amount' => $newPaid->toString(),
            'tendered_amount' => $amountPaid->toString(),
            'applied_amount' => $appliedAmount->toString(),
            'change_due' => $changeDue->toString(),
            'payment_id' => $paymentId,
            'remaining_amount' => $remaining->toString(),
            'payment_status' => $paymentStatus,
            'order_status' => $orderStatus,
            'invoice_status' => $invoiceStatus,
            'fully_paid' => $isPaid,
        ];
    }

    private function insertPaymentRecord(
        mysqli $conn,
        $orderId,
        $amount,
        $paymentMethod,
        $userId,
        $notes,
        array $tenderFacts = []
    ): ?int
    {
        if (!$this->tableExists($conn, 'order_payments')) {
            if (!empty($tenderFacts['require_cash_fact_columns'])) {
                throw new RuntimeException('PAYMENT_RECORD_SCHEMA_REQUIRED');
            }
            return null;
        }

        $appliedAmount = Money::from($amount)->toString();
        $tenderedAmount = Money::from((string) ($tenderFacts['tendered_amount'] ?? $appliedAmount))->toString();
        $changeDue = Money::from((string) ($tenderFacts['change_due'] ?? '0'))->toString();
        $hasCashFacts = $this->columnExists($conn, 'order_payments', 'tendered_amount')
            && $this->columnExists($conn, 'order_payments', 'applied_amount')
            && $this->columnExists($conn, 'order_payments', 'change_due');
        if (!empty($tenderFacts['require_cash_fact_columns']) && !$hasCashFacts) {
            throw new RuntimeException('PAYMENT_CASH_FACT_SCHEMA_REQUIRED');
        }

        if ($hasCashFacts) {
            $this->execute($conn, "
                INSERT INTO order_payments (
                    order_id, amount, tendered_amount, applied_amount, change_due,
                    payment_method, reference_no, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ", [
                (int) $orderId,
                $appliedAmount,
                $tenderedAmount,
                $appliedAmount,
                $changeDue,
                trim((string) $paymentMethod),
                trim((string) $notes),
                (int) $userId,
            ]);
        } else {
            $this->execute($conn, "
                INSERT INTO order_payments (
                    order_id, amount, payment_method, reference_no, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ", [
                (int) $orderId,
                $appliedAmount,
                trim((string) $paymentMethod),
                trim((string) $notes),
                (int) $userId,
            ]);
        }
        $paymentId = (int) $conn->insert_id;
        $this->assignUuidIfPresent($conn, 'order_payments', $paymentId);

        return $paymentId > 0 ? $paymentId : null;
    }

    public function queryOne(mysqli $conn, $sql, array $params = [])
    {
        $rows = $this->queryAll($conn, $sql, $params);
        return $rows ? $rows[0] : null;
    }

    public function queryAll(mysqli $conn, $sql, array $params = [])
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('فشل في تحضير الاستعلام: ' . $conn->error);
        }

        if ($params) {
            $this->bindParams($stmt, $params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $rows;
    }

    public function execute(mysqli $conn, $sql, array $params = [])
    {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('فشل في تحضير الاستعلام: ' . $conn->error);
        }

        if ($params) {
            $this->bindParams($stmt, $params);
        }

        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    public function assignUuidIfPresent(mysqli $conn, $tableName, $id, $primaryKey = 'id')
    {
        $id = (int) $id;
        $tableName = (string) $tableName;
        $primaryKey = (string) $primaryKey;
        if ($id < 1 || !$this->safeIdentifier($tableName) || !$this->safeIdentifier($primaryKey)) {
            return null;
        }

        try {
            if (!$this->columnExists($conn, $tableName, $primaryKey) || !$this->columnExists($conn, $tableName, 'uuid')) {
                return null;
            }

            $quotedTable = $this->quoteIdentifier($tableName);
            $quotedPk = $this->quoteIdentifier($primaryKey);
            $row = $this->queryOne($conn, "SELECT uuid FROM {$quotedTable} WHERE {$quotedPk} = ? LIMIT 1", [$id]);
            if (!$row) {
                return null;
            }
            if (trim((string) ($row['uuid'] ?? '')) !== '') {
                return (string) $row['uuid'];
            }

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $uuid = $this->uuidV4();
                try {
                    $affected = $this->execute($conn, "
                        UPDATE {$quotedTable}
                        SET uuid = ?
                        WHERE {$quotedPk} = ?
                          AND (uuid IS NULL OR uuid = '')
                    ", [$uuid, $id]);
                    if ($affected > 0) {
                        return $uuid;
                    }
                } catch (mysqli_sql_exception $exception) {
                    if ((int) $exception->getCode() !== 1062) {
                        throw $exception;
                    }
                }
            }
        } catch (Throwable $exception) {
            error_log('UUID assignment skipped for ' . $tableName . ':' . $id . ' - ' . $exception->getMessage());
        }

        return null;
    }

    private function bindParams(mysqli_stmt $stmt, array $params)
    {
        $types = '';
        $refs = [];

        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $refs[$key] = $params[$key];
        }

        $bindValues = [$types];
        foreach ($refs as $key => $value) {
            $bindValues[] = &$refs[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }

    private function tableExists(mysqli $conn, $tableName)
    {
        $tableName = $conn->real_escape_string($tableName);
        $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");
        return $result && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, $tableName, $columnName)
    {
        $tableName = $conn->real_escape_string($tableName);
        $columnName = $conn->real_escape_string($columnName);
        $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        return $result && $result->num_rows > 0;
    }

    private function safeIdentifier($value)
    {
        return (bool) preg_match('/^[A-Za-z0-9_]+$/', (string) $value);
    }

    private function quoteIdentifier($value)
    {
        return '`' . str_replace('`', '``', (string) $value) . '`';
    }

    private function uuidV4()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
