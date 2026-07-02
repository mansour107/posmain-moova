<?php

require_once __DIR__ . '/PosCustomerService.php';
require_once __DIR__ . '/PosCustomerPhoneService.php';

class PosCustomerAnalyticsService
{
    private PosCustomerService $customerService;
    private PosCustomerPhoneService $phoneService;

    public function __construct(?PosCustomerService $customerService = null, ?PosCustomerPhoneService $phoneService = null)
    {
        $this->customerService = $customerService ?: new PosCustomerService();
        $this->phoneService = $phoneService ?: new PosCustomerPhoneService();
    }

    public function dashboard(mysqli $conn): array
    {
        if (!$this->customerService->tablesReady($conn)) {
            return [
                'total_customers' => 0,
                'new_this_month' => 0,
                'active_30d' => 0,
                'top_spender' => null,
            ];
        }

        $total = (int) ($conn->query('SELECT COUNT(*) AS c FROM pos_customers WHERE isdeleted = 0')->fetch_assoc()['c'] ?? 0);
        $newMonth = (int) ($conn->query("SELECT COUNT(*) AS c FROM pos_customers WHERE isdeleted = 0 AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetch_assoc()['c'] ?? 0);
        $active = (int) ($conn->query("SELECT COUNT(*) AS c FROM pos_customers WHERE isdeleted = 0 AND last_order_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['c'] ?? 0);
        $top = $conn->query('SELECT id, display_name, lifetime_paid FROM pos_customers WHERE isdeleted = 0 ORDER BY lifetime_paid DESC, orders_count DESC LIMIT 1')->fetch_assoc();

        return [
            'total_customers' => $total,
            'new_this_month' => $newMonth,
            'active_30d' => $active,
            'top_spender' => $top ? [
                'id' => (int) $top['id'],
                'display_name' => (string) $top['display_name'],
                'lifetime_paid' => (float) $top['lifetime_paid'],
            ] : null,
        ];
    }

    public function listCustomers(mysqli $conn, array $filters = []): array
    {
        if (!$this->customerService->tablesReady($conn)) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 25];
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;
        $q = trim((string) ($filters['q'] ?? ''));
        $sort = (string) ($filters['sort'] ?? 'lifetime_paid');
        $dir = strtoupper((string) ($filters['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $allowedSort = [
            'lifetime_paid' => 'c.lifetime_paid',
            'orders_count' => 'c.orders_count',
            'last_order_at' => 'c.last_order_at',
            'display_name' => 'c.display_name',
            'created_at' => 'c.created_at',
        ];
        $sortColumn = $allowedSort[$sort] ?? $allowedSort['lifetime_paid'];

        $where = ['c.isdeleted = 0'];
        $types = '';
        $params = [];

        if ($q !== '') {
            $normalized = $this->phoneService->normalizePhone($q);
            $like = '%' . $q . '%';
            if ($normalized !== '') {
                $where[] = '(c.display_name LIKE ? OR p.phone_normalized LIKE ? OR p.phone_display LIKE ?)';
                $phoneLike = $normalized . '%';
                $types .= 'sss';
                $params[] = $like;
                $params[] = $phoneLike;
                $params[] = $like;
            } else {
                $where[] = 'c.display_name LIKE ?';
                $types .= 's';
                $params[] = $like;
            }
        }

        $minSpend = (float) ($filters['min_spend'] ?? 0);
        if ($minSpend > 0) {
            $where[] = 'c.lifetime_paid >= ?';
            $types .= 'd';
            $params[] = $minSpend;
        }

        $minOrders = (int) ($filters['min_orders'] ?? 0);
        if ($minOrders > 0) {
            $where[] = 'c.orders_count >= ?';
            $types .= 'i';
            $params[] = $minOrders;
        }

        $lastOrderFrom = trim((string) ($filters['last_order_from'] ?? ''));
        if ($lastOrderFrom !== '') {
            $where[] = 'c.last_order_at >= ?';
            $types .= 's';
            $params[] = $lastOrderFrom . ' 00:00:00';
        }

        $lastOrderTo = trim((string) ($filters['last_order_to'] ?? ''));
        if ($lastOrderTo !== '') {
            $where[] = 'c.last_order_at <= ?';
            $types .= 's';
            $params[] = $lastOrderTo . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $join = $q !== '' ? 'LEFT JOIN pos_customer_phones p ON p.customer_id = c.id AND p.isdeleted = 0' : '';

        $countSql = "SELECT COUNT(DISTINCT c.id) AS total FROM pos_customers c {$join} WHERE {$whereSql}";
        $countStmt = $conn->prepare($countSql);
        if ($types !== '') {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();

        $sql = "
            SELECT DISTINCT
                c.id,
                c.display_name,
                c.orders_count,
                c.lifetime_paid,
                c.last_order_at,
                c.created_at,
                (
                    SELECT p2.phone_display
                    FROM pos_customer_phones p2
                    WHERE p2.customer_id = c.id AND p2.isdeleted = 0
                    ORDER BY p2.is_primary DESC, p2.id ASC
                    LIMIT 1
                ) AS primary_phone
            FROM pos_customers c
            {$join}
            WHERE {$whereSql}
            ORDER BY {$sortColumn} {$dir}, c.id DESC
            LIMIT ? OFFSET ?
        ";
        $listTypes = $types . 'ii';
        $listParams = array_merge($params, [$perPage, $offset]);
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($listTypes, ...$listParams);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id' => (int) $row['id'],
                'display_name' => (string) $row['display_name'],
                'primary_phone' => (string) ($row['primary_phone'] ?? ''),
                'orders_count' => (int) $row['orders_count'],
                'lifetime_paid' => (float) $row['lifetime_paid'],
                'last_order_at' => $row['last_order_at'],
                'created_at' => (string) $row['created_at'],
            ];
        }
        $stmt->close();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function customerOrders(mysqli $conn, int $customerId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        if ($customerId < 1 || !$this->columnExists($conn, 'order_fulfillment', 'pos_customer_id')) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $countStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM order_fulfillment f
            INNER JOIN ot_head o ON o.id = f.order_id AND o.isdeleted = 0
            WHERE f.pos_customer_id = ?
        ");
        $countStmt->bind_param('i', $customerId);
        $countStmt->execute();
        $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();

        $stmt = $conn->prepare("
            SELECT
                o.id AS order_id,
                o.pro_id,
                o.order_type,
                o.fat_net,
                o.paid_amount,
                o.payment_status,
                o.order_status,
                COALESCE(o.mdtime, o.crtime, o.pro_date) AS order_time,
                f.fulfillment_type,
                f.customer_phone,
                f.customer_address
            FROM order_fulfillment f
            INNER JOIN ot_head o ON o.id = f.order_id AND o.isdeleted = 0
            WHERE f.pos_customer_id = ?
            ORDER BY order_time DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param('iii', $customerId, $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'order_id' => (int) $row['order_id'],
                'pro_id' => (int) $row['pro_id'],
                'order_type' => (string) ($row['order_type'] ?? $row['fulfillment_type'] ?? ''),
                'fat_net' => (float) $row['fat_net'],
                'paid_amount' => (float) ($row['paid_amount'] ?? 0),
                'payment_status' => (string) $row['payment_status'],
                'order_status' => (string) $row['order_status'],
                'order_time' => (string) $row['order_time'],
            ];
        }
        $stmt->close();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS column_count
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) $row['column_count'] > 0;
    }
}
