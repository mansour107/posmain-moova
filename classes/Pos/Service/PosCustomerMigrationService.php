<?php

require_once __DIR__ . '/PosCustomerPhoneService.php';
require_once __DIR__ . '/PosCustomerService.php';

class PosCustomerMigrationService
{
    public function migrateFromDeliveryClientsIfNeeded(mysqli $conn): array
    {
        if (!$this->tableExists($conn, 'delivery_clients') || !$this->tableExists($conn, 'pos_customers')) {
            return ['migrated' => 0, 'skipped' => true];
        }

        $countResult = $conn->query('SELECT COUNT(*) AS c FROM pos_customers WHERE isdeleted = 0');
        $existing = $countResult ? (int) ($countResult->fetch_assoc()['c'] ?? 0) : 0;
        if ($existing > 0) {
            return ['migrated' => 0, 'skipped' => true, 'reason' => 'pos_customers_not_empty'];
        }

        $service = new PosCustomerService();
        $migrated = 0;
        $idMap = [];
        $result = $conn->query("SELECT id, client_name, phone, address FROM delivery_clients WHERE isdeleted = 0 ORDER BY id ASC");
        if (!$result) {
            return ['migrated' => 0, 'error' => $conn->error];
        }

        while ($row = $result->fetch_assoc()) {
            $phone = (string) ($row['phone'] ?? '');
            $name = trim((string) ($row['client_name'] ?? ''));
            $address = trim((string) ($row['address'] ?? ''));
            if ($phone === '' || $name === '') {
                continue;
            }

            try {
                $saved = $service->saveCustomer($conn, [
                    'display_name' => $name,
                    'phones' => [
                        ['phone' => $phone, 'is_primary' => true],
                    ],
                    'addresses' => $address !== '' ? [
                        ['address_text' => $address, 'is_default' => true],
                    ] : [],
                ]);
                $oldId = (int) ($row['id'] ?? 0);
                $newId = (int) ($saved['id'] ?? 0);
                if ($oldId > 0 && $newId > 0) {
                    $idMap[$oldId] = $newId;
                }
                $migrated++;
            } catch (Throwable $exception) {
                error_log('PosCustomerMigrationService row failed: ' . $exception->getMessage());
            }
        }

        if ($idMap && $this->columnExists($conn, 'order_fulfillment', 'pos_customer_id')) {
            foreach ($idMap as $oldId => $newId) {
                $stmt = $conn->prepare('UPDATE order_fulfillment SET pos_customer_id = ? WHERE delivery_client_id = ? AND (pos_customer_id IS NULL OR pos_customer_id = 0)');
                if ($stmt) {
                    $stmt->bind_param('ii', $newId, $oldId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        return ['migrated' => $migrated, 'mapped' => count($idMap)];
    }

    public function backfillOrderFulfillmentCustomers(mysqli $conn): array
    {
        if (!$this->tableExists($conn, 'order_fulfillment') || !$this->tableExists($conn, 'pos_customers')) {
            return ['updated' => 0, 'skipped' => true];
        }

        $updated = 0;

        if ($this->columnExists($conn, 'pos_customer_id') && $this->columnExists($conn, 'delivery_client_id')) {
            $conn->query("
                UPDATE order_fulfillment f
                INNER JOIN pos_customers c ON c.id = f.delivery_client_id AND c.isdeleted = 0
                SET f.pos_customer_id = f.delivery_client_id
                WHERE (f.pos_customer_id IS NULL OR f.pos_customer_id = 0)
                  AND f.delivery_client_id IS NOT NULL
                  AND f.delivery_client_id > 0
            ");
            $updated += (int) $conn->affected_rows;
        }

        if ($this->columnExists($conn, 'pos_customer_id') && $this->tableExists($conn, 'pos_customer_phones')) {
            $phoneService = new PosCustomerPhoneService();
            $result = $conn->query("
                SELECT f.order_id, f.customer_phone
                FROM order_fulfillment f
                WHERE (f.pos_customer_id IS NULL OR f.pos_customer_id = 0)
                  AND f.customer_phone IS NOT NULL
                  AND TRIM(f.customer_phone) <> ''
            ");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $normalized = $phoneService->normalizePhone((string) ($row['customer_phone'] ?? ''));
                    if ($normalized === '') {
                        continue;
                    }
                    $stmt = $conn->prepare("
                        SELECT p.customer_id
                        FROM pos_customer_phones p
                        INNER JOIN pos_customers c ON c.id = p.customer_id AND c.isdeleted = 0
                        WHERE p.phone_normalized = ?
                          AND p.isdeleted = 0
                        LIMIT 1
                    ");
                    $stmt->bind_param('s', $normalized);
                    $stmt->execute();
                    $match = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$match) {
                        continue;
                    }
                    $customerId = (int) ($match['customer_id'] ?? 0);
                    $orderId = (int) ($row['order_id'] ?? 0);
                    if ($customerId < 1 || $orderId < 1) {
                        continue;
                    }
                    $update = $conn->prepare('UPDATE order_fulfillment SET pos_customer_id = ? WHERE order_id = ? AND (pos_customer_id IS NULL OR pos_customer_id = 0)');
                    $update->bind_param('ii', $customerId, $orderId);
                    $update->execute();
                    if ($update->affected_rows > 0) {
                        $updated++;
                    }
                    $update->close();
                }
            }
        }

        return ['updated' => $updated];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS table_count
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) $row['table_count'] > 0;
    }

    private function columnExists(mysqli $conn, string $column): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS column_count
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'order_fulfillment'
              AND COLUMN_NAME = ?
        ");
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) $row['column_count'] > 0;
    }
}
