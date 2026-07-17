<?php

require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/PosOrderSnapshotBuilder.php';

class PosCustomerSyncPayloadService
{
    private const CUSTOMER_FIELDS = [
        'id',
        'display_name',
        'primary_phone_id',
        'notes',
        'orders_count',
        'lifetime_paid',
        'last_order_at',
        'created_at',
        'updated_at',
        'isdeleted',
    ];

    private const PHONE_FIELDS = [
        'id',
        'customer_id',
        'phone_normalized',
        'phone_display',
        'is_primary',
        'label',
        'created_at',
        'updated_at',
        'isdeleted',
    ];

    private const ADDRESS_FIELDS = [
        'id',
        'customer_id',
        'address_text',
        'zone_id',
        'is_default',
        'created_at',
        'updated_at',
        'isdeleted',
    ];

    public function build(
        mysqli $conn,
        string $branchUuid,
        int $customerId,
        int $revision,
        array $options = []
    ): array {
        if (!SyncBranchIdentity::isUuid($branchUuid) || $customerId < 1 || $revision < 1) {
            throw new InvalidArgumentException('CUSTOMER_SYNC_IDENTITY_INVALID');
        }

        $customer = $this->fetchCustomer($conn, $customerId);
        if (!$customer) {
            throw new RuntimeException('CUSTOMER_SYNC_PARENT_NOT_FOUND');
        }

        $customerUuid = PosOrderSnapshotBuilder::deterministicUuid(
            $branchUuid,
            'pos_customers:' . $customerId
        );
        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'customer_bundle',
            'domain' => 'pos_customer',
            'branch_uuid' => $branchUuid,
            'source_system' => $this->sourceSystem($options['source_system'] ?? null),
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'customer_uuid' => $customerUuid,
            'customer_id' => $customerId,
            'sync_revision' => $revision,
            'customer' => $customer,
            'phones' => $this->fetchChildren($conn, 'pos_customer_phones', self::PHONE_FIELDS, $customerId),
            'addresses' => $this->tableExists($conn, 'pos_customer_addresses')
                ? $this->fetchChildren($conn, 'pos_customer_addresses', self::ADDRESS_FIELDS, $customerId)
                : [],
        ];
        $payload['payload_hash'] = $this->embeddedHash($payload);
        $this->assertValid($payload, $branchUuid);

        return $payload;
    }

    public function assertValid(array $payload, ?string $expectedBranchUuid = null, ?array $event = null): void
    {
        $allowedTopLevel = [
            'schema_version',
            'snapshot_type',
            'domain',
            'branch_uuid',
            'source_system',
            'captured_at_utc',
            'customer_uuid',
            'customer_id',
            'sync_revision',
            'customer',
            'phones',
            'addresses',
            'payload_hash',
        ];
        if (array_diff(array_keys($payload), $allowedTopLevel) !== []
            || (int) ($payload['schema_version'] ?? 0) !== 1
            || (string) ($payload['snapshot_type'] ?? '') !== 'customer_bundle'
            || (string) ($payload['domain'] ?? '') !== 'pos_customer'
        ) {
            throw new RuntimeException('CUSTOMER_SYNC_PAYLOAD_INVALID');
        }

        $branchUuid = trim((string) ($payload['branch_uuid'] ?? ''));
        $customerUuid = trim((string) ($payload['customer_uuid'] ?? ''));
        $customerId = (int) ($payload['customer_id'] ?? 0);
        $revision = (int) ($payload['sync_revision'] ?? 0);
        if (!SyncBranchIdentity::isUuid($branchUuid)
            || ($expectedBranchUuid !== null && !hash_equals(strtolower(trim($expectedBranchUuid)), strtolower($branchUuid)))
            || $customerId < 1
            || $revision < 1
            || !SyncBranchIdentity::isUuid($customerUuid)
            || !hash_equals(
                PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'pos_customers:' . $customerId),
                $customerUuid
            )
        ) {
            throw new RuntimeException('CUSTOMER_SYNC_IDENTITY_INVALID');
        }

        $embeddedHash = strtolower(trim((string) ($payload['payload_hash'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $embeddedHash)
            || !hash_equals($embeddedHash, $this->embeddedHash($payload))
        ) {
            throw new RuntimeException('CUSTOMER_SYNC_PAYLOAD_HASH_INVALID');
        }

        $customer = $payload['customer'] ?? null;
        if (!is_array($customer)
            || array_diff(array_keys($customer), self::CUSTOMER_FIELDS) !== []
            || (int) ($customer['id'] ?? 0) !== $customerId
            || trim((string) ($customer['display_name'] ?? '')) === ''
        ) {
            throw new RuntimeException('CUSTOMER_SYNC_PARENT_INVALID');
        }

        if (!is_array($payload['phones'] ?? null) || !is_array($payload['addresses'] ?? null)) {
            throw new RuntimeException('CUSTOMER_SYNC_CHILD_COLLECTION_INVALID');
        }

        $phoneIds = [];
        $phoneNumbers = [];
        $activePrimaryIds = [];
        foreach ($payload['phones'] as $phone) {
            if (!is_array($phone)
                || array_diff(array_keys($phone), self::PHONE_FIELDS) !== []
                || (int) ($phone['id'] ?? 0) < 1
                || (int) ($phone['customer_id'] ?? 0) !== $customerId
                || trim((string) ($phone['phone_normalized'] ?? '')) === ''
                || trim((string) ($phone['phone_display'] ?? '')) === ''
            ) {
                throw new RuntimeException('CUSTOMER_SYNC_PHONE_SCOPE_INVALID');
            }
            $phoneId = (int) $phone['id'];
            $normalized = trim((string) $phone['phone_normalized']);
            if (isset($phoneIds[$phoneId]) || isset($phoneNumbers[$normalized])) {
                throw new RuntimeException('CUSTOMER_SYNC_PHONE_IDENTITY_DUPLICATE');
            }
            $phoneIds[$phoneId] = true;
            $phoneNumbers[$normalized] = true;
            if ((int) ($phone['isdeleted'] ?? 0) === 0 && (int) ($phone['is_primary'] ?? 0) === 1) {
                $activePrimaryIds[$phoneId] = true;
            }
        }
        if (count($activePrimaryIds) > 1) {
            throw new RuntimeException('CUSTOMER_SYNC_PRIMARY_PHONE_INVALID');
        }
        $primaryPhoneId = (int) ($customer['primary_phone_id'] ?? 0);
        if ($primaryPhoneId > 0 && !isset($activePrimaryIds[$primaryPhoneId])) {
            throw new RuntimeException('CUSTOMER_SYNC_PRIMARY_PHONE_INVALID');
        }

        $addressIds = [];
        $activeDefaults = 0;
        foreach ($payload['addresses'] as $address) {
            if (!is_array($address)
                || array_diff(array_keys($address), self::ADDRESS_FIELDS) !== []
                || (int) ($address['id'] ?? 0) < 1
                || (int) ($address['customer_id'] ?? 0) !== $customerId
                || trim((string) ($address['address_text'] ?? '')) === ''
            ) {
                throw new RuntimeException('CUSTOMER_SYNC_ADDRESS_SCOPE_INVALID');
            }
            $addressId = (int) $address['id'];
            if (isset($addressIds[$addressId])) {
                throw new RuntimeException('CUSTOMER_SYNC_ADDRESS_IDENTITY_DUPLICATE');
            }
            $addressIds[$addressId] = true;
            if ((int) ($address['isdeleted'] ?? 0) === 0 && (int) ($address['is_default'] ?? 0) === 1) {
                $activeDefaults++;
            }
        }
        if ($activeDefaults > 1) {
            throw new RuntimeException('CUSTOMER_SYNC_DEFAULT_ADDRESS_INVALID');
        }

        if ($event !== null) {
            $aggregateUuid = trim((string) ($event['aggregate_uuid'] ?? ''));
            if ((string) ($event['aggregate_type'] ?? '') !== 'pos_customer'
                || !hash_equals($customerUuid, $aggregateUuid)
                || (int) ($event['aggregate_local_id'] ?? $customerId) !== $customerId
                || (int) ($event['event_version'] ?? 0) !== $revision
            ) {
                throw new RuntimeException('CUSTOMER_SYNC_EVENT_SCOPE_INVALID');
            }

            $outerHash = strtolower(trim((string) ($event['payload_hash'] ?? '')));
            if ($outerHash !== '' && (!preg_match('/^[a-f0-9]{64}$/', $outerHash)
                || !hash_equals($outerHash, hash('sha256', $this->encodeJson($payload))))) {
                throw new RuntimeException('CUSTOMER_SYNC_EVENT_HASH_INVALID');
            }
        }
    }

    private function fetchCustomer(mysqli $conn, int $customerId): ?array
    {
        $columns = implode(', ', array_map(static fn (string $field): string => '`' . $field . '`', self::CUSTOMER_FIELDS));
        $stmt = $conn->prepare("SELECT {$columns} FROM pos_customers WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchChildren(mysqli $conn, string $table, array $fields, int $customerId): array
    {
        $columns = implode(', ', array_map(static fn (string $field): string => '`' . $field . '`', $fields));
        $stmt = $conn->prepare("SELECT {$columns} FROM `{$table}` WHERE customer_id = ? ORDER BY id ASC");
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $rows = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function embeddedHash(array $payload): string
    {
        unset($payload['payload_hash']);

        return hash('sha256', $this->encodeJson($payload));
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('CUSTOMER_SYNC_JSON_ENCODE_FAILED');
        }

        return $json;
    }

    private function sourceSystem($value): string
    {
        $value = trim((string) $value);
        return $value === '' ? 'pos' : substr($value, 0, 40);
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result && $result->num_rows > 0;
    }
}
