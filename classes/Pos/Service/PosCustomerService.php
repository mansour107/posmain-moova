<?php

require_once __DIR__ . '/PosCustomerPhoneService.php';
require_once __DIR__ . '/../../Sync/OperationalSyncEventService.php';

class PosCustomerService
{
    private PosCustomerPhoneService $phoneService;
    private OperationalSyncEventService $syncEventService;

    public function __construct(
        ?PosCustomerPhoneService $phoneService = null,
        ?OperationalSyncEventService $syncEventService = null
    )
    {
        $this->phoneService = $phoneService ?: new PosCustomerPhoneService();
        $this->syncEventService = $syncEventService ?: new OperationalSyncEventService();
    }

    public function tablesReady(mysqli $conn): bool
    {
        return $this->tableExists($conn, 'pos_customers')
            && $this->tableExists($conn, 'pos_customer_phones');
    }

    public function searchByPhone(mysqli $conn, string $phone, int $limit = 5): array
    {
        if (!$this->tablesReady($conn)) {
            return ['exact' => null, 'suggestions' => []];
        }

        $normalized = $this->phoneService->normalizePhone($phone);
        if ($normalized === '') {
            return ['exact' => null, 'suggestions' => []];
        }

        $exact = $this->findByNormalizedPhone($conn, $normalized);
        if ($exact) {
            return [
                'exact' => $this->formatCustomerSummary($conn, $exact),
                'suggestions' => [],
            ];
        }

        $like = $normalized . '%';
        $limit = max(1, min(10, $limit));
        $stmt = $conn->prepare("
            SELECT c.id, c.display_name, p.phone_normalized, p.phone_display, p.is_primary
            FROM pos_customer_phones p
            INNER JOIN pos_customers c ON c.id = p.customer_id AND c.isdeleted = 0
            WHERE p.isdeleted = 0
              AND p.phone_normalized LIKE ?
            ORDER BY p.is_primary DESC, c.updated_at DESC
            LIMIT ?
        ");
        $stmt->bind_param('si', $like, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $suggestions = [];
        while ($row = $result->fetch_assoc()) {
            $suggestions[] = [
                'id' => (int) $row['id'],
                'display_name' => (string) $row['display_name'],
                'phone' => (string) $row['phone_display'],
                'phone_normalized' => (string) $row['phone_normalized'],
            ];
        }
        $stmt->close();

        return ['exact' => null, 'suggestions' => $suggestions];
    }

    public function findByNormalizedPhone(mysqli $conn, string $normalizedPhone): ?array
    {
        $stmt = $conn->prepare("
            SELECT c.*
            FROM pos_customer_phones p
            INNER JOIN pos_customers c ON c.id = p.customer_id AND c.isdeleted = 0
            WHERE p.isdeleted = 0
              AND p.phone_normalized = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $normalizedPhone);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function getProfile(mysqli $conn, int $customerId, bool $includeStats = true): ?array
    {
        if ($customerId < 1 || !$this->tablesReady($conn)) {
            return null;
        }

        $stmt = $conn->prepare('SELECT * FROM pos_customers WHERE id = ? AND isdeleted = 0 LIMIT 1');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$customer) {
            return null;
        }

        $phones = $this->loadPhones($conn, $customerId);
        $addresses = $this->loadAddresses($conn, $customerId);
        $primaryPhone = '';
        foreach ($phones as $phone) {
            if (!empty($phone['is_primary'])) {
                $primaryPhone = (string) ($phone['phone'] ?? $phone['phone_display'] ?? '');
                break;
            }
        }
        if ($primaryPhone === '' && $phones) {
            $primaryPhone = (string) ($phones[0]['phone'] ?? $phones[0]['phone_display'] ?? '');
        }

        $profile = [
            'id' => (int) $customer['id'],
            'display_name' => (string) $customer['display_name'],
            'notes' => (string) ($customer['notes'] ?? ''),
            'primary_phone' => $primaryPhone,
            'phones' => $phones,
            'addresses' => $addresses,
            'orders_count' => (int) ($customer['orders_count'] ?? 0),
            'lifetime_paid' => (float) ($customer['lifetime_paid'] ?? 0),
            'last_order_at' => $customer['last_order_at'] ?? null,
        ];

        if ($includeStats) {
            $profile['stats'] = $this->quickStats($conn, $customerId, $customer);
        }

        return $profile;
    }

    public function quickStats(mysqli $conn, int $customerId, ?array $customerRow = null): array
    {
        if ($customerRow === null) {
            $stmt = $conn->prepare('SELECT orders_count, lifetime_paid, last_order_at FROM pos_customers WHERE id = ? AND isdeleted = 0 LIMIT 1');
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $customerRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        $stats = [
            'orders_count' => (int) ($customerRow['orders_count'] ?? 0),
            'lifetime_paid' => (float) ($customerRow['lifetime_paid'] ?? 0),
            'last_order_at' => $customerRow['last_order_at'] ?? null,
        ];

        if ($stats['orders_count'] === 0 && $stats['lifetime_paid'] <= 0) {
            require_once __DIR__ . '/PosCustomerOrderSideEffects.php';
            $live = (new PosCustomerOrderSideEffects())->liveStatsFromFulfillment($conn, $customerId);
            if ($live !== null) {
                $stats = [
                    'orders_count' => (int) $live['orders_count'],
                    'lifetime_paid' => (float) $live['lifetime_paid'],
                    'last_order_at' => $live['last_order_at'] ?? null,
                    'linked_orders' => (int) ($live['linked_orders'] ?? 0),
                ];
            }
        }

        return $stats;
    }

    public function applyRollupDelta(
        mysqli $conn,
        int $customerId,
        float $paidDelta,
        bool $incrementOrderCount,
        array $options = []
    ): void
    {
        if ($customerId < 1 || !$this->tablesReady($conn)) {
            return;
        }

        if ($paidDelta > 0 && $incrementOrderCount) {
            $stmt = $conn->prepare("
                UPDATE pos_customers
                SET orders_count = orders_count + 1,
                    lifetime_paid = lifetime_paid + ?,
                    last_order_at = NOW(),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND isdeleted = 0
            ");
            $stmt->bind_param('di', $paidDelta, $customerId);
        } elseif ($paidDelta > 0) {
            $stmt = $conn->prepare("
                UPDATE pos_customers
                SET lifetime_paid = lifetime_paid + ?,
                    last_order_at = NOW(),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND isdeleted = 0
            ");
            $stmt->bind_param('di', $paidDelta, $customerId);
        } elseif ($incrementOrderCount) {
            $stmt = $conn->prepare("
                UPDATE pos_customers
                SET orders_count = orders_count + 1,
                    last_order_at = NOW(),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND isdeleted = 0
            ");
            $stmt->bind_param('i', $customerId);
        } else {
            return;
        }

        $ownsTransaction = $this->ownsTransaction($options);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $stmt->execute();
            $stmt->close();
            if (empty($options['defer_sync'])) {
                $this->recordSyncSnapshot($conn, $customerId, $options + [
                    'event_type' => 'customer.rollup_updated',
                    'source_system' => 'pos_customer_rollup',
                ]);
            }
            if ($ownsTransaction) {
                $conn->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function saveCustomer(mysqli $conn, array $payload, array $options = []): array
    {
        if (!$this->tablesReady($conn)) {
            throw new RuntimeException('POS_CUSTOMER_TABLES_MISSING');
        }

        $customerId = (int) ($payload['id'] ?? $payload['customer_id'] ?? 0);
        $isNew = $customerId < 1;
        $displayName = trim((string) ($payload['display_name'] ?? $payload['name'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));

        if ($displayName === '') {
            throw new InvalidArgumentException('CUSTOMER_NAME_REQUIRED');
        }

        $ownsTransaction = $this->ownsTransaction($options);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            if ($customerId > 0) {
                $stmt = $conn->prepare('UPDATE pos_customers SET display_name = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND isdeleted = 0');
                $stmt->bind_param('ssi', $displayName, $notes, $customerId);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO pos_customers (display_name, notes) VALUES (?, ?)');
                $stmt->bind_param('ss', $displayName, $notes);
                $stmt->execute();
                $customerId = (int) $conn->insert_id;
                $stmt->close();
            }

            if (!empty($payload['phones']) && is_array($payload['phones'])) {
                $this->syncPhones($conn, $customerId, $payload['phones']);
            } elseif (!empty($payload['phone'])) {
                $this->syncPhones($conn, $customerId, [
                    ['phone' => (string) $payload['phone'], 'is_primary' => true],
                ]);
            }

            if (!empty($payload['addresses']) && is_array($payload['addresses'])) {
                $this->syncAddresses($conn, $customerId, $payload['addresses']);
            } elseif (!empty($payload['address_text']) || !empty($payload['address'])) {
                $this->syncAddresses($conn, $customerId, [[
                    'address_text' => (string) ($payload['address_text'] ?? $payload['address'] ?? ''),
                    'zone_id' => (int) ($payload['zone_id'] ?? 0) ?: null,
                    'is_default' => true,
                ]]);
            }

            $this->refreshPrimaryPhoneId($conn, $customerId);
            $this->recordSyncSnapshot($conn, $customerId, $options + [
                'event_type' => $isNew ? 'customer.created' : 'customer.saved',
                'source_system' => 'pos_customer',
            ]);

            $profile = $this->getProfile($conn, $customerId, true);
            if (!$profile) {
                throw new RuntimeException('CUSTOMER_SAVE_VERIFY_FAILED');
            }

            if ($ownsTransaction) {
                $conn->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }

        return $profile;
    }

    public function upsertForDelivery(
        mysqli $conn,
        string $phone,
        string $name,
        string $address,
        ?int $zoneId = null,
        array $options = []
    ): array
    {
        $normalized = $this->phoneService->normalizePhone($phone);
        if (!$this->phoneService->isValidPhone($phone)) {
            throw new InvalidArgumentException('CUSTOMER_PHONE_INVALID');
        }
        if (trim($name) === '') {
            throw new InvalidArgumentException('CUSTOMER_NAME_REQUIRED');
        }

        $existing = $this->findByNormalizedPhone($conn, $normalized);
        if ($existing) {
            return $this->saveCustomer($conn, [
                'id' => (int) $existing['id'],
                'display_name' => $name,
                'phones' => [['phone' => $phone, 'is_primary' => true]],
                'addresses' => trim($address) !== '' ? [[
                    'address_text' => $address,
                    'zone_id' => $zoneId,
                    'is_default' => true,
                ]] : [],
            ], $options + ['source_system' => 'pos_delivery_customer']);
        }

        return $this->saveCustomer($conn, [
            'display_name' => $name,
            'phone' => $phone,
            'addresses' => trim($address) !== '' ? [[
                'address_text' => $address,
                'zone_id' => $zoneId,
                'is_default' => true,
            ]] : [],
        ], $options + ['source_system' => 'pos_delivery_customer']);
    }

    public function recordOrderPaid(mysqli $conn, int $customerId, float $paidAmount, array $options = []): void
    {
        if ($customerId < 1 || $paidAmount <= 0 || !$this->tablesReady($conn)) {
            return;
        }

        $ownsTransaction = $this->ownsTransaction($options);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $stmt = $conn->prepare("
                UPDATE pos_customers
                SET orders_count = orders_count + 1,
                    lifetime_paid = lifetime_paid + ?,
                    last_order_at = NOW(),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND isdeleted = 0
            ");
            $stmt->bind_param('di', $paidAmount, $customerId);
            $stmt->execute();
            $stmt->close();
            $this->recordSyncSnapshot($conn, $customerId, $options + [
                'event_type' => 'customer.order_paid',
                'source_system' => 'pos_customer_rollup',
            ]);
            if ($ownsTransaction) {
                $conn->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function mergeCustomers(mysqli $conn, int $sourceId, int $targetId, array $options = []): array
    {
        if (!$this->tablesReady($conn)) {
            throw new RuntimeException('POS_CUSTOMER_TABLES_MISSING');
        }
        if ($sourceId < 1 || $targetId < 1 || $sourceId === $targetId) {
            throw new InvalidArgumentException('INVALID_MERGE_IDS');
        }

        $source = $this->getProfile($conn, $sourceId, false);
        $target = $this->getProfile($conn, $targetId, false);
        if (!$source || !$target) {
            throw new InvalidArgumentException('CUSTOMER_NOT_FOUND');
        }

        $ownsTransaction = $this->ownsTransaction($options);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $stmt = $conn->prepare('SELECT id, phone_normalized FROM pos_customer_phones WHERE customer_id = ? AND isdeleted = 0');
            $stmt->bind_param('i', $sourceId);
            $stmt->execute();
            $sourcePhones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($sourcePhones as $phoneRow) {
                $phoneId = (int) $phoneRow['id'];
                $normalized = (string) $phoneRow['phone_normalized'];
                $owner = $this->findPhoneOwner($conn, $normalized);
                if ($owner && (int) $owner['customer_id'] === $targetId) {
                    $stmt = $conn->prepare('UPDATE pos_customer_phones SET isdeleted = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                    $stmt->bind_param('i', $phoneId);
                    $stmt->execute();
                    $stmt->close();
                    continue;
                }
                if ($owner && (int) $owner['customer_id'] !== $sourceId) {
                    $stmt = $conn->prepare('UPDATE pos_customer_phones SET isdeleted = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                    $stmt->bind_param('i', $phoneId);
                    $stmt->execute();
                    $stmt->close();
                    continue;
                }

                $stmt = $conn->prepare('UPDATE pos_customer_phones SET customer_id = ?, is_primary = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $stmt->bind_param('ii', $targetId, $phoneId);
                $stmt->execute();
                $stmt->close();
            }

            if ($this->tableExists($conn, 'pos_customer_addresses')) {
                $stmt = $conn->prepare('UPDATE pos_customer_addresses SET customer_id = ?, is_default = 0, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND isdeleted = 0');
                $stmt->bind_param('ii', $targetId, $sourceId);
                $stmt->execute();
                $stmt->close();
            }

            if ($this->columnExists($conn, 'order_fulfillment', 'pos_customer_id')) {
                $stmt = $conn->prepare('UPDATE order_fulfillment SET pos_customer_id = ? WHERE pos_customer_id = ?');
                $stmt->bind_param('ii', $targetId, $sourceId);
                $stmt->execute();
                $stmt->close();
            }

            $sourceNotes = trim((string) ($source['notes'] ?? ''));
            $targetNotes = trim((string) ($target['notes'] ?? ''));
            $mergedNotes = $targetNotes;
            if ($sourceNotes !== '') {
                $mergedNotes = $mergedNotes !== '' ? $mergedNotes . "\n---\n" . $sourceNotes : $sourceNotes;
            }

            $sourceOrders = (int) ($source['orders_count'] ?? 0);
            $sourcePaid = (float) ($source['lifetime_paid'] ?? 0);
            $sourceLast = $source['last_order_at'] ?? null;

            $stmt = $conn->prepare('
                UPDATE pos_customers
                SET notes = ?,
                    orders_count = orders_count + ?,
                    lifetime_paid = lifetime_paid + ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND isdeleted = 0
            ');
            $stmt->bind_param('sidi', $mergedNotes, $sourceOrders, $sourcePaid, $targetId);
            $stmt->execute();
            $stmt->close();

            if ($sourceLast) {
                $stmt = $conn->prepare('
                    UPDATE pos_customers
                    SET last_order_at = ?
                    WHERE id = ?
                      AND isdeleted = 0
                      AND (last_order_at IS NULL OR last_order_at < ?)
                ');
                $stmt->bind_param('sis', $sourceLast, $targetId, $sourceLast);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare('UPDATE pos_customers SET primary_phone_id = NULL, isdeleted = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->bind_param('i', $sourceId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('UPDATE pos_customer_phones SET isdeleted = 1, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ?');
            $stmt->bind_param('i', $sourceId);
            $stmt->execute();
            $stmt->close();

            if ($this->tableExists($conn, 'pos_customer_addresses')) {
                $stmt = $conn->prepare('UPDATE pos_customer_addresses SET isdeleted = 1, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ?');
                $stmt->bind_param('i', $sourceId);
                $stmt->execute();
                $stmt->close();
            }

            $this->refreshPrimaryPhoneId($conn, $targetId);
            $this->recordSyncSnapshot($conn, $targetId, $options + [
                'event_type' => 'customer.merged_target',
                'source_system' => 'pos_customer_merge',
            ]);
            $this->recordSyncSnapshot($conn, $sourceId, $options + [
                'event_type' => 'customer.merged_source',
                'source_system' => 'pos_customer_merge',
            ]);
            if ($ownsTransaction) {
                $conn->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }

        $profile = $this->getProfile($conn, $targetId, true);
        if (!$profile) {
            throw new RuntimeException('MERGE_VERIFY_FAILED');
        }

        return $profile;
    }

    public function softDeleteCustomer(mysqli $conn, int $customerId, array $options = []): void
    {
        if ($customerId < 1 || !$this->tablesReady($conn)) {
            throw new InvalidArgumentException('CUSTOMER_ID_REQUIRED');
        }

        $profile = $this->getProfile($conn, $customerId, false);
        if (!$profile) {
            throw new InvalidArgumentException('CUSTOMER_NOT_FOUND');
        }

        $ownsTransaction = $this->ownsTransaction($options);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $stmt = $conn->prepare('UPDATE pos_customers SET primary_phone_id = NULL, isdeleted = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('UPDATE pos_customer_phones SET isdeleted = 1, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ?');
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $stmt->close();

            if ($this->tableExists($conn, 'pos_customer_addresses')) {
                $stmt = $conn->prepare('UPDATE pos_customer_addresses SET isdeleted = 1, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ?');
                $stmt->bind_param('i', $customerId);
                $stmt->execute();
                $stmt->close();
            }

            $this->recordSyncSnapshot($conn, $customerId, $options + [
                'event_type' => 'customer.deleted',
                'source_system' => 'pos_customer',
            ]);
            if ($ownsTransaction) {
                $conn->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
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

    private function syncPhones(mysqli $conn, int $customerId, array $phones): void
    {
        $primarySet = false;
        foreach ($phones as $phoneRow) {
            if (!is_array($phoneRow)) {
                continue;
            }
            $rawPhone = (string) ($phoneRow['phone'] ?? $phoneRow['phone_display'] ?? '');
            $normalized = $this->phoneService->normalizePhone($rawPhone);
            if (!$this->phoneService->isValidPhone($rawPhone)) {
                continue;
            }

            $existingOwner = $this->findPhoneOwner($conn, $normalized);
            if ($existingOwner && (int) $existingOwner['customer_id'] !== $customerId) {
                throw new InvalidArgumentException('PHONE_ALREADY_USED');
            }

            $display = $this->phoneService->displayPhone($rawPhone);
            $label = trim((string) ($phoneRow['label'] ?? ''));
            $isPrimary = !empty($phoneRow['is_primary']) || (!$primarySet && count($phones) === 1);
            if ($isPrimary) {
                $primarySet = true;
            }
            $isPrimaryInt = $isPrimary ? 1 : 0;

            if ($existingOwner) {
                $phoneId = (int) $existingOwner['id'];
                $stmt = $conn->prepare('UPDATE pos_customer_phones SET phone_display = ?, label = ?, is_primary = ?, isdeleted = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $stmt->bind_param('ssii', $display, $label, $isPrimaryInt, $phoneId);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO pos_customer_phones (customer_id, phone_normalized, phone_display, is_primary, label) VALUES (?, ?, ?, ?, ?)');
                $stmt->bind_param('issis', $customerId, $normalized, $display, $isPrimaryInt, $label);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($primarySet) {
            $stmt = $conn->prepare('UPDATE pos_customer_phones SET is_primary = 0 WHERE customer_id = ? AND isdeleted = 0');
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $stmt->close();

            $primaryPhone = null;
            foreach ($phones as $phoneRow) {
                if (!empty($phoneRow['is_primary'])) {
                    $primaryPhone = $this->phoneService->normalizePhone((string) ($phoneRow['phone'] ?? ''));
                    break;
                }
            }
            if ($primaryPhone === null && $phones) {
                $primaryPhone = $this->phoneService->normalizePhone((string) ($phones[0]['phone'] ?? ''));
            }
            if ($primaryPhone) {
                $stmt = $conn->prepare('UPDATE pos_customer_phones SET is_primary = 1 WHERE customer_id = ? AND phone_normalized = ? AND isdeleted = 0');
                $stmt->bind_param('is', $customerId, $primaryPhone);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    public function setPrimaryPhone(mysqli $conn, int $customerId, int $phoneId, array $options = []): void
    {
        $ownsTransaction = $this->ownsTransaction($options);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $stmt = $conn->prepare('UPDATE pos_customer_phones SET is_primary = 0 WHERE customer_id = ? AND isdeleted = 0');
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('UPDATE pos_customer_phones SET is_primary = 1 WHERE id = ? AND customer_id = ? AND isdeleted = 0');
            $stmt->bind_param('ii', $phoneId, $customerId);
            $stmt->execute();
            $stmt->close();

            $this->refreshPrimaryPhoneId($conn, $customerId);
            $this->recordSyncSnapshot($conn, $customerId, $options + [
                'event_type' => 'customer.primary_phone_changed',
                'source_system' => 'pos_customer',
            ]);
            if ($ownsTransaction) {
                $conn->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function recordSyncSnapshot(mysqli $conn, int $customerId, array $options = []): ?array
    {
        return $this->syncEventService->recordCustomerSnapshot($conn, $customerId, $options);
    }

    private function syncAddresses(mysqli $conn, int $customerId, array $addresses): void
    {
        if (!$this->tableExists($conn, 'pos_customer_addresses')) {
            return;
        }

        $hasDefault = false;
        foreach ($addresses as $addressRow) {
            if (!is_array($addressRow)) {
                continue;
            }
            $text = trim((string) ($addressRow['address_text'] ?? $addressRow['address'] ?? ''));
            if ($text === '') {
                continue;
            }
            $zoneId = (int) ($addressRow['zone_id'] ?? 0);
            $zoneParam = $zoneId > 0 ? $zoneId : null;
            $isDefault = !empty($addressRow['is_default']);
            if ($isDefault) {
                $hasDefault = true;
            }
            $isDefaultInt = $isDefault ? 1 : 0;
            $addressId = (int) ($addressRow['id'] ?? 0);

            if ($addressId > 0) {
                $stmt = $conn->prepare('UPDATE pos_customer_addresses SET address_text = ?, zone_id = ?, is_default = ?, isdeleted = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND customer_id = ?');
                $stmt->bind_param('siiii', $text, $zoneParam, $isDefaultInt, $addressId, $customerId);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare('INSERT INTO pos_customer_addresses (customer_id, address_text, zone_id, is_default) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('isii', $customerId, $text, $zoneParam, $isDefaultInt);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($hasDefault) {
            $stmt = $conn->prepare('UPDATE pos_customer_addresses SET is_default = 0 WHERE customer_id = ? AND isdeleted = 0');
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $stmt->close();

            foreach ($addresses as $addressRow) {
                if (!empty($addressRow['is_default']) && !empty($addressRow['id'])) {
                    $addressId = (int) $addressRow['id'];
                    $stmt = $conn->prepare('UPDATE pos_customer_addresses SET is_default = 1 WHERE id = ? AND customer_id = ?');
                    $stmt->bind_param('ii', $addressId, $customerId);
                    $stmt->execute();
                    $stmt->close();
                    break;
                }
            }
        }
    }

    private function refreshPrimaryPhoneId(mysqli $conn, int $customerId): void
    {
        $stmt = $conn->prepare('SELECT id FROM pos_customer_phones WHERE customer_id = ? AND isdeleted = 0 ORDER BY is_primary DESC, id ASC LIMIT 1');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $phoneId = (int) $row['id'];
            $stmt = $conn->prepare('UPDATE pos_customer_phones SET is_primary = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE customer_id = ? AND isdeleted = 0');
            $stmt->bind_param('ii', $phoneId, $customerId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('UPDATE pos_customers SET primary_phone_id = ? WHERE id = ?');
            $stmt->bind_param('ii', $phoneId, $customerId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $stmt = $conn->prepare('UPDATE pos_customers SET primary_phone_id = NULL WHERE id = ?');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $stmt->close();
    }

    private function findPhoneOwner(mysqli $conn, string $normalized): ?array
    {
        $stmt = $conn->prepare('SELECT id, customer_id FROM pos_customer_phones WHERE phone_normalized = ? AND isdeleted = 0 LIMIT 1');
        $stmt->bind_param('s', $normalized);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function loadPhones(mysqli $conn, int $customerId): array
    {
        $stmt = $conn->prepare('SELECT id, phone_normalized, phone_display, is_primary, label FROM pos_customer_phones WHERE customer_id = ? AND isdeleted = 0 ORDER BY is_primary DESC, id ASC');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $phones = [];
        while ($row = $result->fetch_assoc()) {
            $phones[] = [
                'id' => (int) $row['id'],
                'phone' => (string) $row['phone_display'],
                'phone_normalized' => (string) $row['phone_normalized'],
                'is_primary' => (int) $row['is_primary'] === 1,
                'label' => (string) ($row['label'] ?? ''),
            ];
        }
        $stmt->close();

        return $phones;
    }

    private function loadAddresses(mysqli $conn, int $customerId): array
    {
        if (!$this->tableExists($conn, 'pos_customer_addresses')) {
            return [];
        }

        $stmt = $conn->prepare('SELECT id, address_text, zone_id, is_default FROM pos_customer_addresses WHERE customer_id = ? AND isdeleted = 0 ORDER BY is_default DESC, id ASC');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $addresses = [];
        while ($row = $result->fetch_assoc()) {
            $addresses[] = [
                'id' => (int) $row['id'],
                'address_text' => (string) $row['address_text'],
                'zone_id' => isset($row['zone_id']) ? (int) $row['zone_id'] : null,
                'is_default' => (int) $row['is_default'] === 1,
            ];
        }
        $stmt->close();

        return $addresses;
    }

    private function formatCustomerSummary(mysqli $conn, array $customerRow): array
    {
        $customerId = (int) $customerRow['id'];
        $profile = $this->getProfile($conn, $customerId, true);

        return $profile ?: [
            'id' => $customerId,
            'display_name' => (string) ($customerRow['display_name'] ?? ''),
            'stats' => $this->quickStats($conn, $customerId, $customerRow),
        ];
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

    private function ownsTransaction(array $options): bool
    {
        return empty($options['in_transaction']) && empty($options['transaction_started']);
    }
}
